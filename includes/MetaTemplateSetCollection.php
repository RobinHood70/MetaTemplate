<?php

class MetaTemplateSetCollection
{
    public $pageId;
    public $revId;

    /**
     * $sets
     *
     * @var MetaTemplateSet[]
     */
    public $sets = [];

    public function __construct($pageId, $revId)
    {
        $this->pageId = $pageId;
        $this->revId = $revId;
    }

    /**
     * addSet
     *
     * @param mixed $subsetName
     *
     * @return MetaTemplateSet
     */
    public function getOrAddSet($subsetName, $revId)
    {
        if (!isset($this->sets[$subsetName])) {
            $this->sets[$subsetName] = new MetaTemplateSet($revId);
        }

        return $this->sets[$subsetName];
    }
}
