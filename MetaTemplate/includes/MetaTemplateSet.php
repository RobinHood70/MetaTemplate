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
     *
     */
    private $variables;

    /**
     * Creates an instance of the MetaTemplateSet class.
     *
     * @param mixed $setName The name of the set to create.
     *
     */
    public function __construct($setName)
    {
        $this->setName = $setName;
    }

    /**
     * Add an entire associated array to the list of variables.
     *
     * @param array $data The data to add. This should be a string => MetaTemplateVariable array.
     *
     * @return void
     *
     */
    public function addVariables(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->variables[$key] = $value;
        }
    }

    /**
     * Adds a new variable to the set with the specified values.
     *
     * @param mixed $varName The name of the variable to add.
     * @param mixed $value The value of the variable.
     * @param bool $parseOnLoad Whether the value should be parsed after loading it.
     *
     * @return void
     *
     */
    public function addVariable($varName, $value, $parseOnLoad): void
    {
        if (!isset($this->variables[$varName])) {
            $this->variables[$varName] = new MetaTemplateVariable($value, $parseOnLoad);
        }
    }

    /**
     * Gets the name of the set.
     *
     * @return string
     *
     */
    public function getSetName(): string
    {
        return $this->setName;
    }

    /**
     * Gets the entire list of variables for the set.
     *
     * @return MetaTemplateVariable[]
     *
     */
    public function getVariables(): array
    {
        return $this->variables;
    }
}
