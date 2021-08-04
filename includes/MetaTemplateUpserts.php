<?php
class MetaTemplateUpserts
{
    /** @var int[] */
    private $deletes = [];

    /** @var MetaTemplateSet[] */
    private $inserts = [];

    private $updates = [];

    public function __construct(MetaTemplateSetCollection $oldData = null, MetaTemplateSetCollection $newData = null)
    {
        $oldSets = (bool)$oldData ? $oldData->getSets() : null; // new MetaTemplateSet('');
        $newSets = (bool)$newData ? $newData->getSets() : null; // new MetaTemplateSet('');
        // writeFile('upserts.txt', "OldData\n", $oldData);
        // writeFile('upserts.txt', "NewData\n", $newData);
        if ($oldSets == $newSets) {
            return;
        }

        if ($oldData) {
            foreach ($oldSets as $setName => $oldSet) {
                if (!isset($newSets[$setName])) {
                    $this->deletes[] = $oldData->getSetId($setName);
                }
            }
        }

        // writeFile('upserts.txt', "Upsert Deletes\n", $this->deletes);

        if ($newSets) {
            foreach ($newSets as $setName => $newSet) {
                $oldSet = isset($oldSets) ?  ParserHelper::arrayGet($oldSets, $setName) : null;
                if ($oldSet) {
                    // All sets are checked for updates as long as an old set existed, since transcluded info may have changed values.
                    $this->updates[$oldData->getSetId($setName)] = $newSet;
                } else {
                    $this->inserts[$newData->getRevId()] = $newSet;
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

    public function getTotal()
    {
        return count($this->deletes) + count($this->inserts) + count($this->updates);
    }

    public function getUpdates()
    {
        return $this->updates;
    }
}
