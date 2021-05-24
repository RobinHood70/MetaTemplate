<?php

class MetaTemplateVariable
{

    public function __construct($data, $parsed)
    {
        $this->data = $data;
        $this->parsed = $parsed;
    }

    /**
     * $value
     *
     * @var PPNode_Hash_Tree
     */
    public $data;

    /**
     * $parsed
     *
     * @var bool
     */
    public $parsed;
}
