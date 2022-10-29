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
        $oldSets = $oldData ? $oldData->getSets() : null; // new MetaTemplateSet('');
        $newSets = $newData ? $newData->getSets() : null; // new MetaTemplateSet('');

        // Do not change to identity operator - object identity is a reference compare, which will fail.
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
            }
            */
        }

        if ($newData) {
            $this->pageId = $newData->getPageId(); // Possibly redundant, but if both collections are present, both page IDs will be the same.
            $this->newRevId = $newData->getRevId();
            if ($newSets) {
                foreach ($newSets as $setName => $newSet) {
                    $oldSet = $oldSets[$setName] ?? null;
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
