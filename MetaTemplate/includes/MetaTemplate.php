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
    public const AV_ANY = 'metatemplate-any';

    public const KEY_METATEMPLATE = '@metatemplate';

    public const NA_CASE = 'metatemplate-case';
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

    private static $config;

    /**
     * An array of strings containing the names of parameters that should be passed through to a template, even if
     * displayed on its own page.
     *
     * @var array $bypassVars // @ var MagicWordArray
     */
    private static $bypassVars = null;

    /**
     * This low-level function determines how MetaTemplate should behave. Possible values can be found in the "config"
     * section of extension.json. Prepend the names with $metatemplate to alter their values in LocalSettings.php.
     * Currently, these include:
     *
     *     EnableCatPageTemplate - if set to false, the <catpagetemplate> tag will be disabled.
     *     EnableData - if set to false, #load, #save, #listsaved and <savemarkup> are all disabled.
     *
     * @param string $setting
     *
     * @return bool Whether MetaTemplate can/should use a particular feature.
     *
     */
    public static function can($setting): bool
    {
        $config = self::getConfig();
        return (bool)$config->get($setting);
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
     *
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
     *
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
     * @param PPFrame $frame The frame in use.
     * @param array $args Function arguments:
     *        1+: The variable(s) to unset.
     *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
     *            'any', along with any translations or synonyms of it.
     *        if: A condition that must be true in order for this function to run.
     *     ifnot: A condition that must be false in order for this function to run.
     *
     * @return void
     *
     */
    public static function doInherit(Parser $parser, PPFrame $frame, array $args): void
    {
        if (!$frame->depth) {
            return;
        }

        [$magicArgs, $values] = ParserHelper::getMagicArgs(
            $frame,
            $args,
            self::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (!$values || !ParserHelper::checkIfs($frame, $magicArgs)) {
            return;
        }

        $anyCase = self::checkAnyCase($magicArgs);
        $translations = self::getVariableTranslations($frame, $values);
        foreach ($translations as $srcName => $destName) {
            if (self::getVar($frame, $destName, $anyCase) === false && isset($frame->parent)) {
                // We force expansion here so variables don't get transferred across frame depths.
                $varValue = self::getVar($frame->parent, $srcName, $anyCase, true, true);
                if ($varValue !== false) {
                    self::setVar($frame, $destName, $varValue, $anyCase);
                }
            }
        }
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
     *
     */
    public static function doLocal(Parser $parser, PPFrame $frame, array $args): void
    {
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
     *
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
     *
     */
    public static function doNestLevel(PPFrame $frame): int
    {
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
     *
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
     *
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
     * @param PPFrame $frame The frame in use.
     * @param array $args Function arguments:
     *        1+: The variable(s) to return, optionally including an "into" specifier.
     *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
     *            'any', along with any translations or synonyms of it. Case insensitivity only applies to the
     *            receiving end. The variables listed in the #return statement must match exactly.
     *        if: A condition that must be true in order for this function to run.
     *     ifnot: A condition that must be false in order for this function to run.
     *
     * @return void
     *
     */
    public static function doReturn(Parser $parser, PPFrame $frame, array $args): void
    {
        $parent = $frame->parent;
        if (!$parent) {
            return;
        }

        [$magicArgs, $values] = ParserHelper::getMagicArgs(
            $frame,
            $args,
            self::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (!$values || !ParserHelper::checkIfs($frame, $magicArgs)) {
            return;
        }

        $anyCase = self::checkAnyCase($magicArgs);
        $translations = self::getVariableTranslations($frame, $values);
        foreach ($translations as $srcName => $destName) {
            $varValue = $frame->getArgument($srcName); // No need for getVar here.
            if ($varValue !== false) {
                self::setVar($parent, $destName, $varValue, $anyCase);
            }
        }
    }

    /**
     * Unsets (removes) variables from the template.
     *
     * @param Parser $parser The parser in use.
     * @param PPFrame $frame The frame in use.
     * @param array $args Function arguments:
     *        1+: The variable(s) to unset.
     *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
     *            'any', along with any translations or synonyms of it.
     *        if: A condition that must be true in order for this function to run.
     *     ifnot: A condition that must be false in order for this function to run.
     *
     * @return void
     *
     */
    public static function doUnset(Parser $parser, PPFrame $frame, array $args): void
    {
        [$magicArgs, $values] = ParserHelper::getMagicArgs(
            $frame,
            $args,
            self::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT,
            self::NA_SHIFT
        );

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
     * Gets a variable from the frame or, optionally, from the template stack.
     *
     * @param PPTemplateFrame_Hash $frame The frame in use.
     * @param string $varName The variable name.
     * @param bool $anyCase Whether the variable's name is case-sensitive or not.
     * @param bool $checkAll Whether to look for the variable in this template only or climb through the entire stack.
     *
     * @return string|false The expanded variable value. If found, this will be the string from the original arguments; otherwise, it will be false.
     *
     */
    public static function getVar(PPTemplateFrame_Hash $frame, string $varName, bool $anyCase, bool $checkAll = false, bool $expandResult = true)
    {
        // If varName is entirely numeric, case doesn't matter, so skip case checking.
        $anyCase &= !ctype_digit($varName);
        do {
            // First, we try to handle the simplest/most common cases.
            if ($expandResult) {
                // Look for an exact match first.
                $retval = $frame->getArgument($varName);
                if ($retval !== false) {
                    return $retval;
                }
            } elseif (!$anyCase) {
                // Look for an exact match, but return unexpanded result if found.
                $retval = $frame->numberedArgs[$varName] ?? $frame->namedArgs[$varName] ?? false;
                if ($retval !== false) {
                    return $retval;
                }
            }

            // If those fail, try looping.
            if ($anyCase || !$expandResult) {
                $lcname = $lcname ?? strtolower($varName);
                foreach ($frame->namedArgs as $key => $value) {
                    if (strtolower($key) === $lcname) {
                        if ($expandResult) {
                            if (isset($frame->namedExpansionCache[$key])) {
                                return $frame->namedExpansionCache[$key];
                            } else {
                                $expandValue = $frame->expand($value, PPFrame::STRIP_COMMENTS);
                                $frame->namedExpansionCache[$key] = $expandValue;
                                return $expandValue;
                            }
                        } else {
                            return $value;
                        }
                    }
                }
            }

            $frame = $checkAll ? $frame->parent : false;
        } while ($frame);

        return false;
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
     *
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

        return $retval;
    }

    /**
     * Initializes magic words.
     *
     * @return void
     *
     */
    public static function init(): void
    {
        if (self::can(self::STTNG_ENABLECPT)) {
            MetaTemplateCategoryViewer::init();
        }

        if (self::can(self::STTNG_ENABLEDATA)) {
            MetaTemplateData::init();
        }

        if (self::can(self::STTNG_ENABLEDEFINE)) {
            ParserHelper::cacheMagicWords([self::NA_SHIFT]);
        }

        if (self::can(self::STTNG_ENABLEPAGENAMES)) {
            ParserHelper::cacheMagicWords([self::VR_NESTLEVEL]);
        }
    }

    /**
     * Takes the provided variable and adds it to the template frame as though it had been passed in. Automatically
     * unsets any previous values, including case-variant values if $anyCase is true. This also shifts any numeric-
     * named arguments it touches from named to numeric.
     *
     * @param PPTemplateFrame_Hash $frame The frame in use.
     * @param string $varName The variable name. This should be pre-trimmed, if necessary.
     * @param MetaTemplateVar|PPNode|string $value The variable value. If this is a PPNode, it must already have had variables replaced to avoid recursive expansion.
     *
     * @return void
     *
     */
    public static function setVar(PPTemplateFrame_Hash $frame, string $varName, $value, $anyCase = false): void
    {
        #RHshow('Setvar: ', $varName, ' = ', is_object($value) ? ''  : '(' . gettype($value) . ')', $value);
        if (!strlen($varName)) {
            return;
        }

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

        self::unsetVar($frame, $varName, $anyCase, false);
        if (is_null($value)) {
            return;
        }

        if (is_string($value)) {
            // Value is a string, so create node and leave text as is.
            $args[$varName] = new PPNode_Hash_Text([$value], 0);
            $cache[$varName] = $value;
        } elseif ($value instanceof PPNode) {
            // Value is a node, so leave as is and expand value for text.
            $args[$varName] = $value;
            $cache[$varName] = $frame->expand($value);
        } else {
            $value = ParserHelper::error('metatemplate-setvar-notrecognized', is_object($value) ? get_class($value) : gettype($value), $varName);
        }
    }

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
        [$magicArgs, $values] = ParserHelper::getMagicArgs(
            $frame,
            $args,
            self::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (!ParserHelper::checkIfs($frame, $magicArgs)) {
            return;
        }

        $name = trim($frame->expand($values[0]));
        if (substr($name, 0, strlen(MetaTemplate::KEY_METATEMPLATE)) === MetaTemplate::KEY_METATEMPLATE) {
            return;
        }

        $anyCase = self::checkAnyCase($magicArgs);

        if (count($values) < 2 && $anyCase) {
            // This occurs with constructs like: {{#local:MiXeD|case=any}}
            $existing = self::getVar($frame, $name, $anyCase);
            if ($existing !== false) {
                // This retains the $anyCase so that all existing mixEd/MixeD/etc get changed to the current case.
                self::setVar($frame, $name, $existing, $anyCase);
            }
        } elseif ($overwrite || ($frame->namedArgs[$name] ?? $frame->numberedArgs[$name] ?? false) === false) {
            // Only expand the value now that we know we're actually setting it.
            $value = $frame->expand($values[1]);
            #RHshow($Set $name = $value");
            self::setVar($frame, $name, $value, $anyCase);
        } // else variable is already defined and should not be overridden.
    }

    private static function getBypassVariables()
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
     *
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
     * Unsets a numeric variable and shifts everything above it down by one.
     *
     * @param PPTemplateFrame_Hash $frame The frame in use.
     * @param string $varName The numeric variable to unset.
     *
     * @return void
     *
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
    private static function unsetVar(PPTemplateFrame_Hash $frame, $varName, bool $anyCase, bool $shift = false): void
    {
        if (is_int($varName) || ctype_digit($varName)) {
            if ($shift) {
                self::unsetWithShift($frame, $varName);
            } else {
                unset($frame->namedArgs[$varName], $frame->namedExpansionCache[$varName]);
                unset($frame->numberedArgs[$varName], $frame->numberedExpansionCache[$varName]);
            }
        } elseif ($anyCase) {
            $lcname = strtolower($varName);
            $keys = array_keys($frame->namedArgs);
            foreach ($keys as $key) {
                if (strtolower($key) === $lcname) {
                    unset($frame->namedArgs[$key], $frame->namedExpansionCache[$key]);
                }
            }
        } else {
            unset($frame->namedArgs[$varName], $frame->namedExpansionCache[$varName]);
        }
    }
}
