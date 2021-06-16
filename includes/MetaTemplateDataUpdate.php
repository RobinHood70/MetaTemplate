<?php

class MetaTemplateDataUpdate extends DataUpdate
{
    /** @var Title */
    private $title;

    /** @var ParserOutput */
    private $output;

    public function __construct(Title $title, ParserOutput $parserOutput)
    {
        $this->title = $title;
        $this->output = $parserOutput;
    }

    public function doUpdate()
    {
        if (!$this->title || !$this->output || $this->title->getNamespace() < NS_MAIN || wfReadOnly()) {
            return true;
        }

        $vars = $this->output->getExtensionData(MetaTemplateData::PF_SAVE);
        if (!$vars || !count($vars)) {
            return true;
        }

        show('Saving');
        $dbRead = wfGetDB(DB_SLAVE);
        $dbWrite = wfGetDB(DB_MASTER);
        $page = WikiPage::factory($this->title);

        // Updating algorithm is based on assumption that its unlikely any data is actually been changed, therefore:
        // * it's best to read the existing DB data before making any DB updates/inserts
        // * the chances are that we're going to need to read all the data for this save_set,
        //   so best to read it all at once instead of one entry at a time
        // * best to use read-only DB object until/unless it's clear that we'll need to write

        // For now, data updates are fully customized, but could also be using upsert at some point. That has the
        // slight disadvantage of trying to update first, then seeing what failed, where this routines makes all the
        // decisions in advance. The downside is it's much longer.

        $oldData = self::loadExisting($page);
        // show('Sets: ', $oldData->sets);
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
        if (count($deleteIds)) {
            $this->dbWrite->delete(MetaTemplateData::DATA_TABLE, [
                MetaTemplateData::DATA_PREFIX . 'set_id' => $deleteIds
            ]);
            $this->dbWrite->delete(MetaTemplateData::SET_TABLE, [
                MetaTemplateData::DATA_PREFIX . 'set_id' => $deleteIds
            ]);
        }

        try {
            $inserts = [];
            foreach ($pageData->sets as $subsetName => $subdata) {
                if (isset($oldData->sets[$subsetName])) {
                    if ($oldData->revId < $pageData->revId) {
                        // Set exists but RevisionID has changed (page has been edited).
                        $oldSet = $oldData->sets[$subsetName];
                        $this->dbWrite->update(
                            MetaTemplateData::SET_TABLE,
                            [MetaTemplateData::SET_PREFIX . 'rev_id' => $pageData->revId],
                            [MetaTemplateData::SET_PREFIX . 'id' => $oldSet->setId]
                        );
                    }
                } else {
                    // New set.
                    $this->dbWrite->insert(MetaTemplateData::SET_TABLE, [
                        MetaTemplateData::SET_PREFIX . 'page_id' => $pageData->pageId,
                        MetaTemplateData::SET_PREFIX . 'rev_id' => $pageData->revId,
                        MetaTemplateData::SET_PREFIX . 'subset' => $subsetName
                    ]);
                    $subdata->setId = $this->dbWrite->insertId();

                    foreach ($subdata->variables as $key => $var) {
                        $inserts[] = [
                            MetaTemplateData::DATA_PREFIX . 'id' => $subdata->setId,
                            MetaTemplateData::DATA_PREFIX . 'varname' => $key,
                            MetaTemplateData::DATA_PREFIX . 'value' => $var->value,
                            MetaTemplateData::DATA_PREFIX . 'parsed' => $var->parsed
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
                MetaTemplateData::DATA_TABLE,
                [MetaTemplateData::DATA_PREFIX . 'id', MetaTemplateData::DATA_PREFIX . 'varname'],
                $inserts
            );
        }

        $this->dbWrite->endAtomic($atom);

        /* TODO: Create an actual job class to do this and run it on occasion. (See if there are relevant hooks, otherwise use a similar low-frequency method as below.)
		//MetaTemplateData::cleardata( $this->title );
		if (count($oldData) || count($deleteIds)) {
			foreach ($oldData as $subset => $subdata)
				$deleteIds[] = $subdata[MetaTemplateData::SET_PREFIX . 'id'];
			MetaTemplateData::clearsets($deleteIds);
		}

		global $wgJobRunRate;
		// same frequency algorithm used by Wiki.php to determine whether or not to do a job
		if ($wgJobRunRate > 0) {
			if ($wgJobRunRate < 1) {
				$max = mt_getrandmax();
				if (mt_rand(0, $max) > $max * $wgJobRunRate)
					$n = 0;
				else
					$n = 1;
			} else {
				$n = intval($wgJobRunRate);
			}
			if ($n) {
				MetaTemplateData::clearoldsets($n);
			}
		}
		*/

        $this->output->setExtensionData(MetaTemplateData::PF_SAVE, null);
        return true;
    }

    /**
     * loadExisting
     *
     * @param mixed $pageId
     *
     * @return MetaTemplateSetCollection;
     */
    private function loadExisting(WikiPage $page)
    {
        // Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
        // data is tracked with $deleteIds. While the database should never be in this state with the current design,
        // this should allow for correct behaviour with simultaneous database updates in the event that of some future
        // non-transactional approach.
        $pageId = $page->getId();
        $tables = [MetaTemplateData::SET_TABLE, MetaTemplateData::DATA_TABLE];
        $conds = [MetaTemplateData::SET_PREFIX . 'page_id' => $pageId];
        $fields = [
            MetaTemplateData::SET_PREFIX . 'rev_id',
            MetaTemplateData::SET_PREFIX . 'subset',
            MetaTemplateData::DATA_PREFIX . 'varname',
            MetaTemplateData::DATA_PREFIX . 'value',
            MetaTemplateData::DATA_PREFIX . 'parsed',
        ];
        $options = ['ORDER BY' => MetaTemplateData::SET_PREFIX . 'rev_id DESC'];
        $joinConds = [MetaTemplateData::SET_TABLE => ['JOIN', MetaTemplateData::SET_PREFIX . 'id = ' . MetaTemplateData::DATA_PREFIX . 'id']];
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

        /** @var MetaTemplateSet[] */
        $row = $this->dbRead->fetchRow($result);
        $retval = new MetaTemplateSetCollection($pageId, $row[MetaTemplateData::SET_PREFIX . 'rev_id']);
        while ($row) {
            $subsetName = $row[MetaTemplateData::SET_PREFIX . 'subset'];
            $set =  $retval->getOrAddSet($subsetName, $row[MetaTemplateData::SET_PREFIX . 'rev_id']);
            $set->addVar($row[MetaTemplateData::DATA_PREFIX . 'varname'], $row[MetaTemplateData::DATA_PREFIX . 'value'], $row[MetaTemplateData::DATA_PREFIX . 'parsed']);
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
                    MetaTemplateData::DATA_TABLE,
                    [
                        MetaTemplateData::DATA_PREFIX . 'value' => $var->value,
                        MetaTemplateData::DATA_PREFIX . 'parsed' => $var->parsed
                    ],
                    [
                        MetaTemplateData::DATA_PREFIX . 'id' => $oldSet->setId,
                        MetaTemplateData::DATA_PREFIX . 'varname' => $key
                    ]
                );

                unset($oldSet->variables[$key]);
            }
        }

        // show('Deletable: ', $oldSet->variables);
        if (count($oldSet->variables)) {
            $this->dbWrite->delete(MetaTemplateData::DATA_TABLE, [
                MetaTemplateData::DATA_PREFIX . 'id' => $oldSet->setId,
                MetaTemplateData::DATA_PREFIX . 'varname' => array_keys($oldSet->variables)
            ]);
        }
    }
}
