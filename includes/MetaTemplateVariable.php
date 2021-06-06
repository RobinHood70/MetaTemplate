<?php

class MetaTemplateVariable
{
    public function __construct($value, $parsed)
    {
        $this->value = $value;
        $this->parsed = $parsed;
    }

    /**
     * $value
     *
     * @var PPNode_Hash_Tree
     */
    public $value;

    /**
     * $parsed
     *
     * @var bool
     */
    public $parsed;
}
