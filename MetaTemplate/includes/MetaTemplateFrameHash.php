<?php

/**
 * UPDATE INSTRUCTIONS: Updating this file should rarely be necessary, but if you wish to do so:
 *
 *	1. Copy PPFrame_Hash from MediaWiki's includes/parser/ folder. In modern versions, the file is named the same as
 *     the class; in older versions, it's bundled into Preprocessor_Hash.php.
 *  2. Remove all properties and methods except the constructor, cachedExpand(), isTemplate(), setTTL() and
 *     setVolatile().
 *	3. Rename the class to "MetaTemplateFrameHash" in the class header and have it extend PPTemplateFrame_Hash.
 *	4. Copy getNamedArgument() and getNumberedArgument() (the singular ones only) from PPTemplateFrame_Hash.
 *	5. Only in those two functions, replace "$this->parent" with "$this".
 *
 *  (In the alternative, you can extend PPFrame and merge in all argument-related properties and functions. This has
 *  the advantage of the inhertance nesting being lower and the cost of being a more complex merge. In addition, you
 *  lose the ability to use constructs such as "instanceof PPTemplateFrame_Hash" to detect either PPTemplateFrame_Hash
 *  or MetaTemplateFrameHash.)
 */

/**
 * Expansion frame with template arguments. Overrides MediaWiki default so it can be used to preview with arguments in
 * root space (i.e., while previewing or viewing a template page or setting variables on a page that's not
 * transcluded). To create the MetaTemplateFramehash, extend PPTemplateFrame_Hash and then override any function that
 * refers to $this->parent without doing an isset on it first, then remove that part of the function since parent will
 * always be null for this. In essence, this allows a page to display as though it had been called with specific
 * arguments or to hold values declared by #preview, #define, and #local.
 *
 * @ingroup Parser
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
class MetaTemplateFrameHash extends PPTemplateFrame_Hash
{
	private $volatile = false;
	private $ttl = null;

	/**
	 * @param Preprocessor $preprocessor
	 */
	public function __construct(Preprocessor $preprocessor)
	{
		$this->preprocessor = $preprocessor;
		$this->parser = $preprocessor->parser;
		$title = $this->parser->mTitle;
		$this->loopCheckHash = [];
		if ($title) {
			$this->title = $title;
			$this->titleCache = [$title->getPrefixedDBkey()];
			$pdbk = $title->getPrefixedDBkey();
			$this->loopCheckHash[$pdbk] = true;
		} else {
			throw new MWException(__METHOD__ . ': root node must always have a title.');
		}

		$this->depth = 0;
		$this->childExpansionCache = [];
		$this->parent = false;
		$this->numberedArgs = [];
		$this->namedArgs = [];
		$this->numberedExpansionCache = [];
		$this->namedExpansionCache = [];
	}

	/**
	 * @throws MWException
	 * @param string|int $key
	 * @param string|PPNode $root
	 * @param int $flags
	 * @return string
	 */
	public function cachedExpand($key, $root, $flags = 0)
	{
		// we don't have a parent, so we don't have a cache
		return $this->expand($root, $flags);
	}

	/**
	 * @param int $index
	 * @return string|bool
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
				PPFrame::STRIP_COMMENTS
			);
		}

		return $this->numberedExpansionCache[$index];
	}

	/**
	 * @param string $name
	 * @return string|bool
	 */
	public function getNamedArgument($name)
	{
		if (!isset($this->namedArgs[$name])) {
			return false;
		}

		if (!isset($this->namedExpansionCache[$name])) {
			# Trim named arguments post-expand, for backwards compatibility
			$this->namedExpansionCache[$name] = trim(
				$this->expand($this->namedArgs[$name], PPFrame::STRIP_COMMENTS)
			);
		}

		return $this->namedExpansionCache[$name];
	}

	/**
	 * Get the TTL
	 *
	 * @return int|null
	 */
	public function getTTL()
	{
		return $this->ttl;
	}

	/**
	 * Return true if the frame is a template frame
	 *
	 * @return bool
	 */
	public function isTemplate()
	{
		return false;
	}

	/**
	 * Get the volatile flag
	 *
	 * @return bool
	 */
	public function isVolatile()
	{
		return $this->volatile;
	}

	/**
	 * Set the TTL
	 *
	 * @param int $ttl
	 */
	public function setTTL($ttl)
	{
		if ($ttl !== null && ($this->ttl === null || $ttl < $this->ttl)) {
			$this->ttl = $ttl;
		}
	}

	/**
	 * Set the volatile flag
	 *
	 * @param bool $flag
	 */
	public function setVolatile($flag = true)
	{
		$this->volatile = $flag;
		$this->parser->disableCache();
	}
}
