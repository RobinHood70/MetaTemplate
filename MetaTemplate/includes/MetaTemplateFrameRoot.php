<?php

/**
 * UPDATE INSTRUCTIONS: Updating this file should rarely be necessary, but if you wish to do so:
 *
 *	1. Copy PPFrame_Hash from MediaWiki's includes/parser/ folder. In modern versions, the file is named the same as
 *     the class; in older versions, it's bundled into Preprocessor_Hash.php.
 *  2. Remove all properties and methods except $volatile, $ttl, the constructor, cachedExpand(), isTemplate(),
 *     setTTL() and setVolatile().
 *	3. Rename the class to "MetaTemplateFrameRoot" in the class header and have it extend PPTemplateFrame_Hash.
 *	4. Copy getNamedArgument() and getNumberedArgument() (the singular ones only) from PPTemplateFrame_Hash.
 *	5. Only in those two functions, replace "$this->parent" with "$this". This allows a page to hold variables set by
 *     #define and its offshoots) as though it had been transcluded.
 */

/**
 * Expansion frame with template arguments. Overrides MediaWiki default so it can be used to preview with arguments in
 * root space (i.e., while previewing or viewing a template page or setting variables on a page that's not
 * transcluded).
 *
 * @internal Note that while this extends PPTemplateFrame_Hash, it's actually used as a PPFrame_Hash and returned via
 * the custom preprocessor's newFrame() override.
 *
 * @ingroup Parser
 */

class MetaTemplateFrameRoot extends PPTemplateFrame_Hash
{
	private $volatile = false;
	private $ttl = null;

	/**
	 * Creates an instance of MetaTemplateFrameRoot.
	 *
	 * @param Preprocessor_Hash $preprocessor The preprocessor this frame will be used with.
	 *
	 */
	public function __construct(Preprocessor_Hash $preprocessor)
	{
		$this->preprocessor = $preprocessor;
		$this->parser = $preprocessor->parser;
		$this->title = $this->parser->mTitle;
		$this->titleCache = [$this->title ? $this->title->getPrefixedDBkey() : false];
		$this->loopCheckHash = [];
		$this->depth = 0;
		$this->childExpansionCache = [];

		$this->parent = null;
		$this->numberedArgs = [];
		$this->namedArgs = [];
		$this->numberedExpansionCache = [];
		$this->namedExpansionCache = [];
	}

	/**
	 * Normally, gets an expanded value from the cache. Since MetaTemplateFrameRoot will always be a root page, this is
	 * a standard expand rather than trying to access the parent's cache.
	 *
	 * @param string|int $key The parameter to expand.
	 * @param string|PPNode $root The value of the parameter node to expand.
	 * @param int $flags Limitations on what to expand.
	 *
	 * @return string The expanded value.
	 *
	 */
	public function cachedExpand($key, $root, $flags = 0)
	{
		// We don't have a parent, so we don't have a cache; just do a regular expand.
		return $this->expand($root, $flags);
	}

	/**
	 * Gets a numbered argument from the template parameters.
	 *
	 * @param int $index The number of the argument to retrieve.
	 *
	 * @return string|bool The value of the argument or false if not found.
	 *
	 */
	public function getNumberedArgument($index)
	{
		if (!isset($this->numberedArgs[$index])) {
			return false;
		}

		if (!isset($this->numberedExpansionCache[$index])) {
			# No trimming for unnamed arguments
			$this->numberedExpansionCache[$index] = $this->expand(
				$this->numberedArgs[$index],
				self::STRIP_COMMENTS
			);
		}

		return $this->numberedExpansionCache[$index];
	}

	/**
	 * [Description for getNamedArgument]
	 *
	 * @param int|string $name The index or name of the argument to retrieve.
	 *
	 * @return string|bool The value of the argument or false if not found.
	 *
	 */
	public function getNamedArgument($name)
	{
		if (!isset($this->namedArgs[$name])) {
			return false;
		}

		if (!isset($this->namedExpansionCache[$name])) {
			# Trim named arguments post-expand, for backwards compatibility
			$this->namedExpansionCache[$name] = trim($this->expand(
				$this->namedArgs[$name],
				self::STRIP_COMMENTS
			));
		}

		return $this->namedExpansionCache[$name];
	}

	/**
	 * Get the TTL.
	 *
	 * @return int|null The time-to-live value.
	 *
	 */
	public function getTTL(): ?int
	{
		return $this->ttl;
	}

	/**
	 * Return true if the frame is a template frame.
	 *
	 * @return bool Always false for this frame type.
	 *
	 */
	public function isTemplate(): bool
	{
		return false;
	}

	/**
	 * Get the volatile flag.
	 *
	 * @return bool
	 *
	 */
	public function isVolatile(): bool
	{
		return $this->volatile;
	}

	/**
	 * Set the TTL.
	 *
	 * @param int $ttl The time that the frame should remain cached. (Note: this appears to be unused by anything.)
	 *
	 * @return void
	 *
	 */
	public function setTTL($ttl): void
	{
		if (
			!is_null($ttl) &&
			(is_null($this->ttl) || $ttl < $this->ttl)
		) {
			$this->ttl = $ttl;
		}
	}

	/**
	 * Set the volatile flag
	 *
	 * @param bool $flag Whether the frame is volatile.
	 *
	 * @return void
	 *
	 */
	public function setVolatile($flag = true): void
	{
		$this->volatile = $flag;
		$this->parser->getOutput()->updateCacheExpiry(0);
	}
}
