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
	 * $setName
	 *
	 * @var ?string
	 */
	public $setName;

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
	 * @param mixed $setName The name of the set to create.
	 *
	 */
	public function __construct(?string $setName = null, ?array $variables = [], bool $anyCase = false)
	{
		$this->setName = $setName;
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
			if (($value instanceof MetaTemplateVariable)) {
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
		return ($this->setName ? $this->setName : '(Main)') . ':' . implode('|', array_keys($this->variables));
	}
}
