<?php
class MetaTemplateUpserts
{
    /** @var int[] */
    public $deletes = [];

    /** @var MetaTemplateSet[] */
    public $inserts = [];

    /** @var array */ // [int setId, [MetaTemplateSet oldSet, MetaTemplateSet newSet]]
    public $updates = [];

    public $newRevId;
    public $oldRevId;
    public $pageId;

    /**
     * Creates a new instance of the MetaTemplateUpserts class.
     *
     * @param ?MetaTemplateSetCollection $oldData
     * @param ?MetaTemplateSetCollection $newData
     *
     */
    public function __construct(?MetaTemplateSetCollection $oldData, ?MetaTemplateSetCollection $newData)
    {
        $oldSets = $oldData ? $oldData->sets : null;
        $newSets = $newData ? $newData->sets : null;

        // Do not change to identity operator - object identity is a reference compare, which will fail.
        if ($oldSets == $newSets) {
            return;
        }

        if ($oldData) {
            $this->pageId = $oldData->pageId;
            $this->oldRevId = $oldData->revId;
            foreach ($oldSets as $setName => $oldSet) {
                if (!isset($newSets[$setName])) {
                    $this->deletes[] = $oldData->setIds[$setName];
                }
            }

            /*
            if (count($this->deletes)) {
                RHshow("Upsert Deletes\n", $this->deletes);
            }
            */
        }

        if ($newData) {
            $this->pageId = $newData->pageId; // Possibly redundant, but if both collections are present, both page IDs will be the same.
            $this->newRevId = $newData->revId;
            if ($newSets) {
                foreach ($newSets as $setName => $newSet) {
                    $oldSet = $oldSets[$setName] ?? null;
                    if ($oldSet) {
                        // All sets are checked for updates as long as an old set existed, since transcluded info may have changed values.
                        $this->updates[$oldData->setIds[$setName]] = [$oldSet, $newSet];
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

    /**
     * Gets the total number of operations for this upsert.
     *
     * @return int The total number of operations for this upsert.
     *
     */
    public function getTotal()
    {
        return count($this->deletes) + count($this->inserts) + count($this->updates);
    }
}
