<?php

class MetaTemplateSet
{
	/**
	 *
	 *
	 * @var ?string The name of the set.
	 */
	public $name;

	/**
	 * $variables
	 *
	 * @var array[]|string[];
	 *
	 */
	public $variables = [];

	/**
	 * Creates an instance of the MetaTemplateSet class.
	 *
	 * @param ?string $name The name of the set to create.
	 * @param ?string[] $variables Any variables to pre-initialize the set with.
	 * @param bool $anyCase
	 *
	 */
	public function __construct(?string $name = null, ?array $variables = [])
	{
		$this->name = $name;
		$this->variables = $variables ?? [];
	}
}
