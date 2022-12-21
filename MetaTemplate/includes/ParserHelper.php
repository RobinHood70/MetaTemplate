<?php

/**
 * Provides a number of library routines, mostly related to the parser along with a few generic global methods.
 */
class ParserHelper
{
	public const AV_ALWAYS = 'parserhelper-always';

	public const NA_DEBUG = 'parserhelper-debug';
	public const NA_IF = 'parserhelper-if';
	public const NA_IFNOT = 'parserhelper-ifnot';

	/**
	 * Cache for localized magic words.
	 *
	 * @var MagicWordArray
	 */
	private static $mwArray;

	/**
	 * Caches magic words in a static MagicWordArray. This should include any named arguments or argument values that
	 * need to be localized, along with any other magic words not already registered with the parser by other means,
	 * such as parser functions, tags, and so forth.
	 *
	 * @param array $magicWords The magic words to cache.
	 *
	 * @return void
	 *
	 */
	public static function cacheMagicWords(array $magicWords): void
	{
		if (isset(self::$mwArray)) {
			self::$mwArray->addArray($magicWords);
		} else {
			self::$mwArray = new \MagicWordArray($magicWords);
		}
	}

	/**
	 * Checks whether both the `if=` and `ifnot=` conditions have been satisfied.
	 *
	 * @param PPFrame $frame The frame in use.
	 * @param array $magicArgs The magic-word arguments as created by getMagicArgs().
	 *
	 * @return bool True if both conditions (if applicable) have been satisfied; otherwise, false.
	 *
	 */
	public static function checkIfs(PPFrame $frame, array $magicArgs): bool
	{
		return (isset($magicArgs[self::NA_IF])
			? $frame->expand($magicArgs[self::NA_IF])
			: true) &&
			!(isset($magicArgs[self::NA_IFNOT])
				? $frame->expand($magicArgs[self::NA_IFNOT])
				: false);
	}

	public static function error(string $key, ...$args)
	{
		$msg = wfMessage($key)->params($args)->inContentLanguage()->text();
		return '<strong class="error">' . htmlspecialchars($msg) . '</strong>';
	}

	/**
	 * Standardizes debug text formatting for parser functions.
	 *
	 * @param string $output The original text being output.
	 * @param bool $debug Whether to return debug or regular text.
	 *
	 * @return string The modified text.
	 *
	 */
	public static function formatPFForDebug(string $output, bool $debug): array
	{
		return $debug && strlen($output)
			? ['<pre>' . htmlspecialchars($output) . '</pre>', 'noparse' => true]
			: ['text' => $output, 'noparse' => false];
	}

	/**
	 * Standardizes debug text formatting for tags.
	 *
	 * @param string $output The original text being output.
	 * @param bool $debug Whether to return debug or regular text.
	 *
	 * @return string The modified text.
	 *
	 */
	public static function formatTagForDebug(string $output, bool $debug): array
	{
		if (!strlen($output)) {
			return [''];
		}

		$retval = $debug
			? ['<pre>' . htmlspecialchars($output) . '</pre>', 'markerType' => 'nowiki', 'noparse' => false]
			: [$output, 'markerType' => 'none', 'noparse' => false];
		return $retval;
	}

	/**
	 * Returns a string or part node split into a key/value pair, with the key expanded, if necessary, into a string.
	 * The return value is always an array. If the argument is of the wrong type, or isn't a key/value pair, the key
	 * will be returned as null and the value will be the original argument.
	 *
	 * @param PPFrame $frame The frame in use.
	 * @param string|PPNode_Hash_Tree $arg The argument to work on.
	 *
	 * @return array
	 */
	public static function getKeyValue(PPFrame $frame, $arg): array
	{
		if ($arg instanceof PPNode_Hash_Tree && $arg->getName() === 'part') {
			$split = $arg->splitArg();
			$key = empty($split['index']) ? $frame->expand($split['name']) : null;
			return [$key, $split['value']];
		}

		if (is_string($arg)) {
			$split = explode('=', $arg, 2);
			if (count($split) == 2) {
				return [$split[0], $split[1]];
			}
		}

		// This handles both value-only nodes and unexpected values.
		return [null, $arg];
	}

	/**
	 * Splits the standard parser function arguments into recognized parameters and all others.
	 *
	 * @param PPFrame $frame The frame in use.
	 * @param array $args The arguments to search.
	 * @param mixed ...$allowedArgs A list of arguments that should be expanded and returned in the `$magic` portion of
	 * the returned array. All other arguments will be returned in the `$values` portion of the returned array.
	 *
	 * @return array The return value consists of three arrays.
	 * - Magic Arguments: contains the list of any named arguments where the key appears in $allowedArgs. Keys and
	 *   values are pre-expanded under the assumption that they will be needed that way.
	 * - Values: The second array will contain any arguments that are anonymous or were not specified in $allowedArgs.
	 *   Although these are returned unaltered, anything before the first equals sign (if any) will have been expanded
	 *   as part of processing. If there's no equals sign in the value, none of it will have been expanded.
	 * - Duplicates: The final array will contain any key-value pairs where the key was in $allowedArgs but appeared
	 *   more than once. This allows custom handling of duplicates, as is done by #splitargs in Riven, for example.
	 *   Keys will always be expanded and will be unique. The values are an array of unexpanded arguments, and do not
	 *   include the accepted (final) value.
	 *
	 */
	public static function getMagicArgs(PPFrame $frame, array $args = [], string ...$allowedArgs): array
	{
		if (!count($args)) {
			return [[], [], []];
		}

		$magic = [];
		$values = [];
		$dupes = [];
		$allowedArray = new MagicWordArray($allowedArgs);

		// TODO: Should be doable in a forwards direction, with duplicates only added if a key already exists.
		// However, this changes a guaranteed array_reverse (and possibly a second at the end) for having to check
		// isset() for every parameter. Might want to time this and see which performs better.
		$args = array_reverse($args); // Make sure last value gets processed and any others go to $dupes.
		foreach ($args as $arg) {
			[$name, $value] = self::getKeyValue($frame, $arg);
			if (is_null($name)) {
				// RHshow('Add anon: ', $frame->expand($value));
				$values[] = $value;
			} else {
				$name = trim($name);
				$magKey = $allowedArray->matchStartToEnd($name);
				if ($magKey) {
					if (isset($magic[$magKey])) {
						// If a key already exists and is one of the allowed keys, add it to the dupes list. This
						// allows for the possibility of merging the duplicates back into values later on for cases
						// like #splitargs where it may be desirable to have keys for the called template
						// (e.g., separator) that overlap with those of the template.
						if (!isset($dupes[$name])) {
							// TODO: change this to `$dupes[$name][] = $value` to allow for multiple duplicates.
							$dupes[$name] = $value;
						}
					} else {
						$magic[$magKey] = trim($frame->expand($value));
					}
				} else {
					// RHDebug::show('Add fake k=v: ', $frame->expand($arg));
					$values[] = $arg;
				}
			}
		}

		$values = array_reverse($values);
		return [$magic, $values, $dupes];
	}

	/**
	 * Initializes ParserHelper, caching all required magic words.
	 *
	 * @return void
	 */
	public static function init(): void
	{
		self::cacheMagicWords([
			self::NA_DEBUG,
			self::NA_IF,
			self::NA_IFNOT,
		]);
	}

	/**
	 * Determines if the word at a specific key matches a certain value after everything's converted to their
	 * respective IDs.
	 *
	 * @param array $magicArguments The arguments the key can be found in.
	 * @param string $key The key to search for.
	 * @param string $value The value to match with.
	 *
	 * @return bool True if the value at the specifc key was the same as the value specified.
	 *
	 */
	public static function magicKeyEqualsValue(array $magicArguments, string $key, string $value): bool
	{
		$arrayValue = $magicArguments[$key] ?? null;
		return
			!is_null($arrayValue) &&
			VersionHelper::getInstance()->getMagicWord($value)->matchStartToEnd($arrayValue);
	}

	/**
	 * Calls setHook() for all synonyms of a tag.
	 *
	 * @param Parser $parser The parser to register the tag names with.
	 * @param string $id The magic word ID whose synonyms should be registered.
	 * @param callable $callback The function to call when the tag is used.
	 *
	 * @return void
	 *
	 */
	public static function setHookSynonyms(Parser $parser, string $id, callable $callback): void
	{
		$synonyms = VersionHelper::getInstance()->getMagicWord($id)->getSynonyms();
		foreach ($synonyms as $synonym) {
			$parser->setHook($synonym, $callback);
		}
	}

	/**
	 * Splits named arguments from unnamed.
	 *
	 * @param PPFrame $frame The template frame in use.
	 * @param ?array $args The arguments to split.
	 *
	 * @return array An array of arrays, the first element being the named values and the second element being the anonymous values.
	 */
	public static function splitNamedArgs(PPFrame $frame, ?array $args = null): array
	{
		$named = [];
		$unnamed = [];
		if (!empty($args)) {
			// $unnamed[] = $args[0];
			foreach (array_values($args) as $arg) {
				[$name, $value] = self::getKeyValue($frame, $arg);
				if (is_null($name)) {
					$unnamed[] = $value;
				} else {
					$named[(string)$name] = $value;
				}
			}
		}

		return [$named, $unnamed];
	}

	/**
	 * Transforms tag attributes so that only wanted elements are present and are represented by their qqq key rather
	 * than the language-specific word.
	 *
	 * @param array $attributes The attributes to transform.
	 * @param ?MagicWordArray The MagicWordArray to compare against. Defaults to previously registered magic words.
	 *
	 * @return array The filtered array.
	 *
	 */
	public static function transformAttributes(array $attributes, ?MagicWordArray $magicWords = null): array
	{
		$magicWords = $magicWords ?? self::$mwArray;
		$retval = [];
		foreach ($attributes as $key => $value) {
			$match = $magicWords->matchStartToEnd($key);
			if ($match) {
				$retval[$match] = $value;
			}
		}

		return $retval;
	}
}
