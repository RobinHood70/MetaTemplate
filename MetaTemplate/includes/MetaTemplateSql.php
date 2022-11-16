<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * Handles all SQL-related functions for MetaTemplate.
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
     *
     */
    private static $pagesPurged = [];

    /**
     * Creates an instance of the MetaTemplateSql class.
     *
     */
    private function __construct()
    {
        $dbWriteConst = defined('DB_PRIMARY') ? 'DB_PRIMARY' : 'DB_MASTER';
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $this->dbRead = $lb->getConnectionRef(constant($dbWriteConst));

        // We get dbWrite lazily since writing will often be unnecessary.
        $this->dbWrite = $lb->getLazyConnectionRef(constant($dbWriteConst));
    }

    /**
     * Gets the global singleton instance of the class.
     *
     * @return MetaTemplateSql
     */
    public static function getInstance(): MetaTemplateSql
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Handles data to be deleted.
     *
     * @param Title $title The title of the page to delete from.
     *
     * @return void
     *
     */
    public function deleteVariables(Title $title): void
    {
        $pageId = $title->getArticleID();

        // Assumes cascading is in effect to delete DATA_TABLE rows.
        $this->dbWrite->delete(self::SET_TABLE, ['pageId' => $pageId]);
        self::$pagesPurged[$pageId] = true;
        $this->recursiveInvalidateCache($title);
    }

    /**
     * Handles data to be inserted.
     *
     * @param mixed $setId The set ID to insert.
     * @param MetaTemplateSet $newSet The set to insert.
     *
     * @return void
     *
     */
    public function insertData($setId, MetaTemplateSet $newSet): void
    {
        $data = [];
        foreach ($newSet->getVariables() as $key => $var) {
            $data[] = [
                'setId' => $setId,
                'varName' => $key,
                'varValue' => $var->getValue(),
                'parseOnLoad' => $var->getParseOnLoad()
            ];
        }

        $this->dbWrite->insert(self::DATA_TABLE, $data);
    }

    public function loadListSavedData(int $namespace, array $named, array $translations): array
    {
        $tables = [
            'page',
            self::SET_TABLE,
            self::DATA_TABLE
        ];
        $fields = [
            'page.page_id',
            'page.page_title',
            'page.page_namespace',
            self::SET_TABLE . '.setName',
            self::DATA_TABLE . '.varName',
            self::DATA_TABLE . '.varValue'
        ];
        $options = [];
        $joinConds = [
            'mtSaveSet' => ['JOIN', ['page.page_id=' . self::SET_TABLE . '.pageId']],
            'mtSaveData' => ['JOIN', [self::SET_TABLE . '.setId=' . self::DATA_TABLE . '.setId']]
        ];

        $varNames = array_merge(array_keys($named), array_keys($translations));
        $conds = [self::DATA_TABLE . '.varName' => $varNames];
        if ($namespace >= 0) {
            $conds['page.page_namespace'] = $namespace;
        }

        $data = 1;
        foreach ($named as $key => $value) {
            $dataName = 'data' . $data;
            $tables[$dataName] = self::DATA_TABLE;
            $joinConds[$dataName] = ['JOIN', [self::SET_TABLE . '.setId=' . $dataName . '.setId']];
            $conds[$dataName . '.varName'] = $key;
            $conds[$dataName . '.varValue'] = $value;
            $data++;
        }

        // RHshow($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
        $rows = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options, $joinConds);

        $retval = [];
        for ($row = $rows->fetchRow(); $row; $row = $rows->fetchRow()) {
            // The key only serves to provide a quick, unique index as we iterate. After that, it's discarded.
            $key = str_pad($row['page_id'], 8, '0', STR_PAD_LEFT) . '_' . $row['setName'];
            if (!isset($retval[$key])) {
                $retval[$key] = [
                    'namespace' => $row['page_namespace'],
                    'pagename' => $row['page_title'],
                    'set' => $row['setName']
                ];
            }

            $varName = $translations[$row['varName']];
            // Because the final result will always be parsed, we don't need to worry about parsing it here; we can
            // just include the value verbatim.
            $retval[$key][$varName] = $row['varValue'];
        }

        // RHshow($retval);
        return array_values($retval);
    }

    /**
     * Loads variables for a specific page.
     *
     * @param mixed $pageId The page ID to load.
     *
     * @return MetaTemplateSetCollection
     */
    public function loadPageVariables($pageId): ?MetaTemplateSetCollection
    {
        // Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
        // data is tracked with $deleteIds.

        // logFunctionText("($pageId)");
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $fields = [
            self::SET_TABLE . '.setId',
            'revId',
            'setName',
            'varName',
            'varValue',
            'parseOnLoad',
        ];
        $joinConds = [self::DATA_TABLE => ['JOIN', [self::DATA_TABLE . '.setId=' . self::SET_TABLE . '.setId']]];
        $conds = ['pageId' => $pageId];
        $options = ['ORDER BY' => 'revId'];
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
        $row = $this->dbRead->fetchRow($result);
        if (!$row) {
            return null;
        }

        $retval = new MetaTemplateSetCollection($pageId, $row['revId']);
        while ($row) {
            $set =  $retval->getOrCreateSet($row['setId'], $row['setName']);
            $set->addVariable($row['varName'], $row['varValue'], $row['parseOnLoad']);
            $row = $this->dbRead->fetchRow($result);
        }

        return $retval;
    }

    /**
     * Loads variables from the database.
     *
     * @param mixed $pageId The page ID to load.
     * @param string $setName The set name to load.
     * @param array $varNames A filter of which variable names should be returned.
     *
     * @return ?MetaTemplateVariable[]
     */
    public function loadTableVariables($pageId, string $setName = '', $varNames = []): ?array
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
            'parseOnLoad'
        ];

        $options = ['ORDER BY' => 'revId ASC'];

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
            $retval[$row['varName']] = new MetaTemplateVariable($row['varValue'], $row['parseOnLoad']);
            $row = $result->fetchRow();
        }

        return $retval;
    }

    public function moveVariables(int $oldid, int $newid)
    {
        $this->dbRead->update(
            self::SET_TABLE,
            ['pageId' => $newid],
            ['pageId' => $oldid]
        );
    }

    /**
     * Does a simple purge on all direct backlinks from a page. Needs tested to see if this should be used in the long run. Might be too server intensive, but
     *
     * @param Title $title
     *
     * @return void
     *
     */
    public function recursiveInvalidateCache(Title $title): void
    {
        // Note: this is recursive only in the sense that it will cause page re-evaluation, which will, in turn, cause
        // their dependents to be re-evaluated. This should not be left in-place in the final product, as it's very
        // server-intensive. (Is it, though? Test on large job on dev.) Instead, call the cache's enqueue jobs method
        // to put things on the queue or possibly just send this page to be purged with forcerecursivelinksupdate.

        // RHwriteFile('Recursive Invalidate');
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

        // RHwriteFile('End Recursive Update');
    }

    /**
     * Saves variables to the database.
     *
     * @param Parser $parser The parser in use.
     *
     * @return void
     *
     */
    public function saveVariables(Parser $parser): void
    {
        // This algorithm is based on the assumption that data is rarely changed, therefore:
        // * It's best to read the existing DB data before making any DB updates/inserts.
        // * Chances are that we're going to need to read all the data for this save set, so best to read it all at
        //   once instead of individually or by set.
        // * It's best to use the read-only DB until we know we need to write.

        $title = $parser->getTitle();
        $output = $parser->getOutput();
        $vars = MetaTemplateData::getPageVariables($output);
        if (!$parser->getRevisionId()) {
            return;
        }

        // RHwriteFile("Saving:\n", $vars);
        // MetaTemplateData::setPageVariables($output, null);
        if (!$vars || $vars->isEmpty()) {
            // RHwriteFile('Empty Vars: ', $title->getFullText());
            // If there are no variables on the page at all, check if there were to begin with. If so, delete them.
            if ($this->loadPageVariables($title->getArticleID())) {
                // RHwriteFile('Delete Vars: ', $title->getFullText());
                $this->deleteVariables($title);
            }
        } else if ($vars->getRevId() === -1) {
            // The above check will only be satisfied on Template-space pages that use #save.
            // RHwriteFile('Save Template: ', $title->getFullText());
            $this->recursiveInvalidateCache($title);
        } else {
            $pageId = $title->getArticleID();

            // Whether or not the data changed, the page has been evaluated, so add it to the list.
            self::$pagesPurged[$pageId] = true;
            $oldData = $this->loadPageVariables($title->getArticleID());
            $upserts = new MetaTemplateUpserts($oldData, $vars);
            if ($upserts->getTotal() > 0) {
                // RHwriteFile('Normal Save: ', $title->getFullText());
                $this->saveUpserts($upserts);
                $this->recursiveInvalidateCache($title);
            }
        }

        MetaTemplateData::setPageVariables($output, null);
    }

    /**
     * Indicates whether the tables needed for MetaTemplate's data features exist.
     *
     * @return bool Whether both tables exist.
     */
    public function tablesExist(): bool
    {
        return
            $this->dbRead->tableExists(self::SET_TABLE) &&
            $this->dbRead->tableExists(self::DATA_TABLE);
    }

    /**
     * Alters the database in whatever ways are necessary to update one revision's variables to the next.
     *
     * @param MetaTemplateUpserts $upserts
     *
     * @return void
     *
     */
    private function saveUpserts(MetaTemplateUpserts $upserts): void
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
                    ['revId' => $newRevId],
                    [
                        // setId uniquely identifies the set, but setName and pageId are part of the primary key, so we
                        // add them here for better indexing.
                        'setName' => $oldSet->getSetName(),
                        'pageId' => $upserts->getPageId(),
                        'setId' => $setId
                    ]
                );
            }
        }
    }

    /**
     * Alters the database in whatever ways are necessary to update one revision's sets to the next.
     *
     * @param mixed $setId The set ID # from the mtSaveSet table.
     * @param MetaTemplateSet $oldSet The previous revision's set data.
     * @param MetaTemplateSet $newSet The current revision's set data.
     *
     * @return void
     *
     */
    private function updateSetData($setId, MetaTemplateSet $oldSet, MetaTemplateSet $newSet): void
    {
        // RHshow('Update Set Data');
        $oldVars = &$oldSet->getVariables();
        $newVars = $newSet->getVariables();
        $deletes = [];
        foreach ($oldVars as $varName => $oldValue) {
            if (isset($newVars[$varName])) {
                $newValue = $newVars[$varName];
                // RHwriteFile($oldVars[$varName]);
                if ($oldValue != $newValue) {
                    // RHwriteFile("Updating $varName from {$oldValue->getValue()} to {$newValue->getValue()}");
                    // Makes the assumption that most of the time, only a few columns are being updated, so does not
                    // attempt to batch the operation in any way.
                    $this->dbWrite->update(
                        self::DATA_TABLE,
                        [
                            'varValue' => $newValue->getValue(),
                            'parseOnLoad' => $newValue->getParseOnLoad()
                        ],
                        [
                            'setId' => $setId,
                            'varName' => $varName
                        ]
                    );
                }

                unset($newVars[$varName]);
            } else {
                $deletes[] = $varName;
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
