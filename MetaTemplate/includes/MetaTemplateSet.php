<?php

class MetaTemplateSet
{
    /**
     * Whether the set should allow case-insensitive compares.
     * @internal It's useful to have this travel with the set when used alongside #load().
     *
     * @var bool
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
    public function __construct(?string $setName = null, ?array $variables = [], bool $anyCase = false)
    {
        $this->setName = $setName;
        $this->anyCase = $anyCase;
        $this->variables = $variables;
    }

    public function toText()
    {
        return ($this->setName ? $this->setName : '(Main)') . ':' . implode('|', array_keys($this->variables));
    }
}
