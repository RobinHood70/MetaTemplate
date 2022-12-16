<?php

class MetaTemplateSetCollection
{
    public $pageId;
    public $revId;

    /**
     * All sets on the page.
     *
     * @var MetaTemplateSet[]
     */
    public $sets = [];

    /**
     * All set IDs.
     *
     * @var int[]
     */
    public $setIds = []; // We mostly want to ignore the IDs in any operations, except when it comes to the final upserts, so we store them separately.

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
     * Gets a set by name if it exists or creates one if it doesn't.
     *
     * @param int $setId The set ID. If set to zero, this will be
     * @param string $setName
     *
     * @return MetaTemplateSet
     *
     */
    public function addToSet(int $setId, string $setName, ?array $variables = null): MetaTemplateSet
    {
        $this->setIds[$setName] = $setId;
        if (isset($this->sets[$setName])) {
            $retval = $this->sets[$setName];
            if ($variables !== null) {
                $retval->addVariables($variables);
            }
        } else {
            $retval = new MetaTemplateSet($setName, $variables);
            $this->sets[$setName] = $retval;
        }

        return $retval;
    }
}
