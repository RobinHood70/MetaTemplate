<?php

class MetaTemplateVariable
{
    /**
     * $parsed
     *
     * @var bool
     */
    private $parsed;

    /**
     * $value
     *
     * @var PPNode_Hash_Tree
     */
    private $value;

    public function __construct($value, $parsed)
    {
        $this->parsed = $parsed;
        $this->value = $value;
    }

    public function getParsed()
    {
        return $this->parsed;
    }

    public function getValue()
    {
        return $this->value;
    }
}
