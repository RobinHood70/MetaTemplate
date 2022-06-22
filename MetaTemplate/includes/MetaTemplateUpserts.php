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
        $oldSets = (bool)$oldData ? $oldData->getSets() : []; // new MetaTemplateSet('');
        $newSets = (bool)$newData ? $newData->getSets() : []; // new MetaTemplateSet('');
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
        }

        // writeFile('upserts.txt', "Upsert Deletes\n", $this->deletes);

        if ($newData) {
            $this->pageId = $newData->getPageId(); // Possibly redundant, but if both collections are present, both page IDs will be the same.
            $this->newRevId = $newData->getRevId();
            if ($newSets) {
                foreach ($newSets as $setName => $newSet) {
                    $oldSet = isset($oldSets) ?  ParserHelper::arrayGet($oldSets, $setName) : null;
                    if ($oldSet) {
                        // All sets are checked for updates as long as an old set existed, since transcluded info may have changed values.
                        $this->updates[$oldData->getSetId($setName)] = [$oldSet, $newSet];
                    } else {
                        $this->inserts[] = $newSet;
                    }
                }
            }
        }

        // writeFile('upserts.txt', "Upsert Inserts\n", $this->inserts);
        // writeFile('upserts.txt', "Upsert Updates\n", $this->updates);
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
