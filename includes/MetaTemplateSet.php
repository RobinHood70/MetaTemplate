<?php

class MetaTemplateSet
{
    public function __construct($setId)
    {
        $this->setId = $setId;
    }

    /**
     * $setId
     *
     * @var int
     */
    public $setId;

    /**
     * $variables
     *
     * @var MetaTemplateVariable[];
     */
    public $variables;

    public function addSubset(array $data)
    {
        foreach ($data as $key => $value) {
            $this->variables[$key] = $value;
        }
    }

    public function addVar($varName, $value, $parsed = true)
    {
        $this->variables[$varName] = new MetaTemplateVariable($value, $parsed);
    }
}
