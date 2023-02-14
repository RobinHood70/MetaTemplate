<?php

class MetaTemplateVariable
{
	/**
	 * Whether the value should be parsed (for templates and such) after loading.
	 *
	 * @var bool
	 */
	public $parseOnLoad;

	/**
	 * $value
	 *
	 * @var string
	 */
	public $value;

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
}
