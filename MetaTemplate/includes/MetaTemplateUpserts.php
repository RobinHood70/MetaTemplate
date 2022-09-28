<?php
class MetaTemplateUpserts
{
    /** @var int[] */
    private $deletes = [];

    /** @var MetaTemplateSet[] */
    private $inserts = [];

    private $newRevId;
    private $oldRevId;
    private $pageId;

    private $updates = [];

    public function __construct(MetaTemplateSetCollection $oldData = null, MetaTemplateSetCollection $newData = null)
    {
        /** @var MetaTemplateSet[] */
        $oldSets = (bool)$oldData ? $oldData->getSets() : false; // new MetaTemplateSet('');
        /** @var MetaTemplateSet[] */
        $newSets = (bool)$newData ? $newData->getSets() : false; // new MetaTemplateSet('');
        // RHshow("Old sets:\n", $oldSets);
        // RHshow("New sets:\n", $newSets);
        if ($oldSets == $newSets) {
            return;
        }

        if ($oldData) {
            $this->pageId = $oldData->getPageId();
            $this->oldRevId = $oldData->getRevId();
            foreach ($oldSets as $setName => $oldSet) {
                if (!isset($newSets[$setName])) {
                    $this->deletes[] = $oldData->getSetId($setName);
                }
            }

            /*
            if (count($this->deletes)) {
                RHshow("Upsert Deletes\n", $this->deletes);
            } */
        }

        if ($newData) {
            $this->pageId = $newData->getPageId(); // Possibly redundant, but if both collections are present, both page IDs will be the same.
            $this->newRevId = $newData->getRevId();
            if ($newSets) {
                foreach ($newSets as $setName => $newSet) {
                    $oldSet = $oldSets ?  ParserHelper::getInstance()->arrayGet($oldSets, $setName) : null;
                    if ($oldSet) {
                        // All sets are checked for updates as long as an old set existed, since transcluded info may have changed values.
                        $this->updates[$oldData->getSetId($setName)] = [$oldSet, $newSet];
                    } else {
                        $this->inserts[] = $newSet;
                    }
                }
            }
            /*
            if (count($this->inserts)) {
                RHshow("Upsert Inserts\n", $this->inserts);
            }

            if (count($this->updates)) {
                RHshow("Upsert Updates\n", $this->updates);
            } */
        }
    }

    public function getDeletes()
    {
        return $this->deletes;
    }

    public function getInserts()
    {
        return $this->inserts;
    }

    public function getNewRevId()
    {
        return $this->newRevId;
    }

    public function getOldRevId()
    {
        return $this->oldRevId;
    }

    public function getPageId()
    {
        return $this->pageId;
    }

    public function getTotal()
    {
        return count($this->deletes) + count($this->inserts) + count($this->updates);
    }

    public function getUpdates()
    {
        return $this->updates;
    }
}
