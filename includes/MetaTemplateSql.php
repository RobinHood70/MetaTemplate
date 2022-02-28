<?php

/**
 * [Description MetaTemplateData]
 */
class MetaTemplateSql
{
    const SET_TABLE = 'mt_save_set';
    const SET_PREFIX = 'mt_set_';
    const DATA_TABLE = 'mt_save_data';
    const DATA_PREFIX = 'mt_save_';

    /** @var MetaTemplateSql */
    private static $instance;

    /** @var DatabaseBase */
    private $dbRead;

    /** @var DatabaseBase */
    private $dbWrite;

    /**
     * A list of all the pages purged during this session. Never cleared, since this effectively acts as an instance
     * varible for the parent page.
     *
     * @var array
     */
    private static $pagesPurged = [];

    private function __construct()
    {
        $this->dbRead = wfGetDB(DB_SLAVE);
        $this->dbWrite = wfGetDB(DB_MASTER);
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

    public function cleanupData($pageId = NULL)
    {
        // TODO: Investigate what kind of data cleanup might need to be done.
        return;

        // Proof of concept. Last deletion, especially, is not a good way to do this on a per-page basis. Check main code to see if this can all be incorporated there.
        $conds = is_null($pageId)
            ? 'page.page_id IS NULL'
            : ['page.page_id' => 'NULL', self::SET_TABLE . 'page_id' => $pageId];
        $this->dbWrite->deleteJoin(self::SET_TABLE, 'page', self::SET_PREFIX . 'page_id', 'page_id', $conds, __METHOD__);

        $conds = is_null($pageId)
            ? self::SET_PREFIX . 'rev_id < page.page_latest'
            : [self::SET_PREFIX . 'rev_id < page.page_latest', self::SET_TABLE . 'page_id = ' . $pageId];
        $this->dbWrite->deleteJoin(self::SET_TABLE, 'page', self::SET_PREFIX . 'page_id', 'page_id', $conds, __METHOD__);

        $this->dbWrite->deleteJoin(self::DATA_TABLE, self::SET_TABLE, self::DATA_PREFIX . 'id', 'id', self::SET_TABLE . self::SET_PREFIX . `id IS NULL`, __METHOD__);
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
        // data is tracked with $deleteIds. While the database should never be in this state with the current design,
        // this should allow for correct behaviour with simultaneous database updates in the event that of some future
        // non-transactional approach.

        // logFunctionText("($pageId)");
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $conds = [self::SET_PREFIX . 'page_id' => $pageId];
        $fields = [
            self::SET_PREFIX . 'id',
            self::SET_PREFIX . 'rev_id',
            self::SET_PREFIX . 'subset',
            self::DATA_PREFIX . 'varname',
            self::DATA_PREFIX . 'value',
            self::DATA_PREFIX . 'parsed',
        ];
        $options = ['ORDER BY' => self::SET_PREFIX . 'rev_id DESC'];
        $joinConds = [self::SET_TABLE => ['JOIN', [self::SET_PREFIX . 'id = ' . self::DATA_PREFIX . 'id']]];
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
        // logFunctionText(' ', formatQuery($this->dbRead));
        $row = $this->dbRead->fetchRow($result);
        if ($row) {
            $retval = new MetaTemplateSetCollection($pageId, $row[self::SET_PREFIX . 'rev_id']);
            while ($row) {
                $set =  $retval->getOrCreateSet($row[self::SET_PREFIX . 'id'], $row[self::SET_PREFIX . 'subset']);
                $set->addVariable($row[self::DATA_PREFIX . 'varname'], $row[self::DATA_PREFIX . 'value'], $row[self::DATA_PREFIX . 'parsed']);
                $row = $this->dbRead->fetchRow($result);
            }

            return $retval;
        }

        return null;
    }

    public function insertData($setId, MetaTemplateSet $newSet)
    {
        $data = [];
        foreach ($newSet->getVariables() as $key => $var) {
            $data[] = [
                self::DATA_PREFIX . 'id' => $setId,
                self::DATA_PREFIX . 'varname' => $key,
                self::DATA_PREFIX . 'value' => $var->getValue(),
                self::DATA_PREFIX . 'parsed' => $var->getParsed()
            ];
        }

        $this->dbWrite->insert(self::DATA_TABLE, $data);
    }

    /**
     * loadTableVariables
     *
     * @param mixed $pageId
     * @param mixed $revId
     * @param string $setName
     * @param array $varNames
     *
     * @return MetaTemplateVariable[]|bool
     */
    public function loadTableVariables($pageId, $revId, $setName = '', $varNames = [])
    {
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $conds = [
            self::SET_PREFIX . 'page_id' => $pageId,
            self::SET_PREFIX . 'rev_id >= ' . intval($revId),
            self::SET_PREFIX . 'subset' => $setName,
            self::DATA_PREFIX . 'varname' => $varNames
        ];
        $fields = [
            self::DATA_PREFIX . 'varname',
            self::DATA_PREFIX . 'value',
            self::DATA_PREFIX . 'parsed',
        ];

        // Transactions should make sure this never happens, but in the event that we got more than one rev_id back,
        // ensure that we start with the lowest first, so data is overridden by the most recent values once we get
        // there, but lower values will exist if the write is incomplete.
        $options = ['ORDER BY' => self::SET_PREFIX . 'rev_id ASC'];
        $joinConds = [self::SET_TABLE => ['JOIN', self::SET_PREFIX . 'id = ' . self::DATA_PREFIX . 'id']];
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

        $retval = [];
        if ($result && $result->numRows()) {
            $row = $result->fetchRow();
            while ($row) {
                $retval[$row[self::DATA_PREFIX . 'varname']] = new MetaTemplateVariable($row[self::DATA_PREFIX . 'value'], $row[self::DATA_PREFIX . 'parsed']);
                $row = $result->fetchRow();
            }

            return $retval;
        }

        return false;
    }

    public function deleteVariable(Title $title)
    {
        // We run saveVariable even if $vars is empty, since that could mean that all #saves have been removed from the page.
        $pageId = $title->getArticleID();

        // Whether or not the data changed, the page has been evaluated, so add it to the list.
        self::$pagesPurged[$pageId] = true;
        $oldData = $this->loadPageVariables($pageId);
        $upserts = new MetaTemplateUpserts($oldData, $vars);
        if ($upserts->getTotal() > 0) {
            $this->reallySaveVariables($upserts);
            $this->recursiveInvalidateCache($title);
        }
    }

    public function saveVariables(Title $title, MetaTemplateSetCollection $vars = null)
    {
        // This algorithm is based on the assumption that data is rarely changed, therefore:
        // * It's best to read the existing DB data before making any DB updates/inserts.
        // * Chances are that we're going to need to read all the data for this save_set, so best to read it all at
        //   once instead of individually or by set.
        // * It's best to use the read-only DB until we know we need to write.

        if ($vars) {
            logFunctionText('(' . $vars->getRevId() . ': ' . $title->getFullText() . ')');
            if ($vars->getRevId() === -1) {
                // The above check will only be satisfied on Template-space pages that use #save. We need to have a way to
                // check for data inconsistencies, and since templates save no other data, this seems like a good place to
                // put this for now. Might also make sense as a maintenance job.
                $this->cleanupData();
                $this->recursiveInvalidateCache($title);
            } else {
                // We run saveVariable even if $vars is empty, since that could mean that all #saves have been removed from the page.
                $pageId = $title->getArticleID();

                // Whether or not the data changed, the page has been evaluated, so add it to the list.
                self::$pagesPurged[$pageId] = true;
                $oldData = $this->loadPageVariables($pageId);
                $upserts = new MetaTemplateUpserts($oldData, $vars);
                if ($upserts->getTotal() > 0) {
                    $this->reallySaveVariables($upserts);
                    $this->recursiveInvalidateCache($title);
                }
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

    private function reallySaveVariables(MetaTemplateUpserts $upserts)
    {
        $deletes = $upserts->getDeletes();
        // writeFile('  Deletes: ', count($deletes));
        if (count($deletes)) {
            $this->dbWrite->delete(self::DATA_TABLE, [self::DATA_PREFIX . 'id' => $deletes]);
            $this->dbWrite->delete(self::SET_TABLE, [self::SET_PREFIX . 'id' => $deletes]);
        }

        $pageId = $upserts->getPageId();
        $newRevId = $upserts->getNewRevId();
        // writeFile('  Inserts: ', count($inserts));
        foreach ($upserts->getInserts() as $newSet) {
            $this->dbWrite->insert(self::SET_TABLE, [
                self::SET_PREFIX . 'page_id' => $pageId,
                self::SET_PREFIX . 'rev_id' => $newRevId,
                self::SET_PREFIX . 'subset' => $newSet->getSetName()
            ]);
            $setId = $this->dbWrite->insertId();
            $this->insertData($setId, $newSet);
        }

        $updates = $upserts->getUpdates();
        // writeFile('  Updates: ', count($updates));
        if (count($updates)) {
            foreach ($updates as $setId => $setData) {
                list($oldSet, $newSet) = $setData;
                $this->updateSetData($setId, $oldSet, $newSet);
            }

            if (
                $upserts->getOldRevId() < $newRevId
            ) {
                $this->dbWrite->update(
                    self::SET_TABLE,
                    [self::SET_PREFIX . 'rev_id' => $newRevId],
                    [self::SET_PREFIX . 'id' => $setId]
                );
            }
        }
    }

    private function recursiveInvalidateCache(Title $title)
    {
        // Note: this is recursive only in the sense that it will cause page re-evaluation, which will instantiate
        // other parsers. This should not be left in-place in the final product, as it's very server-intensive.
        // Instead, call the cache's enqueue jobs method to put things on the queue.
        $table = 'templatelinks';
        /** @var Title[] */
        $linkTitles = iterator_to_array($title->getBacklinkCache()->getLinks($table));
        $linkIds = [];
        foreach ($linkTitles as $link) {
            $linkIds[] = $link->getArticleID();
        }

        $result = $this->dbRead->select(
            self::SET_TABLE,
            [self::SET_PREFIX . 'page_id'],
            [self::SET_PREFIX . 'page_id' => $linkIds],
            __METHOD__
        );

        $recursiveIds = [];
        for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
            $recursiveIds[] = $row[self::SET_PREFIX . 'page_id'];
        }

        foreach ($linkIds as $linkId) {
            if (!isset(self::$pagesPurged[$linkId])) {
                self::$pagesPurged[$linkId] = true;
                $title = Title::newFromID($linkId);
                if (isset($recursiveIds[$linkId])) {
                    writeFile('Queue: ' . $title->getFullText());
                    $job = new RefreshLinksJob(
                        $title,
                        [
                            'table' => $table,
                            'recursive' => true,
                        ] + Job::newRootJobParams(
                            "refreshlinks:{$table}:{$title->getPrefixedText()}"
                        )
                    );

                    writeFile($job);
                    JobQueueGroup::singleton()->push($job);
                } else {
                    writeFile('Purge: ' . $title->getFullText());
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
                // writeFile('upserts.txt',  $oldVars[$varName]);
                if ($oldValue != $newValue) {
                    // updates can't be done in a batch... unless I delete then insert them all
                    // but I'm assuming that it's most likely only value needs to be updated, in which case
                    // it's most efficient to simply make updates one value at a time
                    $this->dbWrite->update(
                        self::DATA_TABLE,
                        [
                            self::DATA_PREFIX . 'value' => $newValue->getValue(),
                            self::DATA_PREFIX . 'parsed' => $newValue->getParsed()
                        ],
                        [
                            self::DATA_PREFIX . 'id' => $setId,
                            self::DATA_PREFIX . 'varname' => $oldName
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
                self::DATA_PREFIX . 'id' => $setId,
                self::DATA_PREFIX . 'varname' => $deletes
            ]);
        }
    }
}
