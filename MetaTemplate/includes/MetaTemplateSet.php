<?php

class MetaTemplateSet
{
    /**
     * Whether the set should allow case-insensitive compares.
     * @internal It's useful to have this travel with the set when used alongside #load().
     *
     * @var ?bool
     */
    public $anyCase;

    /**
     * $setName
     *
     * @var ?string
     */
    public $setName;

    /**
     * $variables
     *
     * @var MetaTemplateVariable[];
     *
     */
    public $variables = [];

    /**
     * Creates an instance of the MetaTemplateSet class.
     *
     * @param mixed $setName The name of the set to create.
     *
     */
    public function __construct(?string $setName = null, ?array $variables = [], ?bool $anyCase = null)
    {
        $this->setName = $setName;
        $this->addVariables($variables);
        $this->anyCase = $anyCase;
    }

    /**
     * Add an entire associated array to the list of variables.
     *
     * @param MetaTemplateVariable[] $data The data to add. This should be a string => MetaTemplateVariable array.
     *
     * @return void
     *
     */
    public function addVariables(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!($value instanceof MetaTemplateVariable)) {
                throw new Exception('Invalid variable.');
            }

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
    public function addVariable($name, $value, $parseOnLoad): void
    {
        if (!isset($this->variables[$name])) {
            $this->variables[$name] = new MetaTemplateVariable($value, $parseOnLoad);
        }
    }
}
