<?php

//namespace MediaWiki\Extension\MetaTemplate;

use MediaWiki\Extension;
use MediaWiki\MediaWikiServices;

/**
 * An extension to add data persistence and variable manipulation to MediaWiki.
 */
class MetaTemplate
{
    const NA_NAMESPACE = 'metatemplate-namespace';
    const NA_NESTLEVEL = 'metatemplate-nestlevel';
    const NA_ORDER = 'metatemplate-order';
    const NA_PAGENAME = 'metatemplate-pagename';
    const NA_SHIFT = 'metatemplate-shift';

    const PF_DEFINE = 'metatemplate-define';
    const PF_FULLPAGENAMEx = 'metatemplate-fullpagenamex';
    const PF_INHERIT = 'metatemplate-inherit';
    const PF_LOCAL = 'metatemplate-local';
    const PF_NAMESPACEx = 'metatemplate-namespacex';
    const PF_PAGENAMEx = 'metatemplate-pagenamex';
    const PF_PREVIEW = 'metatemplate-preview';
    const PF_RETURN = 'metatemplate-return';
    const PF_UNSET = 'metatemplate-unset';

    const STTNG_ENABLEDATA = 'EnableData';
    const STTNG_ENABLECPT = 'EnableCatPageTemplate';

    const TG_CATPAGETEMPLATE = 'metatemplate-catpagetemplate'; // Might be movable

    const VR_FULLPAGENAME0 = 'metatemplate-fullpagename0';
    const VR_NAMESPACE0 = 'metatemplate-namespace0';
    const VR_NESTLEVEL = 'metatemplate-nestlevel';
    const VR_PAGENAME0 = 'metatemplate-pagename0';

    private static $config;

    /**
     * An array of strings containing the names of parameters that should be passed through to a template, even if
     * displayed on its own page.
     *
     * @var array $bypassVars // @ var MagicWordArray
     */
    private static $bypassVars = [];

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
        return boolval($config->get($setting));
    }

    /**
     * @return GlovalVarConfig The global variable configuration for MetaTemplate.
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
        // (e.g., ns_base and ns_id) through at all times.
        if (
            $frame->parent ||
            $parser->getTitle()->getNamespace() !== NS_TEMPLATE ||
            $parser->getOptions()->getIsPreview() ||
            self::$bypassVars[trim($frame->expand($args[0]))]
        ) {
            self::checkAndSetVar($frame, $args, false);
        }
    }

    /**
     * Gets the full page name at a given point in the stack.
     *
     * @param Parser $parser The parser in use.
     * @param PPFrame $frame The frame in use.
     * @param array $args Function arguments:
     *     depth: The stack depth to check.
     *
     * @return string
     *
     */
    public static function doFullPageNameX(Parser $parser, PPFrame $frame, ?array $args): string
    {
        $title = self::getTitleAtDepth($parser, $frame, $args);
        return is_null($title) ? '' : $title->getPrefixedText();
    }

    /**
     * [Description]
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

        $helper = ParserHelper::getInstance();
        list($magicArgs, $values) = $helper->getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (empty($values) || !$helper->checkIfs($frame, $magicArgs)) {
            return;
        }

        $anyCase = $helper->checkAnyCase($magicArgs);
        foreach ($values as $nameNode) {
            $varSplit = explode('=>', $frame->expand($nameNode), 2);
            $varValue = self::getVar($frame, $varSplit[0], $anyCase, false);
            if ($varValue === false) {
                $varValue = self::getVar($frame->parent, $varSplit[0], $anyCase, true);
                if ($varValue !== false) {
                    $varName = trim(count($varSplit) == 2 ? $varSplit[1] : $varSplit[0]);
                    self::setVar($frame, $varName, $varValue);
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
    public static function doNestLevel(PPFrame $frame): string
    {
        $retval = $frame->depth;
        $args = $frame->getNamedArguments();
        if (!is_null($args)) {
            $magicArgs = ParserHelper::getInstance()->transformArgs($args);
            if (isset($magicArgs[self::NA_NESTLEVEL])) {
                $retval = $frame->expand($magicArgs[self::NA_NESTLEVEL]);
            }
        }

        return $retval;
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

        $helper = ParserHelper::getInstance();
        list($magicArgs, $values) = $helper->getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (empty($values) || !$helper->checkIfs($frame, $magicArgs)) {
            return;
        }

        $anyCase = $helper->checkAnyCase($magicArgs);
        foreach ($values as $varNode) {
            $varSplit = explode('=>', $frame->expand($varNode), 2);
            $varValue = self::getVar($frame, $varSplit[0], false, false);
            if ($varValue !== false) {
                $varName = trim(count($varSplit) == 2 ? $varSplit[1] : $varSplit[0]);
                self::unsetVar($parent, $varName, $anyCase, false);
                self::setVar($parent, $varName, $varValue);
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
        list($magicArgs, $values) = ParserHelper::getInstance()->getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT,
            self::NA_SHIFT
        );

        if (!count($values) || !ParserHelper::getInstance()->checkIfs($frame, $magicArgs)) {
            return;
        }

        $anyCase = ParserHelper::getInstance()->checkAnyCase($magicArgs);
        $shift = boolval($magicArgs[self::NA_SHIFT] ?? false);
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
     * @return string|PPNode_Hash_Tree
     */
    public static function getVar(PPTemplateFrame_Hash $frame, string $varName, bool $anyCase, bool $checkAll)
    {
        // If varName is entirely numeric, case doesn't matter, so skip case checking.
        $anyCase &= !ctype_digit($varName);
        $lcname = strtolower($varName);
        do {
            // Try exact name first.
            $retval = $frame->getArgument($varName);
            if ($retval === false && $anyCase) {
                foreach ($frame->getNamedArguments() as $key => $value) {
                    if (strtolower($key) === $lcname) {
                        $retval = $value;
                    }
                }
            }

            $frame = $checkAll ? $frame->parent : false;
        } while ($retval === false && $frame);

        return $retval;
    }

    /**
     * Initializes magic words and bypass variables.
     *
     * @return void
     *
     */
    public static function init(): void
    {
        ParserHelper::getInstance()->cacheMagicWords([
            self::NA_NAMESPACE,
            self::NA_NESTLEVEL,
            self::NA_ORDER,
            self::NA_PAGENAME,
            self::NA_SHIFT,
        ]);

        Hooks::run('MetaTemplateSetBypassVars', [&self::$bypassVars]);
        if (self::can('EnableData')) {
            MetaTemplateData::init();
        }
    }

    /**
     * Takes the provided variable and adds it to the template frame as though it had been passed in.
     *
     * @param PPTemplateFrame_Hash $frame The frame in use.
     * @param string $varName The variable name. This should be pre-trimmed, if necessary.
     * @param mixed $value The variable value.
     *
     * @return void
     *
     */
    public static function setVar(PPTemplateFrame_Hash $frame, string $varName, $value): void
    {
        // RHshow($varName, '=>', $frame->expand($value));
        if (!strlen($varName)) {
            return;
        }

        /*
            $args = Numbered/Named Args to add node value to.
            $cache = Numbered/Named Cache to add the fully expanded value to.
        */
        if (ctype_digit($varName)) {
            $varName = intval($varName);
            $args = &$frame->numberedArgs;
            $cache = &$frame->numberedExpansionCache;
        } else {
            $args = &$frame->namedArgs;
            $cache = &$frame->namedExpansionCache;
        }

        self::unsetVar($frame, $varName, false, false);
        if (is_string($value)) {
            // Value is a string, so create node and leave text as is.
            $valueNode = new PPNode_Hash_Text([$value], 0);
            $valueText = $value;
        } else {
            // Value is a node, so leave node as it is and expand value for text.
            $valueNode = $value;
            $valueText = $frame->expand($value);
        }

        $args[$varName] = $valueNode;
        $cache[$varName] = $valueText;
        // RHshow("Args:\n", $args);
        // RHshow("Cache:\n", $cache);
    }

    /**
     * @param PPTemplateFrame_Hash $frame The frame in use.
     * @param array $args Function arguments:
     *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
     *            'any', along with any translations or synonyms of it.
     *        if: A condition that must be true in order for this function to run.
     *     ifnot: A condition that must be false in order for this function to run.
     * @param bool $override
     *
     * @return void
     */
    private static function checkAndSetVar(PPTemplateFrame_Hash $frame, array $args, bool $override): void
    {
        list($magicArgs, $values) = ParserHelper::getInstance()->getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (count($values) < 2 || !ParserHelper::getInstance()->checkIfs($frame, $magicArgs)) {
            return;
        }

        $name = trim($frame->expand($values[0]));
        $anyCase = ParserHelper::getInstance()->checkAnyCase($magicArgs);
        $existing = self::getVar($frame, $name, $anyCase, false);
        if ($existing !== false) {
            if (!$override) {
                return;
            }

            self::unsetVar($frame, $name, $anyCase);
        }

        self::setVar($frame, $name, $values[1]);
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
        if (empty($args)) {
            $level = 0;
        } else {
            $level = intval($frame->expand($args[0]));
        }

        $depth = $frame->depth;
        $level = ($level > 0) ? $depth - $level + 1 : -$level;
        if ($level < $depth) {
            while ($frame && $level > 0) {
                $frame = $frame->parent;
                $level--;
            }

            return isset($frame) ? $frame->title : null;
        }

        if ($level == $depth) {
            return $parser->getTitle();
        }

        return null;
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
    private static function unsetNumeric(PPTemplateFrame_Hash $frame, string $varName): void
    {
        $newArgs = [];
        $newCache = [];
        foreach ($frame->numberedArgs as $key => $value) {
            if ($varName != $key) {
                $newKey = $key > $varName ? $key - 1 : $key;
                $newArgs[$newKey] = $value;
                if (isset($frame->numberedCache[$key])) {
                    $newCache[$newKey] = $frame->numberedExpansionCache[$key];
                }
            }
        }

        foreach ($frame->namedArgs as $key => $value) {
            if ($varName != $key) {
                $newKey = ctype_digit($key) && $key > $varName ? $key - 1 :  $key;
                $newArgs[$newKey] = $value;
                if (isset($frame->namedCache[$key])) {
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
    private static function unsetVar(PPTemplateFrame_Hash $frame, string $varName, bool $anyCase, bool $shift = false): void
    {
        if (is_string($varName) && ctype_digit($varName)) {
            if ($shift) {
                self::unsetNumeric($frame, $varName);
            } else {
                unset($frame->numberedArgs[$varName], $frame->numberedExpansionCache[$varName]);
            }
        } elseif ($anyCase) {
            $lcname = strtolower($varName);
            $namedArgs = &$frame->namedArgs;
            $keys = array_keys($namedArgs);
            foreach ($keys as $key) {
                if (strtolower($key) === $lcname) {
                    unset($namedArgs[$key]);
                }
            }
        } else {
            unset($frame->namedArgs[$varName], $frame->namedExpansionCache[$varName]);
        }
    }
}
