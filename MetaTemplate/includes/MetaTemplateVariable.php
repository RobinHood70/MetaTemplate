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
     * @var string
     */
    private $value;

    /**
     * Creates a new instance of the MetaTemplateVariable class to store a variable's data.
     *
     * @param mixed $value The value to store.
     * @param bool $parseOnLoad Whether the value needs to be parsed when loaded.
     *
     */
    public function __construct(string $value, bool $parseOnLoad)
    {
        $this->parseOnLoad = $parseOnLoad;
        $this->value = $value;
    }

    /**
     * Gets whether the value in this instance needs to be parsed.
     *
     * @return bool Whether the value in this instance needs to be parsed.
     *
     */
    public function getParseOnLoad(): bool
    {
        return $this->parseOnLoad;
    }

    /**
     * The variable's value.
     *
     * @return mixed
     *
     */
    public function getValue()
    {
        return $this->value;
    }
}
