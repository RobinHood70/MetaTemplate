<?php

use MediaWiki\MediaWikiServices;

/**
 * An extension to add data persistence and variable manipulation to MediaWiki.
 *
 * At this point, the code could easily be split into four separate extensions based on the SSTNG constants, but at as
 * they're likely to all be used together, with the possible exception of the Define group as of MW 1.35, it seems
 * easier to keep them together for easier maintenance.
 */
class MetaTemplate
{
	#region Public Constants
	public const AV_ANY = 'metatemplate-any';

	public const KEY_METATEMPLATE = '@metatemplate';

	public const NA_CASE = 'metatemplate-case';
	public const NA_FULLPAGENAME = 'metatemplate-fullpagename';
	public const NA_PAGEID = 'metatemplate-pageid';
	public const NA_PAGENAME = 'metatemplate-pagename';
	public const NA_SHIFT = 'metatemplate-shift';

	public const PF_DEFINE = 'metatemplate-define';
	public const PF_FULLPAGENAMEx = 'metatemplate-fullpagenamex';
	public const PF_INHERIT = 'metatemplate-inherit';
	public const PF_LOCAL = 'metatemplate-local';
	public const PF_NAMESPACEx = 'metatemplate-namespacex';
	public const PF_PAGENAMEx = 'metatemplate-pagenamex';
	public const PF_PREVIEW = 'metatemplate-preview';
	public const PF_RETURN = 'metatemplate-return';
	public const PF_UNSET = 'metatemplate-unset';

	public const STTNG_ENABLECPT = 'EnableCatPageTemplate';
	public const STTNG_ENABLEDATA = 'EnableData';
	public const STTNG_ENABLEDEFINE = 'EnableDefine';
	public const STTNG_ENABLEPAGENAMES = 'EnablePageNames';

	public const VR_FULLPAGENAME0 = 'metatemplate-fullpagename0';
	public const VR_NAMESPACE0 = 'metatemplate-namespace0';
	public const VR_NESTLEVEL = 'metatemplate-nestlevel';
	public const VR_NESTLEVEL_VAR = 'metatemplate-nestlevel-var';
	public const VR_PAGENAME0 = 'metatemplate-pagename0';
	#endregion

	/** @var ?string */
	public static $mwFullPageName = null;

	/** @var ?string */
	public static $mwNamespace = null;

	#region Public Static Variables
	/** @var ?string */
	public static $mwPageId = null;

	/** @var ?string */
	public static $mwPageName = null;

	/** @var ?string */
	public static $mwSet = null;
	#endregion

	#region Private Static Variables
	private static $config;
	private static $varExpandFlags;

	/**
	 * An array of strings containing the names of parameters that should be passed through to a template, even if
	 * displayed on its own page.
	 *
	 * @var array $bypassVars // @ var MagicWordArray
	 */
	private static $bypassVars = null;
	#endregion

	#region Public Static Functions
	/**
	 * Substitutes values for all {{{arguments}}}.
	 *
	 * @param PPFrame $frame
	 * @param PPNode|string $dom
	 *
	 * @return string
	 *
	 */
	public static function argSubtitution(PPFrame $frame, $dom, int $flags): string
	{
		// This would have been easier with regex, but regex could mess up on wiki syntax where this can't.
		$retval = $frame->expand($dom, $flags);
		/** @var ?string $retval */
		$retval = VersionHelper::getInstance()->getStripState($frame->parser)->unstripBoth($retval);
		// We may have leftover arguments, so re-DOM it to figure out if they're real and if so, surround them with
		// <nowiki> tags.
		/** @var Parser $parser */
		$dom = $frame->parser->preprocessToDom($retval);
		$dom = self::argRecurse($frame, $dom->getRawChildren());
		$dom = new PPNode_Hash_Tree([array_merge(['value'], [$dom])], 0);
		$retval = $frame->expand($dom, $flags);

		return $retval;
	}

	private static function argRecurse(PPFrame $frame, array $children): array
	{
		$newNodes = [];
		foreach ($children as $node) {
			if (is_array($node)) {
				if (count($node) && $node[0] === 'tplarg') {
					$newText = $frame->expand(PPNode_Hash_Tree::factory($node[1], 0), PPFrame::NO_ARGS);
					// We insert this as a text node instead of an 'ext' hash so it doesn't get processed as a "real" nowiki, which would ultimately lead us right back to our starting point, interpreting the value as not having tags at all.
					$newNodes[] = new PPNode_Hash_Text(['<nowiki>{{{' . $newText . '}}}</nowiki>'], 0);
				} else {
					$newNodes[] = self::argRecurse($frame, $node);
				}
			} else {
				$newNodes[] = $node;
			}
		}

		return $newNodes;
	}

	/**
	 * Checks the `case` parameter to see if it matches `case=any` or any of the localized equivalents.
	 *
	 * @param array $magicArgs The magic-word arguments as created by getMagicArgs().
	 *
	 * @return bool True if `case=any` or any localized equivalent was found in the argument list.
	 */
	public static function checkAnyCase(array $magicArgs): bool
	{
		return ParserHelper::magicKeyEqualsValue($magicArgs, self::NA_CASE, self::AV_ANY);
	}

	/**
	 * @return GlobalVarConfig The global variable configuration for MetaTemplate.
	 */
	public static function configBuilder(): GlobalVarConfig
	{
		return new GlobalVarConfig('metatemplate');
	}

	/**
	 * Sets the value of a variable if it has not already been set. This is most often done to provide a default value
	 * for a parameter if it was not passed in the template call.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments:
	 *         1: The variable name.
	 *         2: The variable value.
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doDefine(Parser $parser, PPFrame $frame, array $args): void
	{
		// Show {{{parameter names}}} if on the actual template page and not previewing, but allow bypass variables
		// like ns_base/ns_id through at all times.
		if (!$frame->parent && $parser->getTitle()->getNamespace() === NS_TEMPLATE && !$parser->getOptions()->getIsPreview()) {
			if (!isset(self::$bypassVars)) {
				self::getBypassVariables();
			}

			$varName = trim($frame->expand($args[0]));
			if (!isset(self::$bypassVars[$varName])) {
				return;
			}
		}

		self::checkAndSetVar($frame, $args, false);
	}

	/**
	 * Gets the full page name at a given point in the stack.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments:
	 *     depth: The stack depth to check.
	 *
	 * @return string The requested full page name.
	 */
	public static function doFullPageNameX(Parser $parser, PPFrame $frame, ?array $args): string
	{
		$title = self::getTitleAtDepth($parser, $frame, $args);
		return is_null($title) ? '' : $title->getPrefixedText();
	}

	/**
	 * Inherit variables from the calling template(s).
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *        1+: The variable(s) to unset.
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doInherit(Parser $parser, PPFrame $frame, array $args)
	{
		if (!$frame->depth) {
			return;
		}

		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			ParserHelper::NA_DEBUG,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_CASE
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		if (!$values || !ParserHelper::checkIfs($frame, $magicArgs)) {
			return;
		}

		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
		$translations = self::getVariableTranslations($frame, $values);
		$inherited = [];
		foreach ($translations as $srcName => $destName) {
			$varValue =
				$frame->numberedArgs[$srcName] ??
				$frame->namedArgs[$srcName] ??
				self::inheritVar($frame, $srcName, $destName, self::checkAnyCase($magicArgs));
			if ($varValue && $debug) {
				$inherited[] = "$destName=$varValue";
			}
		}

		if ($debug) {
			$varList = implode("\n", $inherited);
			return ParserHelper::formatPFForDebug($varList, true, false, 'Inherited Variables');
		}

		return '';
	}

	/**
	 * Sets the value of a variable. This is most often used to create local variables or modify a template parameter's
	 * value. Any previous value will be overwritten.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The template frame in use.
	 * @param array $args Function arguments:
	 *         1: The variable name.
	 *         2: The variable value.
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doLocal(Parser $parser, PPFrame $frame, array $args): void
	{
		/** @var PPTemplateFrame_Hash $frame */
		self::checkAndSetVar($frame, $args, true);
	}

	/**
	 * Gets the namespace at a given point in the stack.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments:
	 *     depth: The stack depth to check.
	 *
	 * @return string The requested namespace.
	 */
	public static function doNamespaceX(Parser $parser, PPFrame $frame, ?array $args): string
	{
		$title = self::getTitleAtDepth($parser, $frame, $args);
		$nsName = $parser->getFunctionLang()->getNsText($title->getNamespace());
		return is_null($title) ? '' : str_replace('_', ' ', $nsName);
	}

	/**
	 * Gets the template stack depth.
	 *
	 * For example, if a page calls template {{A}} which in turn calls template {{B}}, then {{NESTLEVEL}} would report:
	 *     0 if used on the page itself,
	 *     1 if used in {{A}},
	 *     2 if used in {{B}}.
	 *
	 * @param PPFrame $frame The frame in use.
	 *
	 * @return int The frame depth.
	 */
	public static function doNestLevel(PPFrame $frame): int
	{
		// Rely on internal magic word caching; ours would be a duplication of effort.
		$nestlevelVars = MagicWord::get(MetaTemplate::VR_NESTLEVEL_VAR);
		$lastVal = false;
		foreach ($frame->getNamedArguments() as $arg => $value) {
			// We do a matchStartToEnd() here rather than flipping the logic around and iterating through synonyms in
			// case someone overrides the declaration to be case-insensitive. Likewise, we always check all arguments,
			// regardless of case-sensitivity, so that the last one defined is always used in the event that there are
			// multiple qualifying values defined.
			if ($nestlevelVars->matchStartToEnd($arg)) {
				$lastVal = $value;
			}
		}

		return $lastVal !== false
			? $lastVal
			: $frame->depth;
	}

	/**
	 * Gets the page name at a given point in the stack.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments:
	 *     depth: The stack depth to check.
	 *
	 * @return string The requested page name.
	 */
	public static function doPageNameX(Parser $parser, PPFrame $frame, ?array $args): string
	{
		$title = self::getTitleAtDepth($parser, $frame, $args);
		return is_null($title) ? '' : $title->getPrefixedText();
	}

	/**
	 * Sets the value of a variable but only in Show Preview mode. This allows values to be specified as though the
	 * template had been called with those arguments. Like #define, #preview will not override any values that are
	 * already set.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The template frame in use.
	 * @param array $args Function arguments:
	 *     1: The variable name.
	 *     2: The variable value.
	 *
	 * @return void
	 */
	public static function doPreview(Parser $parser, PPFrame $frame, array $args): void
	{
		if (
			$frame->depth == 0 &&
			$parser->getOptions()->getIsPreview()
		) {
			self::checkAndSetVar($frame, $args, false);
		}
	}

	/**
	 * Returns values from a child template to its immediate parent. Unlike a traditional programming language, this
	 * has no effect on program flow.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *        1+: The variable(s) to return, optionally including an "into" specifier.
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it. Case insensitivity only applies to the
	 *            receiving end. The variables listed in the #return statement must match exactly.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doReturn(Parser $parser, PPFrame $frame, array $args): void
	{
		$parent = $frame->parent;
		if (!$parent) {
			return;
		}

		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_CASE
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		if (!$values || !ParserHelper::checkIfs($frame, $magicArgs)) {
			return;
		}

		$anyCase = self::checkAnyCase($magicArgs);
		$translations = self::getVariableTranslations($frame, $values);
		foreach ($translations as $srcName => $destName) {
			$dom = self::getVar($frame, $srcName, $anyCase);
			if ($dom) {
				$varValue = self::argSubtitution($frame, $dom, 0);
				self::setVar($parent, $destName, $varValue, $anyCase);
			}
		}
	}

	/**
	 * Unsets (removes) variables from the template.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *        1+: The variable(s) to unset.
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doUnset(Parser $parser, PPFrame $frame, array $args): void
	{
		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_CASE,
			self::NA_SHIFT
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		if (!count($values) || !ParserHelper::checkIfs($frame, $magicArgs)) {
			return;
		}

		$anyCase = self::checkAnyCase($magicArgs);
		$shift = (bool)($magicArgs[self::NA_SHIFT] ?? false);
		foreach ($values as $value) {
			$varName = $frame->expand($value);
			self::unsetVar($frame, $varName, $anyCase, $shift);
		}
	}

	/**
	 * Gets a confiuration object, as required by modern versions of MediaWiki.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Configuration_for_developers
	 *
	 * @return Config
	 */
	public static function getConfig(): Config
	{
		if (is_null(self::$config)) {
			self::$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig(strtolower(__CLASS__));
		}

		return self::$config;
	}

	/**
	 * This low-level function determines how MetaTemplate should behave. Possible values can be found in the "config"
	 * section of extension.json. Prepend the names with $metatemplate to alter their values in LocalSettings.php.
	 * Currently, these include:
	 *
	 *     EnableCatPageTemplate (self::STTNG_ENABLECPT) - if set to false, the following features are disabled:
	 *         <catpagetemplate>
	 *     EnableData (self::STTNG_ENABLEDATA) - if set to false, the following features are disabled:
	 *         {{#listsaved}}, {{#load}}, {{#save}}, <savemarkup>
	 *     EnableDefine (self::STTNG_ENABLEDEFINE) - if set to false, the following features are disabled:
	 *         {{#define}}, {{#inherit}}, {{#local}}, {{#preview}}, {{#return}}, {{#unset}}
	 *     EnablePageNames (self::STTNG_ENABLEPAGENAMES) - if set to false, the following features are disabled:
	 *         {{FULLPAGENAME0}}, {{FULLPAGENAMEx}}, {{NAMESPACEx}}, {{NAMESPACE0}}, {{NESTLEVEL}}, {{PAGENAME0}},
	 *         {{PAGENAMEx}}
	 *     ListsavedMaxTemplateSize	- templates with lengths above the size listed here will not be exectued.
	 *
	 * @param string $setting
	 *
	 * @return bool Whether MetaTemplate can/should use a particular feature.
	 */
	public static function getSetting($setting): bool
	{
		$config = self::getConfig();
		return (bool)$config->get($setting);
	}

	/**
	 * Gets a raw variable from the frame or, optionally, the entire stack. Use $frame->getXargument() in favour of
	 * this unless you need to parse the raw argument yourself or need case-insensitive retrieval.
	 *
	 * @param PPTemplateFrame_Hash $frame The frame to start at.
	 * @param string $varName The variable name.
	 * @param bool $anyCase Whether the variable's name is case-sensitive or not.
	 *
	 * @return ?PPNode_Hash_Tree Returns the value in raw format and the frame it came from.
	 */
	public static function getVar(PPTemplateFrame_Hash $frame, string $varName, bool $anyCase)
	{
		#RHshow('GetVar', $varName);
		// Try for an exact match without triggering expansion.
		$varValue = $frame->numberedArgs[$varName] ?? $frame->namedArgs[$varName] ?? null;
		if (!$varValue && $anyCase && !ctype_digit($varName)) {
			$lcname = $lcname ?? strtolower($varName);
			foreach ($frame->namedArgs as $key => $varValue) {
				if (strtolower($key) === $lcname) {
					return $varValue;
				}
			}
		}

		return $varValue;
	}

	/**
	 * Splits a variable list of the form 'x->xPrime' to a proper associative array.
	 *
	 * @param PPFrame $frame If the variable names may need to be expanded, this should be set to the active frame;
	 *                       otherwise it should be null.
	 * @param array $variables The list of variables to work on.
	 * @param ?int $trimLength The maximum number of characters allowed for variable names.
	 *
	 * @return array
	 */
	public static function getVariableTranslations(?PPFrame $frame, array $variables, ?int $trimLength = null): array
	{
		$retval = [];
		foreach ($variables as $srcName) {
			if ($frame) {
				$srcName = $frame->expand($srcName);
			}

			$varSplit = explode('->', $srcName, 2);
			$srcName = trim($varSplit[0]);
			// In PHP 8, this can be reduced to just substr(trim...)).
			$srcName = $trimLength ? substr($srcName, 0, $trimLength) : $srcName;
			if (count($varSplit) === 2) {
				$destName = trim($varSplit[1]);
				$destName = $trimLength ? substr($destName, 0, $trimLength) : $destName;
			} else {
				$destName = $srcName;
			}

			if (strlen($srcName) && strlen($destName)) {
				$retval[$srcName] = $destName;
			}
		}

		#RHshow('Translations', $retval);
		return $retval;
	}

	/**
	 * Takes the provided variable and adds it to the template frame as though it had been passed in. Automatically
	 * unsets any previous values, including case-variant values if $anyCase is true.
	 *
	 * @internal This also shifts any numeric-named arguments it touches from named to numeric. This should be
	 * inconsequential, but is mentioned in case there's something I've missed.
	 *
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param string $varName The variable name. This should be pre-trimmed, if necessary.
	 * @param PPNode|string $value The variable value.
	 *     PPNode: use some variation of argument expansion before sending the node here.
	 *
	 *
	 * @return void
	 */
	public static function setVar(PPTemplateFrame_Hash $frame, string $varName, string $varValue, $anyCase = false): void
	{
		#RHshow('Setvar', $varName, ' = ', is_object($varValue) ? ''  : '(' . gettype($varValue) . ')', $varValue);
		/*
            $args = Numbered/Named Args to add node value to.
            $cache = Numbered/Named Cache to add the fully expanded value to.
        */
		if (ctype_digit($varName)) {
			$varName = (int)$varName;
			$args = &$frame->numberedArgs;
			$cache = &$frame->numberedExpansionCache;
		} else {
			$args = &$frame->namedArgs;
			$cache = &$frame->namedExpansionCache;
		}

		self::unsetVar($frame, $varName, $anyCase);

		$dom = $frame->parser->preprocessToDom($varValue, Parser::PTD_FOR_INCLUSION); // was: (..., $frame->depth ? Parser::PTD_FOR_INCLUSION : 0)
		$dom->name = 'value';
		$checkText = $dom->getFirstChild();
		// If value is text-only, which will be the case the vast majority of times, we can use it to set the cache
		// without expansion. We still have to expand, however, due to the funky way we have to handle variable et al.
		$cache[$varName] = ($checkText === false || ($checkText instanceof PPNode_Hash_Text && !$checkText->getNextSibling()))
			? $varValue
			: $frame->expand($dom, PPFrame::NO_ARGS);
		$args[$varName] = $dom;
	}

	/**
	 * Takes the provided DOM tree and adds it to the template frame as though it had been passed in. Automatically
	 * unsets any previous values, including case-variant values if $anyCase is true. This is almost never the function
	 * you want to call, as there are no safeties in place to check that the DOM tree is in the correct format.
	 *
	 * @internal This also shifts any numeric-named arguments it touches from named to numeric. This should be
	 * inconsequential, but is mentioned in case there's something I've missed.
	 *
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param string $varName The variable name. This should be pre-trimmed, if necessary.
	 * @param PPNode|string $value The variable value.
	 *     PPNode: use some variation of argument expansion before sending the node here.
	 *
	 *
	 * @return void
	 */
	public static function setVarDirect(PPTemplateFrame_Hash $frame, string $varName, PPNode_Hash_Tree $dom, $anyCase = false): void
	{
		#RHshow('Setvar', $varName, ' = ', is_object($varValue) ? ''  : '(' . gettype($varValue) . ')', $varValue);
		/*
            $args = Numbered/Named Args to add node value to.
            $cache = Numbered/Named Cache to add the fully expanded value to.
        */
		if (ctype_digit($varName)) {
			$varName = (int)$varName;
			$args = &$frame->numberedArgs;
			$cache = &$frame->numberedExpansionCache;
		} else {
			$args = &$frame->namedArgs;
			$cache = &$frame->namedExpansionCache;
		}

		self::unsetVar($frame, $varName, $anyCase);

		if (!$frame->depth) {
			$cache[$varName] = $frame->expand($dom);
		}

		$args[$varName] = $dom;
	}

	/**
	 * Unsets a template variable.
	 *
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param string $varName The variable to unset.
	 * @param bool $anyCase Whether the variable match should be case insensitive.
	 * @param bool $shift For numeric unsets, whether to shift everything above it down by one.
	 *
	 * @return void
	 */
	public static function unsetVar(PPTemplateFrame_Hash $frame, $varName, bool $anyCase, bool $shift = false): void
	{
		if (is_int($varName) || ctype_digit($varName)) {
			if ($shift) {
				self::unsetWithShift($frame, $varName);
			} else {
				unset(
					$frame->namedArgs[$varName],
					$frame->namedExpansionCache[$varName],
					$frame->numberedArgs[$varName],
					$frame->numberedExpansionCache[$varName]
				);
			}
		} elseif ($anyCase) {
			$lcname = strtolower($varName);
			$keys = array_keys($frame->namedArgs);
			foreach ($keys as $key) {
				if (strtolower($key) === $lcname) {
					unset(
						$frame->namedArgs[$key],
						$frame->namedExpansionCache[$key]
					);
				}
			}
		} else {
			unset(
				$frame->namedArgs[$varName],
				$frame->namedExpansionCache[$varName]
			);
		}
	}
	#endregion

	#region Private Static Functions
	/**
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 * @param bool $overwrite Whether the incoming variable is allowed to overwrite any existing one.
	 *
	 * @return void
	 */
	private static function checkAndSetVar(PPTemplateFrame_Hash $frame, array $args, bool $overwrite): void
	{
		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_CASE
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		// No values possible with, for example, {{#local:if=1}}
		if (!ParserHelper::checkIfs($frame, $magicArgs) || !count($values)) {
			return;
		}

		$varName = trim($frame->expand($values[0]));
		if (substr($varName, 0, strlen(MetaTemplate::KEY_METATEMPLATE)) === MetaTemplate::KEY_METATEMPLATE) {
			return;
		}

		$anyCase = self::checkAnyCase($magicArgs);
		if (count($values) < 2) {
			if ($anyCase) {
				// This occurs with constructs like: {{#local:MiXeD|case=any}}
				$varValue = self::getVar($frame, $varName, $anyCase);
				if ($varValue) {
					// Does this need expanded?
					self::setVarDirect($frame, $varName, $varValue, $anyCase);
				}
			}
		} elseif ($overwrite || ($frame->namedArgs[$varName] ?? $frame->numberedArgs[$varName] ?? false) === false) {
			// Do argument substitution
			// Could do this faster by recursing tree and calling parser->argSubtitution on tplarg nodes.
			$varValue = $frame->expand($values[1], PPFrame::RECOVER_ORIG & ~PPFrame::NO_ARGS); // We need tag recovery with this, so don't use standard argSubstitution
			$varValue = VersionHelper::getInstance()->getStripState($frame->parser)->unstripBoth($varValue);
			self::setVar($frame, $varName, $varValue, $anyCase);
		}
	}

	/**
	 * Gets a list of variables that can bypass the normal variable definition lockouts on a template page. This means
	 * that variables which would normally display as {{{ns_id}}}, for example, will instead take on the specified/
	 * default values.
	 *
	 * @return void
	 */
	private static function getBypassVariables(): void
	{
		$bypassList = [];
		Hooks::run('MetaTemplateSetBypassVars', [&$bypassList]);
		self::$bypassVars = [];
		foreach ($bypassList as $bypass) {
			self::$bypassVars[$bypass] = true;
		}
	}

	/**
	 * Gets the title at a specific depth in the template stack.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments:
	 *     1: The depth of the title to get. Negative numbers start at the top of the template stack instead of the
	 *        current depth. Common values include:
	 *            0 = the current page
	 *            1 = the parent page
	 *           -1 = the first page
	 *
	 * @return ?Title
	 */
	private static function getTitleAtDepth(Parser $parser, PPFrame $frame, ?array $args): ?Title
	{
		$level = empty($args[0])
			? 0
			: (int)$frame->expand($args[0]);
		$depth = $frame->depth;
		$level = ($level > 0) ? $depth - $level + 1 : -$level;
		if ($level < $depth) {
			while ($frame && $level > 0) {
				$frame = $frame->parent;
				$level--;
			}

			return isset($frame) ? $frame->title : null;
		}

		return $level === $depth
			? $parser->getTitle()
			: null;
	}

	/**
	 * Gets a raw variable from the frame or, optionally, the entire stack. Use $frame->getXargument() in favour of
	 * this unless you need to parse the raw argument yourself or need case-insensitive retrieval.
	 *
	 * @param PPTemplateFrame_Hash $frame The frame to start at.
	 * @param string $varName The variable name.
	 * @param bool $anyCase Whether the variable's name is case-sensitive or not.
	 * @param bool $checkAll Whether to look for the variable in this template only or climb through the entire stack.
	 *
	 * @return tuple<PPNode_Hash_Tree, PPFrame> Returns the value in raw format and the frame it came from.
	 */
	private static function inheritVar(PPTemplateFrame_Hash $frame, string $srcName, $destName, bool $anyCase)
	{
		#RHshow('inhertVar', "$srcName->$destName ", (int)(bool)($frame->numberedArgs[$srcName] ?? $frame->namedArgs[$srcName]));
		$varValue = null;
		$curFrame = $frame->parent;
		while ($curFrame) {
			$varValue = $curFrame->numberedArgs[$srcName] ?? $curFrame->namedArgs[$srcName] ?? null;
			if (isset($varValue)) {
				break;
			}

			if (!$varValue && $anyCase && !ctype_digit($srcName)) {
				$lcname = $lcname ?? strtolower($srcName);
				foreach ($curFrame->namedArgs as $key => $value) {
					if (strtolower($key) === $lcname) {
						$varValue = $value;
						break 2;
					}
				}
			}

			$curFrame = $curFrame->parent;
		}

		/** @var PPNode_Hash_Tree|string $varValue */
		/** @var PPFrame|false $curFrame */
		if ($varValue && $curFrame) {
			$varValue = self::argSubtitution($curFrame, $varValue, 0);
			self::setVar($frame, $destName, $varValue, $anyCase);
		}

		return $varValue;
	}

	/**
	 * Unsets a numeric variable and shifts everything above it down by one.
	 *
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param string $varName The numeric variable to unset.
	 *
	 * @return void
	 */
	private static function unsetWithShift(PPTemplateFrame_Hash $frame, string $varName): void
	{
		$newArgs = [];
		$newCache = [];
		foreach ($frame->numberedArgs as $key => $value) {
			if ($varName != $key) {
				$newKey = ($key > $varName) ? $key - 1 : $key;
				$newArgs[$newKey] = $value;
				if (isset($frame->numberedExpansionCache[$key])) {
					$newCache[$newKey] = $frame->numberedExpansionCache[$key];
				}
			}
		}

		foreach ($frame->namedArgs as $key => $value) {
			if ($varName != $key) {
				$newKey = ctype_digit($key) && $key > $varName ? $key - 1 :  $key;
				$newArgs[$newKey] = $value;
				if (isset($frame->namedExpansionCache[$key])) {
					$newCache[$newKey] = $frame->namedExpansionCache[$key];
				}
			}
		}

		$frame->numberedArgs = $newArgs;
		$frame->numberedExpansionCache = $newCache;
	}
	#endregion
}
