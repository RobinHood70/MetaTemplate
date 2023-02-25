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
	 * Whether or not any $variables are unparsed. Used as an optimization to avoid unnecessary checks and loops.
	 * In future, this should default to false and only be set to true if MetaTemplateUnparsedValues exist in
	 * $this->variables.
	 *
	 * @var bool
	 */
	public $hasUnparsed = true;

	/**
	 *
	 *
	 * @var ?string The name of the set.
	 */
	public $name;

	/**
	 * $variables
	 *
	 * @var MetaTemplateVariable[]|string[];
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
	public function __construct(?string $name = null, ?array $variables = [], bool $anyCase = false)
	{
		$this->name = $name;
		$this->anyCase = $anyCase;
		$this->variables = $variables ?? [];
	}

	/**
	 * After running this, all variables will be resolved values instead of MetaTemplateVariables.
	 *
	 * @param PPFrame $frame
	 *
	 * @return void
	 *
	 */
	public function resolveVariables(PPFrame $frame): void
	{
		// Most things that rely on $variables should be converted to run this by default and then only use the
		// straight values after that instead of checking for parseOnLoad. Convert MetaTemplateVariable to
		// MetaTemplateUnparsedValue.
		if (!$this->hasUnparsed) {
			return;
		}

		foreach ($this->variables as $key => &$value) {
			if ($value instanceof MetaTemplateVariable) {
				$value = $value->parseOnLoad
					? $frame->expand($value->value)
					: $value->value;
			}
		}

		unset($value);
		$this->hasUnparsed = false;
	}

	public function toText()
	{
		return ($this->name ? $this->name : '(Main)') . ':' . implode('|', array_keys($this->variables));
	}
}
