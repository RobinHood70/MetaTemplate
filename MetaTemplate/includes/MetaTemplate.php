<?php
/*
namespace MediaWiki\Extension\MetaTemplate;
*/

use MediaWiki\MediaWikiServices;

/**
 * [Description MetaTemplate]
 */
class MetaTemplate
{
    // IMP: Disallowed arguments will now be passed back to the caller rather than being ignored.
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
    private static $bypassVars;

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
     * @return bool
     *
     */
    public static function can($setting)
    {
        $config = self::getConfig();
        return boolval($config->get($setting));
    }

    /**
     * @return GlovalVarConfig
     */
    public static function configBuilder()
    {
        return new GlobalVarConfig('metatemplate');
    }

    /**
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doDefine(Parser $parser, PPFrame $frame, array $args)
    {
        if (!isset($args[0])) {
            return;
        }

        $name = $frame->expand($args[0]);

        if (
            // Show {{{argument names}}} if on the actual template page and not previewing, but allow ns_base and ns_id through at all times.
            $frame->parent ||
            isset(self::$bypassVars[$name]) || // If re-instated as magic words, use: self::$bypassVars->matchStartToEnd($name)
            $parser->getTitle()->getNamespace() !== NS_TEMPLATE ||
            $parser->getOptions()->getIsPreview()
        ) {
            self::checkAndSetVar($parser, $frame, $args, $name);
        }
    }

    /**
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array|null $args
     *
     * @return string
     */
    public static function doFullPageNameX(Parser $parser, PPFrame_Hash $frame, array $args = null)
    {
        $title = self::getTitleFromArgs($parser, $frame, $args);
        return is_null($title) ? '' : $title->getPrefixedText();
    }

    /**
     * @param Parser $parser
     * @param PPTemplateFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doInherit(Parser $parser, PPTemplateFrame_Hash $frame, array $args)
    {
        $helper = ParserHelper::getInstance();
        list($magicArgs, $values) = $helper->getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (!empty($values) && $helper->checkIfs($frame, $magicArgs)) {
            $anyCase = $helper->checkAnyCase($magicArgs);
            foreach ($values as $name) {
                $varName = $frame->expand($name);
                $varValue = self::getVar($frame, $varName, $anyCase, true);
                if ($varValue !== false) {
                    self::setVar($frame, $varName, $varValue);
                }
            }
        }
    }

    // IMP: case=any is now respected and #local:x will override argument X or x.
    /**
     * doLocal
     *
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doLocal(Parser $parser, PPFrame_Hash $frame, array $args)
    {
        self::checkAndSetVar($parser, $frame, $args, $frame->expand($args[0]), true);
    }

    /**
     * doNamespaceX
     *
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array|null $args
     *
     * @return string
     */
    public static function doNamespaceX(Parser $parser, PPFrame_Hash $frame, array $args = null)
    {
        $title = self::getTitleFromArgs($parser, $frame, $args);
        $nsName = $parser->getFunctionLang()->getNsText($title->getNamespace());
        return is_null($title) ? '' : str_replace('_', ' ', $nsName);
    }

    /**
     * doNestLevel
     *
     * @param PPFrame_Hash $frame
     *
     * @return string
     */
    public static function doNestLevel(PPFrame_Hash $frame)
    {
        $retval = $frame->depth;
        $args = $frame->getNamedArguments();
        if (!is_null($args)) {
            $magicArgs = ParserHelper::getInstance()->transformArgs($args);
            $retval = $frame->expand(ParserHelper::getInstance()->arrayGet($magicArgs, self::NA_NESTLEVEL));
        }

        return $retval;
    }

    /**
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array|null $args
     *
     * @return string
     */
    public static function doPageNameX(Parser $parser, PPFrame_Hash $frame, array $args = null)
    {
        $title = self::getTitleFromArgs($parser, $frame, $args);
        return is_null($title) ? '' : $title->getPrefixedText();
    }

    /**
     * doPreview
     *
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doPreview(Parser $parser, PPFrame_Hash $frame, array $args)
    {
        if (
            $frame->depth == 0 &&
            $parser->getOptions()->getIsPreview()
        ) {
            self::checkAndSetVar($parser, $frame, $args, $frame->expand($args[0]));
        }
    }

    /**
     * doReturn
     *
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doReturn(Parser $parser, PPFrame_Hash $frame, array $args)
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

        if ($values && $helper->checkIfs($frame, $magicArgs)) {
            $anyCase = $helper->checkAnyCase($magicArgs);
            foreach ($values as $value) {
                $varName = $frame->expand($value);
                $varValue = self::getVar($frame, $varName, $anyCase);
                self::setVar($parent, $varName, $varValue);
            }
        }
    }

    /**
     * doUnset
     *
     * @param Parser $parser
     * @param PPFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doUnset(Parser $parser, PPFrame_Hash $frame, array $args)
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
        $shift = boolval(ParserHelper::getInstance()->arrayGet($magicArgs, self::NA_SHIFT, false));
        foreach ($values as $value) {
            $varName = $frame->expand($value);
            self::unsetVar($frame, $varName, $anyCase, $shift);
        }
    }

    /**
     * getConfig
     *
     * @return Config
     */
    public static function getConfig()
    {
        if (is_null(self::$config)) {
            self::$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig(strtolower(__CLASS__));
        }

        return self::$config;
    }

    /**
     * getVar
     *
     * @param PPTemplateFrame_Hash $frame
     * @param mixed $varName
     * @param bool $anyCase
     * @param bool $checkAll
     *
     * @return string|PPNode_Hash_Tree
     */
    public static function getVar(PPTemplateFrame_Hash $frame, $varName, $anyCase = false, $checkAll = false)
    {
        // If varName is entirely numeric, case doesn't matter, so skip it.
        $anyCase &= !ctype_digit($varName);
        $lcname = strtolower($varName);
        $retval = false;
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
     * init
     *
     * @return void
     */
    public static function init()
    {
        ParserHelper::getInstance()->cacheMagicWords([
            self::NA_NAMESPACE,
            self::NA_NESTLEVEL,
            self::NA_ORDER,
            self::NA_PAGENAME,
            self::NA_SHIFT,
            MetaTemplateData::NA_SAVEMARKUP,
            MetaTemplateData::NA_SET,
        ]);

        $bypassVars = [];
        Hooks::run('MetaTemplateSetBypassVars', [&$bypassVars]);
        self::$bypassVars = new MagicWordArray($bypassVars);
    }

    /**
     * Takes the provided variable and adds it to the template frame as though it had been passed in.
     *
     * @param PPTemplateFrame_Hash $frame
     * @param mixed $varName
     * @param mixed $value
     *
     * @return void
     */
    public static function setVar(PPTemplateFrame_Hash $frame, $varName, $value)
    {
        // RHshow($varName, '=>', $frame->expand($value));
        /*
            $args = Numbered/Named Args to add node value to.
            $cache = Numbered/Named Cache to add the fully expanded value to.
        */
        if (is_int($varName) || (is_string($varName) && ctype_digit($varName))) {
            $varName = intval($varName);
            $args = &$frame->numberedArgs;
            $cache = &$frame->numberedExpansionCache;
        } else {
            $args = &$frame->namedArgs;
            $cache = &$frame->namedExpansionCache;
        }

        if ($frame->getArgument($varName) !== false) {
            $child = $args[$varName]->getFirstChild();
            $cache[$varName] = $value;
            if ($child) {
                $child->value = $value;
            }
        } else {
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
    }

    /**
     * @param PPFrame_Hash $frame
     * @param array $args
     * @param bool $override
     *
     * @return void
     */
    private static function checkAndSetVar(Parser $parser, PPFrame_Hash $frame, array $args, $name, $override = false)
    {
        list($magicArgs, $values) = ParserHelper::getInstance()->getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );

        if (count($values) > 1 && ParserHelper::getInstance()->checkIfs($frame, $magicArgs)) {
            $anyCase = !ctype_digit($name) && ParserHelper::getInstance()->checkAnyCase($magicArgs);
            $existing = self::getVar($frame, $name, $anyCase);
            $value = $values[1];
            // RHshow('Existing: ', $existing);
            // RHshow('Override: ', $override);
            // RHshow('Any Case: ', $anyCase);
            if ($existing === false) {
                self::setVar($frame, $name, $value);
            } elseif ($override) {
                self::unsetVar($frame, $name, $anyCase);
                self::setVar($frame, $name, $value);
            } elseif ($anyCase) {
                // RHshow($name);
                // Unset/reset to ensure correct case.
                self::unsetVar($frame, $name, true);
                self::setVar($frame, $name, $existing);
            }
        }
    }

    /**
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array|null $args
     *
     * @return Title|null
     */
    private static function getTitleFromArgs(Parser $parser, PPFrame $frame, array $args = null)
    {
        if (is_null($args)) {
            $level = 0;
        } else {
            $values = ParserHelper::getInstance()->getMagicArgs($frame, $args)[1];
            $level = intval(ParserHelper::getInstance()->arrayGet($values, 0, 0));
        }

        // It should be impossible to alter the name of the current page without triggering a new parser expansion
        // with new frames, so don't disable cache for that. Other than that, we have no easy way to know if parent
        // pages may have affected this page, so make this volatile so we can be sure.
        // NOTE: Disabled, as it seems unlikely that any parent page can change title during the request.
        /* if ($level != 0) {
            $frame->setVolatile();
        } */

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
     * unsetVar
     *
     * @param PPFrame_Hash $frame
     * @param mixed $varName
     * @param mixed $anyCase
     * @param bool $shift
     *
     * @return void
     */
    private static function unsetVar(PPFrame_Hash $frame, $varName, $anyCase, $shift = false)
    {
        if (is_string($varName) && ctype_digit($varName)) {
            if ($shift) {
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
            } else {
                unset($frame->numberedArgs[$varName], $frame->numberedExpansionCache[$varName]);
            }
        } elseif ($anyCase) {
            $lcname = strtolower($varName);
            $namedArgs = $frame->getNamedArguments();
            foreach ($namedArgs as $key => $value) {
                if (strtolower($key) === $lcname) {
                    // This is safe in PHP as the array is copied.
                    unset($namedArgs[$key]);
                }
            }
        } else {
            unset($frame->namedArgs[$varName], $frame->namedExpansionCache[$varName]);
        }
    }
}
