<?php
/*
namespace RobinHood70\Extension\ParserHelper;
*/

function alert($msg)
{
	echo "<script>alert(\"$msg\")</script>";
}

function show(...$msgs)
{
	echo '<pre>';
	foreach ($msgs as $msg) {
		if ($msg) {
			print_r($msg);
		}
	}

	echo '</pre>';
}

function showQuery(IDatabase $db, ResultWrapper $result)
{
	// MW 1.28+: $db = $result->getDB();
	show($db->lastQuery() . "\n\n" . $db->numRows($result) . ' rows returned.');
}

function writeFile($file, $msg)
{
	$handle = fopen($file, 'a') or die("Cannot open file: $file");
	$msg2 = print_r($msg, true);
	fwrite($handle, $msg2);
	fclose($handle);
}

/**
 * [Description ParserHelper]
 */
class ParserHelper
{
	const AV_ANY = 'parserhelper-any';
	const AV_ALWAYS = 'parserhelper-always';
	const NA_CASE = 'parserhelper-case';
	const NA_IF = 'parserhelper-if';
	const NA_IFNOT = 'parserhelper-ifnot';
	const NA_NSBASE = 'namespaceinfo-ns_base';
	const NA_NSID = 'namespaceinfo-ns_id';

	private static $allMagicWords = [
		self::AV_ANY,
		self::AV_ALWAYS,
		self::NA_CASE,
		self::NA_IF,
		self::NA_IFNOT,
		self::NA_NSBASE,
		self::NA_NSID,
	];

	/**
	 * @var string[][]
	 */
	private static $lmw = [];

	/**
	 * @param array $array
	 * @param mixed $key
	 * @param null $default
	 *
	 * @return mixed
	 */
	public static function arrayGet(array $array, $key, $default = null)
	{
		return (isset($array[$key]) || array_key_exists($key, $array)) ? $array[$key] : $default;
	}

	/**
	 * @param string[] $magicWords
	 *
	 * @return void
	 */
	public static function cacheMagicWords(array $magicWords)
	{
		foreach ($magicWords as $magicWord) {
			self::$lmw[$magicWord] = MagicWord::get($magicWord)->getSynonyms();
		}
	}

	/**
	 * checkAnyCase
	 *
	 * @param array $magicArgs
	 *
	 * @return bool
	 */
	public static function checkAnyCase(array $magicArgs)
	{
		$caseValue = self::arrayGet($magicArgs, self::NA_CASE);
		return in_array($caseValue, self::getMagicWordNames(self::AV_ANY));
	}

	// IMP: if and ifnot can co-exist; both must be satisfied to proceed.
	/**
	 * checkIfs
	 *
	 * @param array $magicArgs
	 *
	 * @return bool
	 */
	public static function checkIfs(array $magicArgs)
	{
		return
			self::arrayGet($magicArgs, self::NA_IF, true) &&
			!self::arrayGet($magicArgs, self::NA_IFNOT, false);
	}

	public static function expandAll(PPFrame $frame, ...$values)
	{
		$retval = [];
		foreach ($values as $value) {
			$retval[] = $frame->expand($value);
		}

		return $retval;
	}

	public static function expandArray(PPFrame $frame, array $values, $count = 0, $flags = 0)
	{
		$retval = [];
		if ($count == 0) {
			$count = count($values);
		}

		foreach ($values as $value) {
			$retval[] = $frame->expand($value, $flags);
			$count--;
			if ($count <= 0) {
				break;
			}
		}

		return $retval;
	}

	// Returns an associative array of the allowable named arguments and their expanded values. All other values,
	// including unrecognized named arguments, will be returned under the VALUES_KEY key.
	/**
	 * getMagicArgs
	 *
	 * @param PPFrame $frame
	 * @param array $args
	 * @param mixed ...$allowedArgs
	 *
	 * @return array
	 */
	public static function getMagicArgs(PPFrame $frame, array $args = [], ...$allowedArgs)
	{
		$magic = [];
		$values = [];
		if (count($args) && count($allowedArgs)) {
			for ($i = count($args) - 1; $i >= 0; $i--) {
				$arg = $args[$i];
				list($name, $value) = self::getKeyValue($frame, $arg);
				if (is_null($name)) {
					$values[] = $value;
				} else {
					$found = false;
					foreach ($allowedArgs as $trKey => $magKey) {
						$allWords = self::$lmw[$magKey];
						if (in_array($name, $allWords)) {
							$found = true;
							$magic[$magKey] = $frame->expand($value);
							unset($allowedArgs[$trKey]);
							if (count($allowedArgs) == 0) {
								break 2;
							}

							break;
						}
					}

					if (!$found) {
						$values[] = $arg;
					}
				}
			}
		}

		$values = array_reverse($values);
		return [$magic, $values];
	}

	/**
	 * getMagicWordNames
	 *
	 * @param mixed $words
	 *
	 * @return string[]
	 */
	public static function getMagicWordNames($words)
	{
		if (is_array($words)) {
			$retval = [];
			foreach ($words as $word) {
				if (array_key_exists($word, self::$lmw)) {
					$retval =  array_merge($retval, self::$lmw[$word]);
				}
			}

			return $retval;
		} else {
			return self::$lmw[$words];
		}
	}

	/**
	 * Returns a string or part node split into a key/value pair, with the key expanded, if necessary, into a string.
	 * The return value is always an array. If the argument is of the wrong type, or isn't a key/value pair, the key
	 * will be returned as null and the value will be the original argument.
	 *
	 * @param PPFrame $frame
	 * @param string|PPNode_Hash_Tree $arg
	 *
	 * @return array
	 */
	public static function getKeyValue(PPFrame $frame, $arg)
	{
		if (is_string($arg)) {
			$split = explode('=', $arg, 2);
			if (count($split) == 2) {
				return [$split[0], $split[1]];
			}
		} elseif ($arg instanceof PPNode_Hash_Tree && $arg->getName() === 'part') {
			$split = $arg->splitArg();
			$indexNode = $split['index'];
			$key = strlen($indexNode) ? null : $frame->expand($split['name']);
			return [$key, $split['value']];
		}

		// This handles both value-only nodes and unexpected values.
		return [null, $arg];
	}

	/**
	 * init
	 *
	 * @return void
	 */
	public static function init()
	{
		self::cacheMagicWords(self::$allMagicWords);
	}

	/**
	 * nullCoalesce
	 *
	 * @param mixed ...$args
	 *
	 * @return mixed|null
	 */
	public static function nullCoalesce(...$args)
	{
		foreach ($args as $arg) {
			if (!is_null($arg)) {
				return $arg;
			}
		}

		return null;
	}
}
