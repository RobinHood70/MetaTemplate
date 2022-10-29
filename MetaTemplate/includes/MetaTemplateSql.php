<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * [Description MetaTemplateData]
 */
class MetaTemplateSql
{
    const SET_TABLE = 'mtSaveSet';
    const DATA_TABLE = 'mtSaveData';

    /** @var MetaTemplateSql */
    private static $instance;

    /** @var IDatabase */
    private $dbRead;

    /** @var IDatabase */
    private $dbWrite;

    /**
     * A list of all the pages purged during this session to avoid looping.
     *
     * @var array
     */
    private static $pagesPurged = [];

    private function __construct()
    {
        $dbWriteConst = defined('DB_PRIMARY') ? 'DB_PRIMARY' : 'DB_MASTER';
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $this->dbRead = $lb->getConnectionRef(constant($dbWriteConst));

        // We get dbWrite lazily since writing will often be unnecessary.
        $this->dbWrite = $lb->getLazyConnectionRef(constant($dbWriteConst));
    }

    /**
     * getInstance
     *
     * @return MetaTemplateSql
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function deleteVariables(Title $title)
    {
        $pageId = $title->getArticleID();

        // Assumes cascading is in effect to delete DATA_TABLE rows.
        $this->dbWrite->delete(self::SET_TABLE, ['pageId' => $pageId]);
        self::$pagesPurged[$pageId] = true;
        $this->recursiveInvalidateCache($title);
    }

    public function insertData($setId, MetaTemplateSet $newSet)
    {
        $data = [];
        foreach ($newSet->getVariables() as $key => $var) {
            $data[] = [
                'setId' => $setId,
                'varName' => $key,
                'varValue' => $var->getValue(),
                'parsed' => $var->getParsed()
            ];
        }

        $this->dbWrite->insert(self::DATA_TABLE, $data);
    }

    /**
     * loadExisting
     *
     * @param mixed $pageId
     *
     * @return MetaTemplateSetCollection
     */
    public function loadPageVariables($pageId)
    {
        // Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
        // data is tracked with $deleteIds.

        // logFunctionText("($pageId)");
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $conds = ['pageId' => $pageId];
        $fields = [
            self::SET_TABLE . '.setId',
            'revId',
            'setName',
            'varName',
            'varValue',
            'parsed',
        ];
        $options = ['ORDER BY' => 'revId'];
        $joinConds = [self::DATA_TABLE => ['LEFT JOIN', [self::DATA_TABLE . '.setId=' . self::SET_TABLE . '.setId']]];
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
        $row = $this->dbRead->fetchRow($result);
        if (!$row) {
            return null;
        }

        $retval = new MetaTemplateSetCollection($pageId, $row['revId']);
        while ($row) {
            $set =  $retval->getOrCreateSet($row['setId'], $row['setName']);
            $set->addVariable($row['varName'], $row['varValue'], $row['parsed']);
            $row = $this->dbRead->fetchRow($result);
        }

        return $retval;
    }

    /**
     * loadTableVariables
     *
     * @param mixed $pageId
     * @param mixed $revId
     * @param string $setName
     * @param array $varNames
     *
     * @return ?MetaTemplateVariable[]
     */
    public function loadTableVariables($pageId, string $setName = '', $varNames = []): array
    {
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $conds = [
            'setName' => $setName,
            'pageId' => $pageId
        ];

        if (count($varNames)) {
            $conds['varName'] = $varNames;
        }

        $fields = [
            'varName',
            'varValue',
            'parsed'
        ];

        $options = ['ORDER BY' => self::SET_TABLE . "revId ASC"];

        // Transactions should make sure this never happens, but in the event that we got more than one rev_id back,
        // ensure that we start with the lowest first, so data is overridden by the most recent values once we get
        // there, but lower values will exist if the write is incomplete.
        $joinConds = [
            self::DATA_TABLE => [
                'LEFT JOIN',
                [self::DATA_TABLE . '.setId=' . self::SET_TABLE . '.setId']
            ]
        ];

        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
        if (!$result || !$result->numRows()) {
            return null;
        }

        $retval = [];
        $row = $result->fetchRow();
        while ($row) {
            // Because the results are sorted by revId, any duplicate variables caused by an update in mid-select
            // will overwrite the older values.
            $retval[$row['varName']] = new MetaTemplateVariable($row['varValue'], $row['parsed']);
            $row = $result->fetchRow();
        }

        return $retval;
    }

    public function saveVariables(Title $title, ?MetaTemplateSetCollection $vars = null)
    {
        // This algorithm is based on the assumption that data is rarely changed, therefore:
        // * It's best to read the existing DB data before making any DB updates/inserts.
        // * Chances are that we're going to need to read all the data for this save set, so best to read it all at
        //   once instead of individually or by set.
        // * It's best to use the read-only DB until we know we need to write.

        if (is_null($vars) || $vars->isEmpty()) {
            $this->deleteVariables($title);
        } else if ($vars->getRevId() === -1) {
            // The above check will only be satisfied on Template-space pages that use #save.
            $this->recursiveInvalidateCache($title);
        } else {
            // We run saveVariable even if $vars is empty, since that could mean that all #saves have been removed from the page.
            $pageId = $title->getArticleID();

            // Whether or not the data changed, the page has been evaluated, so add it to the list.
            self::$pagesPurged[$pageId] = true;
            $oldData = $this->loadPageVariables($pageId);
            $upserts = new MetaTemplateUpserts($oldData, $vars);
            if ($upserts->getTotal() > 0) {
                $this->saveUpserts($upserts);
                $this->recursiveInvalidateCache($title);
            }
        }
    }

    /**
     * tablesExist
     *
     * @return bool
     */
    public function tablesExist()
    {
        return
            $this->dbRead->tableExists(self::SET_TABLE) &&
            $this->dbRead->tableExists(self::DATA_TABLE);
    }

    private function saveUpserts(MetaTemplateUpserts $upserts)
    {
        $deletes = $upserts->getDeletes();
        // writeFile('  Deletes: ', count($deletes));
        if (count($deletes)) {
            // Assumes cascading is in effect, so doesn't delete DATA_TABLE entries.
            $this->dbWrite->delete(self::SET_TABLE, ['setId' => $deletes]);
        }

        $pageId = $upserts->getPageId();
        $newRevId = $upserts->getNewRevId();
        // writeFile('  Inserts: ', count($inserts));
        foreach ($upserts->getInserts() as $newSet) {
            $this->dbWrite->insert(self::SET_TABLE, [
                'setName' => $newSet->getSetName(),
                'pageId' => $pageId,
                'revId' => $newRevId
            ]);
            $setId = $this->dbWrite->insertId();
            $this->insertData($setId, $newSet);
        }

        $updates = $upserts->getUpdates();
        // writeFile('  Updates: ', count($updates));
        if (count($updates)) {
            foreach ($updates as $setId => $setData) {
                /**
                 * @var MetaTemplateSet $oldSet
                 * @var MetaTemplateSet $newSet
                 */
                list($oldSet, $newSet) = $setData;
                $this->updateSetData($setId, $oldSet, $newSet);
            }

            if (
                $upserts->getOldRevId() < $newRevId
            ) {
                $this->dbWrite->update(
                    self::SET_TABLE,
                    ['setId' => $setId],
                    ['revId' => $newRevId]
                );
            }
        }
    }

    private function recursiveInvalidateCache(Title $title)
    {
        // Note: this is recursive only in the sense that it will cause page re-evaluation, which will instantiate
        // other parsers. This should not be left in-place in the final product, as it's very server-intensive.
        // Instead, call the cache's enqueue jobs method to put things on the queue.
        $templateLinks = 'templatelinks';
        $linkIds = [];
        foreach ($title->getBacklinkCache()->getLinks($templateLinks) as $link) {
            $linkIds[] = $link->getArticleID();
        }

        if (!count($linkIds)) {
            return;
        }

        $result = $this->dbRead->select(
            self::SET_TABLE,
            ['pageId'],
            ['pageId' => $linkIds],
            __METHOD__
        );

        $recursiveIds = [];
        for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
            $recursiveIds[] = $row['pageId'];
        }

        foreach ($linkIds as $linkId) {
            if (!isset(self::$pagesPurged[$linkId])) {
                self::$pagesPurged[$linkId] = true;
                $title = Title::newFromID($linkId);
                if (isset($recursiveIds[$linkId])) {
                    $job = new RefreshLinksJob(
                        $title,
                        [
                            'table' => $templateLinks,
                            'recursive' => true,
                        ] + Job::newRootJobParams(
                            "refreshlinks:{$templateLinks}:{$title->getPrefixedText()}"
                        )
                    );

                    JobQueueGroup::singleton()->push($job);
                } else {
                    $page = WikiPage::factory($title);
                    $page->doPurge();
                }
            }
        }
    }

    private function updateSetData($setId, MetaTemplateSet $oldSet, MetaTemplateSet $newSet)
    {
        $oldVars = &$oldSet->getVariables();
        $newVars = $newSet->getVariables();
        $deletes = [];
        foreach ($oldVars as $oldName => $oldValue) {
            if (isset($newVars[$oldName])) {
                $newValue = $newVars[$oldName];
                // RHwriteFile('upserts.txt',  $oldVars[$varName]);
                if ($oldValue != $newValue) {
                    // Makes the assumption that most of the time, only a few columns are being updated, so does not
                    // attempt to batch the operation in any way.
                    $this->dbWrite->update(
                        self::DATA_TABLE,
                        [
                            'varValue' => $newValue->getValue(),
                            'parsed' => $newValue->getParsed()
                        ],
                        [
                            'setId' => $setId,
                            'varName' => $oldName
                        ]
                    );
                }

                unset($newVars[$oldName]);
            } else {
                $deletes[] = $oldName;
            }
        }

        if (count($newVars)) {
            $insertSet = new MetaTemplateSet($newSet->getSetName());
            $insertSet->addVariables($newVars);
            $this->insertData($setId, $insertSet);
        }

        if (count($deletes)) {
            $this->dbWrite->delete(self::DATA_TABLE, [
                'setId' => $setId,
                'varName' => $deletes
            ]);
        }
    }
}
