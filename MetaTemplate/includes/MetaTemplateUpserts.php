<?php
class MetaTemplateUpserts
{
    /** @var int[] */
    private $deletes = [];

    /** @var MetaTemplateSet[] */
    private $inserts = [];

    /** @var array */ // [int setId, [MetaTemplateSet oldSet, MetaTemplateSet newSet]]
    private $updates = [];

    private $newRevId;
    private $oldRevId;
    private $pageId;

    /**
     * Creates a new instance of the MetaTemplateUpserts class.
     *
     * @param ?MetaTemplateSetCollection $oldData
     * @param ?MetaTemplateSetCollection $newData
     *
     */
    public function __construct(?MetaTemplateSetCollection $oldData, ?MetaTemplateSetCollection $newData)
    {
        $oldSets = $oldData ? $oldData->getSets() : null;
        $newSets = $newData ? $newData->getSets() : null;

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

    /**
     * Gets the deletions that need to be made.
     *
     * @return array The insertions that need to be made.
     *
     */
    public function getDeletes(): array
    {
        return $this->deletes;
    }

    /**
     * Gets the insertions that need to be made.
     *
     * @return array The insertions that need to be made.
     *
     */
    public function getInserts(): array
    {
        return $this->inserts;
    }

    /**
     * Gets the new revision ID.
     *
     * @return int The new revision ID.
     *
     */
    public function getNewRevId(): int
    {
        return $this->newRevId;
    }

    /**
     * Gets the previous revision's ID.
     *
     * @return int The previous revision's ID.
     *
     */
    public function getOldRevId(): int
    {
        return $this->oldRevId;
    }

    /**
     * Gets the page ID.
     *
     * @return int The page ID.
     *
     */
    public function getPageId(): int
    {
        return $this->pageId;
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

    /**
     * Gets the updates that need to be made.
     *
     * @return array
     *
     */
    public function getUpdates()
    {
        return $this->updates;
    }
}
