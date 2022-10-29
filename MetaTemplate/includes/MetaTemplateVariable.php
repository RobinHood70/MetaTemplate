<?php

class MetaTemplateVariable
{
    /**
     * Whether the value should be parsed (for templates and such) after loading.
     *
     * @var bool
     */
    private $parseOnLoad;

    /**
     * $value
     *
     * @var PPNode_Hash_Tree
     */
    private $value;

    public function __construct($value, bool $parseOnLoad)
    {
        $this->parseOnLoad = $parseOnLoad;
        $this->value = $value;
    }

    public function getParseOnLoad()
    {
        return $this->parseOnLoad;
    }

    public function getValue()
    {
        return $this->value;
    }
}
