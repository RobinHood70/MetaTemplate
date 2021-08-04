<?php

class MetaTemplateSet
{
    /**
     * $setName
     *
     * @var string
     */
    private $setName;

    /**
     * $variables
     *
     * @var MetaTemplateVariable[];
     */
    private $variables;

    public function __construct($setName)
    {
        $this->setName = $setName;
    }

    public function addVariables(array $data)
    {
        foreach ($data as $key => $value) {
            $this->variables[$key] = $value;
        }
    }

    public function addVariable($varName, $value, $parsed = true)
    {
        if (!isset($this->variables[$varName])) {
            $this->variables[$varName] = new MetaTemplateVariable($value, $parsed);
        }
    }

    public function getSetName()
    {
        return $this->setName;
    }

    public function &getVariables()
    {
        return $this->variables;
    }
}
