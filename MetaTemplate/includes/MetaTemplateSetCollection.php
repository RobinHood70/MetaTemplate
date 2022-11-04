<?php

class MetaTemplateSetCollection
{
    private $pageId;
    private $revId;

    /**
     * All sets on the page.
     *
     * @var MetaTemplateSet[]
     */
    private $sets = [];

    /**
     * All set IDs.
     *
     * @var int[]
     */
    private $setIds = []; // We mostly want to ignore the IDs in any operations, except when it comes to the final upserts, so we store them separately.

    /**
     * Creates a set collection.
     *
     * @param mixed $pageId The page the set belongs to.
     * @param mixed $revId The revision ID of the set.
     *
     */
    public function __construct($pageId, $revId)
    {
        $this->pageId = $pageId;
        $this->revId = $revId;
    }

    /**
     * Clears all sets from the collection.
     *
     * @return void
     *
     */
    public function clear(): void
    {
        $this->sets = [];
    }

    /**
     * Indicates whether this page has sets or not.
     *
     * @return bool
     *
     */
    public function isEmpty(): bool
    {
        return !$this->sets;
    }

    /**
     * Gets a set by name if it exists or creates one if it doesn't.
     *
     * @param int $setId The set ID. If set to zero, this will be
     * @param string $setName
     *
     * @return MetaTemplateSet
     *
     */
    public function getOrCreateSet(int $setId, string $setName): MetaTemplateSet
    {
        if (!isset($this->sets[$setName])) {
            $this->sets[$setName] = new MetaTemplateSet($setName);
        }

        $this->setIds[$setName] = $setId;
        return $this->sets[$setName];
    }

    /**
     * Gets the page ID the set belongs to.
     *
     * @return int
     *
     */
    public function getPageId(): int
    {
        return $this->pageId;
    }

    /**
     * Gets the revision ID when the set was last changed.
     *
     * @return int
     *
     */
    public function getRevId(): int
    {
        return $this->revId;
    }

    /**
     * Gets a set by name.
     *
     * @param string $setName The set name to find.
     *
     * @return ?MetaTemplateSet
     */
    public function getSet($setName): ?MetaTemplateSet
    {
        return $this->sets[$setName] ?? null;
    }

    /**
     * Gets a set by ID.
     *
     * @param mixed $setId The set ID to find.
     *
     * @return ?MetaTemplateSet The set, or null if not found.
     *
     */
    public function getSetId($setId): int
    {
        return $this->setIds[$setId] ?? 0;
    }

    /**
     * Gets all sets.
     *
     * @return MetaTemplateSet[]
     *
     */
    public function getSets(): array
    {
        return $this->sets;
    }
}
