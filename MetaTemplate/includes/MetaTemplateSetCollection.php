<?php

class MetaTemplateSetCollection
{
    private $pageId;
    private $revId;

    /**
     * $sets
     *
     * @var MetaTemplateSet[]
     */
    private $sets = [];

    private $setIds = []; // We mostly want to ignore the IDs in any operations, except when it comes to the final upserts, so we store them separately.

    public function __construct($pageId, $revId)
    {
        $this->pageId = $pageId;
        $this->revId = $revId;
    }

    public function isEmpty()
    {
        return empty($this->sets);
    }

    /**
     * addSet
     *
     * @param mixed $setName
     *
     * @return MetaTemplateSet
     */
    public function getOrCreateSet($setId, $setName)
    {
        $this->setIds[$setName] = $setId;
        if (!isset($this->sets[$setName])) {
            $this->sets[$setName] = new MetaTemplateSet($setName);
        }

        return $this->sets[$setName];
    }

    public function getPageId()
    {
        return $this->pageId;
    }

    public function getRevId()
    {
        return $this->revId;
    }

    /**
     * getSet
     *
     * @param string $setName
     *
     * @return MetaTemplateSet|bool
     */
    public function getSet($setName)
    {
        return ParserHelper::getInstance()->arrayGet($this->sets, $setName, false);
    }

    public function getSetId($setName)
    {
        return ParserHelper::getInstance()->arrayGet($this->setIds, $setName, 0);
    }

    public function getSets()
    {
        return $this->sets;
    }
}
