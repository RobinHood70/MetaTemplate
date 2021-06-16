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

    // if rev_id is provided, then it's the one rev_id that should be kept
    // (ideally should perhaps do rev_id lookup as part of query, but it doesn't matter if rev_id
    //  is a bit out of date... the purpose is to make sure that any completely out of date data gets cleaned up)
    // needs to also handle clearing multiple set_ids for same page/subset
    // (otherwise, it could be called over and over again when loads are done)
    private function clearData($title, $rev_id = NULL)
    {
        $page_id =  ($title instanceof Title) ? $title->getArticleID() : $title;
        $conds = array('mt_set_page_id=' . $page_id);
        if (!is_null($rev_id))
            $conds[] = 'mt_set_rev_id < ' . $rev_id;

        // work around to handle DB memory issues
        $result = $this->dbWrite->select(
            'mt_save_set',
            'mt_set_id',
            $conds,
            __METHOD__
        );
        if ($result) {
            while ($row = $this->dbWrite->fetchRow($result)) {
                $rowconds = array('mt_save_id=' . $row['mt_set_id']);
                $this->dbWrite->delete('mt_save_data', $rowconds);
            }
        }
        //		$dbw->deleteJoin( 'mt_save_data', 'mt_save_set', 'mt_save_id', 'mt_set_id', $conds );
        $this->dbWrite->delete('mt_save_set', $conds);

        // to be safe: I don't want these deletes to be run after I insert any new data
        // (although might not be necessary now that I'm not clearing and rewriting data)
        // also needs to be done before next round of deletes
        // With this extra commit image deletion causes an DBUnexpectedError from line 2661 of /home/uesp/www/w/includes/db/Database.php:
        // starting in MW 1.27. Commenting this line out fixes the issue.
        //$dbw->commit();

        // if I didn't clear the title out completely, now check to make sure that no
        // subset names are duplicated (could happen with simultaneous DB updates for same page)
        if (isset($rev_id)) {
            $delids = array();
            $donesets = array();
            $res = $this->dbWrite->select(
                'mt_save_set',
                array('mt_set_subset', 'mt_set_id'),
                array('mt_set_page_id' => $page_id),
                __METHOD__,
                array('ORDER BY' => 'mt_set_rev_id DESC, mt_set_id DESC')
            );

            while ($row = $this->dbWrite->fetchRow($res)) {
                if (!isset($donesets[$row['mt_set_subset']])) {
                    $donesets[$row['mt_set_subset']] = $row['mt_set_id'];
                } else {
                    $delids[] = $row['mt_set_id'];
                }
            }

            if (count($delids))
                $this->deleteSets($delids);
        }
    }

    /**
     * loadPageVariables
     *
     * @return MetaTemplateVariable[]|false
     */
    public function loadNamedVariables($pageId, $subsetName, $revId, $varNames)
    {
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $conds = [
            self::SET_PREFIX . 'page_id' => $pageId,
            self::SET_PREFIX . "rev_id >= $revId",
            self::SET_PREFIX . 'subset' => $subsetName,
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
                $retval[] = new MetaTemplateVariable($row[self::DATA_PREFIX . 'value'], $row[self::DATA_PREFIX . 'parsed']);
                $row = $result->fetchRow();
            }

            return $retval;
        }

        return false;
    }

    public function saveVariables($vars)
    {
        $page = WikiPage::factory($this->title);

        // Updating algorithm is based on assumption that its unlikely any data has actually been changed, therefore:
        // * It's best to read the existing DB data before making any DB updates/inserts.
        // * Chances are that we're going to need to read all the data for this save_set, so best to read it all at
        //   once instead of one entry at a time.
        // * It's best to use the read-only DB until we know we need to write.
        $oldData = $this->loadPageVariables($page);
        $pageId = $page->getId();
        $pageData = $vars[$pageId];
        $deleteIds = [];
        foreach ($oldData->sets as $setName => $oldSet) {
            if (!isset($pageData->sets[$setName])) {
                $deleteIds[] = $oldSet->setId;
            }
        }

        $atom = __METHOD__;
        $this->dbWrite->startAtomic($atom);
        $this->deleteSets($deleteIds);

        try {
            $inserts = [];
            foreach ($pageData->sets as $subsetName => $subdata) {
                if (isset($oldData->sets[$subsetName])) {
                    if ($oldData->revId < $pageData->revId) {
                        // Set exists but RevisionID has changed (page has been edited).
                        $oldSet = $oldData->sets[$subsetName];
                        $this->dbWrite->update(
                            self::SET_TABLE,
                            [self::SET_PREFIX . 'rev_id' => $pageData->revId],
                            [self::SET_PREFIX . 'id' => $oldSet->setId]
                        );
                    }
                } else {
                    // New set.
                    $this->dbWrite->insert(self::SET_TABLE, [
                        self::SET_PREFIX . 'page_id' => $pageData->pageId,
                        self::SET_PREFIX . 'rev_id' => $pageData->revId,
                        self::SET_PREFIX . 'subset' => $subsetName
                    ]);
                    $subdata->setId = $this->dbWrite->insertId();

                    foreach ($subdata->variables as $key => $var) {
                        $inserts[] = [
                            self::DATA_PREFIX . 'id' => $subdata->setId,
                            self::DATA_PREFIX . 'varname' => $key,
                            self::DATA_PREFIX . 'value' => $var->value,
                            self::DATA_PREFIX . 'parsed' => $var->parsed
                        ];
                    }
                }

                $this->updateData($subdata);
            }
        } catch (Exception $e) {
            $this->dbWrite->rollback($atom);
            throw $e;
        }

        if (count($inserts)) {
            // use replace instead of insert just in case there's simultaneous processing going on
            // second param isn't used by mysql, but provide it just in case another DB is used
            $this->dbWrite->replace(
                self::DATA_TABLE,
                [self::DATA_PREFIX . 'id', self::DATA_PREFIX . 'varname'],
                $inserts
            );
        }

        $this->dbWrite->endAtomic($atom);

        // TODO: Because this is now running as part of the broader ParserOutput data updates on the job queue, we can
        // comfortably do more here. Investigate what kind of data cleanup needs to be done.

        //self::cleardata( $this->title );
        return true;
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

    // to delete a specific set_id from mt_save_set and all its associated values from mt_save_data
    // used in cases of duplicate entries for same subset, and also in cases where a single subset
    // needs to be removed from a page (but other subsets are still being kept)
    private function deleteSets($setIds)
    {
        if (!$setIds || empty($setIds)) {
            return;
        }

        $this->dbWrite->delete(self::DATA_TABLE, [self::DATA_PREFIX . 'id' => $setIds]);
        $this->dbWrite->delete(self::SET_TABLE, [self::SET_PREFIX . 'id' => $setIds]);
    }

    /**
     * loadExisting
     *
     * @param mixed $pageId
     *
     * @return MetaTemplateSetCollection;
     */
    private function loadPageVariables(WikiPage $page)
    {
        // Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
        // data is tracked with $deleteIds. While the database should never be in this state with the current design,
        // this should allow for correct behaviour with simultaneous database updates in the event that of some future
        // non-transactional approach.
        $pageId = $page->getId();
        $tables = [self::SET_TABLE, self::DATA_TABLE];
        $conds = [self::SET_PREFIX . 'page_id' => $pageId];
        $fields = [
            self::SET_PREFIX . 'rev_id',
            self::SET_PREFIX . 'subset',
            self::DATA_PREFIX . 'varname',
            self::DATA_PREFIX . 'value',
            self::DATA_PREFIX . 'parsed',
        ];
        $options = ['ORDER BY' => self::SET_PREFIX . 'rev_id DESC'];
        $joinConds = [self::SET_TABLE => ['JOIN', self::SET_PREFIX . 'id = ' . self::DATA_PREFIX . 'id']];
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

        /** @var MetaTemplateSet[] */
        $row = $this->dbRead->fetchRow($result);
        $retval = new MetaTemplateSetCollection($pageId, $row[self::SET_PREFIX . 'rev_id']);
        while ($row) {
            $subsetName = $row[self::SET_PREFIX . 'subset'];
            $set =  $retval->getOrAddSet($subsetName, $row[self::SET_PREFIX . 'rev_id']);
            $set->addVar($row[self::DATA_PREFIX . 'varname'], $row[self::DATA_PREFIX . 'value'], $row[self::DATA_PREFIX . 'parsed']);
            $row = $this->dbRead->fetchRow($result);
        }

        return $retval;
    }

    private function updateData(MetaTemplateSet $oldSet)
    {
        foreach ($oldSet->variables as $key => $var) {
            if (
                !is_null($oldSet) &&
                $oldSet->setId != 0 &&
                $var == $oldSet->variables[$key]
            ) {
                // updates can't be done in a batch... unless I delete then insert them all
                // but I'm assuming that it's most likely only value needs to be updated, in which case
                // it's most efficient to simply make updates one value at a time
                $this->dbWrite->update(
                    self::DATA_TABLE,
                    [
                        self::DATA_PREFIX . 'value' => $var->value,
                        self::DATA_PREFIX . 'parsed' => $var->parsed
                    ],
                    [
                        self::DATA_PREFIX . 'id' => $oldSet->setId,
                        self::DATA_PREFIX . 'varname' => $key
                    ]
                );

                unset($oldSet->variables[$key]);
            }
        }

        // show('Deletable: ', $oldSet->variables);
        if (count($oldSet->variables)) {
            $this->dbWrite->delete(self::DATA_TABLE, [
                self::DATA_PREFIX . 'id' => $oldSet->setId,
                self::DATA_PREFIX . 'varname' => array_keys($oldSet->variables)
            ]);
        }
    }
}
