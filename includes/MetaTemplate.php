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
    const AV_ANY = 'metatemplate-any';

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

    const TG_CATPAGETEMPLATE = 'metatemplate-catpagetemplate'; // Might be movable

    const VR_FULLPAGENAME0 = 'metatemplate-fullpagename0';
    const VR_NAMESPACE0 = 'metatemplate-namespace0';
    const VR_NESTLEVEL = 'metatemplate-nestlevel';
    const VR_PAGENAME0 = 'metatemplate-pagename0';

    private static $config;
    private static $ignoredArgs = [ParserHelper::NA_NSBASE, ParserHelper::NA_NSID];

    /**
     * @param mixed $setting
     *
     * @return bool
     */
    public static function can($setting)
    {
        $config = self::getConfig();
        $retval = boolval($config->get($setting));
        if ($setting === 'EnableLoadSave') {
            $retval &= MetaTemplateSql::getInstance()->tablesExist();
        }

        return $retval;
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
    public static function doDefine(Parser $parser, PPFrame_Hash $frame, array $args)
    {
        if (count($args) > 1) {
            $name = $frame->expand($args[0]);
            if (
                $frame->depth > 0 ||
                $parser->getOptions()->getIsPreview() ||
                $parser->getTitle()->getNamespace() != NS_TEMPLATE ||
                ParserHelper::magicWordIn($name, self::$ignoredArgs)
            ) {
                self::checkAndSetVar($frame, $args, $name);
            }
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
     * @param PPFrame_Hash $frame
     * @param array $args
     *
     * @return void
     */
    public static function doInherit(Parser $parser, PPFrame_Hash $frame, array $args)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );
        if (count($values) > 0 && ParserHelper::checkIfs($magicArgs)) {
            $anyCase = ParserHelper::checkAnyCase($magicArgs);
            foreach ($values as $value) {
                $varName = $frame->expand($value);
                $value = self::getVar($frame, $varName, $anyCase, true);
                if (!is_null($value)) {
                    self::setVar($frame, $varName, $value);
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
        if (count($args) > 1) {
            self::checkAndSetVar($frame, $args, $frame->expand($args[0]), true);
        }
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
        return ParserHelper::getMagicValue(self::NA_NESTLEVEL, $frame->getArguments(), $frame->depth);

        /*
        foreach ($frame->getArguments() as $key => $value) {
            if (ParserHelper::magicWordIn($key, [self::NA_NESTLEVEL])) {
                return $frame->expand($value);
            }
        }

        return strval($value); // sprintf("%d", $value);
        */
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
        if (count($args) > 1 && $frame->depth == 0 && $parser->getOptions()->getIsPreview()) {
            self::checkAndSetVar($frame, $args, $frame->expand($args[0]));
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
        if ($frame->depth != 0) {
            return;
        }

        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );
        if (count($values) == 0 || !ParserHelper::checkIfs($magicArgs)) {
            return;
        }

        $anyCase = ParserHelper::checkAnyCase($magicArgs);
        foreach ($values as $value) {
            $varName = $frame->expand($value);
            $value = self::getVar($frame, $varName, $anyCase);
            if (isset($frame->parent, $value)) {
                self::setVar($frame->parent, $varName, $value);
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
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT,
            self::NA_SHIFT
        );
        if (count($values) == 0 || !ParserHelper::checkIfs($magicArgs)) {
            return;
        }

        $anyCase = ParserHelper::checkAnyCase($magicArgs);
        $shift = boolval(ParserHelper::arrayGet($magicArgs, self::NA_SHIFT, false));
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
     * @param PPFrame_Hash $frame
     * @param mixed $varName
     * @param bool $anyCase
     * @param bool $checkAll
     *
     * @return string|PPNode_Hash_Tree
     */
    public static function getVar(PPTemplateFrame_Hash $frame, $varName, $anyCase = false, $checkAll = false)
    {
        $retval = '';
        $anyCase == $anyCase && !ctype_digit($varName);
        while ($frame && !$retval) {
            if ($anyCase) {
                // Loop through all, only picking up last one, like a template would if it supported case-insensitive names.
                foreach (self::findAnyCaseNames($frame, $varName) as $anyCaseName) {
                    $retval = $frame->namedArgs[$anyCaseName];
                }
            } else {
                $retval = self::getVarDirect($frame, $varName);
            }

            $frame = $checkAll ? $frame->parent : null;
        }

        return $retval;
    }

    /**
     * init
     *
     * @return void
     */
    public static function init()
    {
        // MW 1.32+ $magicFactory = $parser->getMagicWordFactory( );
        //          $magicFactory->get( $word );
        ParserHelper::cacheMagicWords([
            self::NA_NAMESPACE,
            self::NA_NESTLEVEL,
            self::NA_ORDER,
            self::NA_PAGENAME,
            self::NA_SHIFT,
            MetaTemplateData::NA_SAVEMARKUP,
            MetaTemplateData::NA_SET,
        ]);
    }

    /**
     * setVar
     *
     * @param PPFrame_Hash $frame
     * @param mixed $varName
     * @param mixed $value
     *
     * @return void
     */
    public static function setVar(PPFrame_Hash $frame, $varName, $value)
    {
        show($varName, '=', $frame->expand($value));
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
            if ($child)
                $child->value = $value;
            $cache[$varName] = $value;
        } else {
            $element = new PPNode_Hash_Tree('value');
            if (is_string($value)) {
                $cacheValue =  $value;
                $child = new PPNode_Hash_Text($value);
            } else {
                $cacheValue = $frame->expand($value);
                $child = $value;
            }

            $element->addChild($child);
            $args[$varName] = $element;
            $cache[$varName] = $cacheValue;
        }
    }

    /**
     * @param PPFrame_Hash $frame
     * @param array $args
     * @param bool $override
     *
     * @return void
     */
    private static function checkAndSetVar(PPFrame_Hash $frame, array $args, $name, $override = false)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_CASE,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );
        if (ParserHelper::checkIfs($magicArgs)) {
            $anyCase = ParserHelper::checkAnyCase($magicArgs);
            $existing = self::getVar($frame, $name, $anyCase);
            $value = $values[1];
            if (is_null($existing)) {
                self::setVar($frame, $name, $value);
            } elseif ($override) {
                self::unsetVar($frame, $name, $anyCase);
                self::setVar($frame, $name, $value);
            } elseif ($anyCase) { // Unset/reset to ensure correct case.
                $value = ParserHelper::nullCoalesce($existing, $value);
                self::unsetVar($frame, $name, $anyCase);
                self::setVar($frame, $name, $value);
            }
        }
    }

    /**
     * findAnyCaseNames
     *
     * @param PPFrame $frame
     * @param mixed $varName
     *
     * @return string[]
     */
    private static function findAnyCaseNames(PPFrame $frame, $varName)
    {
        // Loop to find all in the event of case-variant names. We can't yield here because the caller will likely be modifying the array.
        $lcname = strtolower($varName);
        $retval = [];
        foreach (array_keys($frame->getNamedArguments()) as $key) {
            if (strtolower($key) == $lcname) {
                $retval[] = $key;
            }
        }

        return $retval;
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
        if (!is_null($args)) {
            list(, $values) = ParserHelper::getMagicArgs(
                $frame,
                $args
            );
            $level = intval(ParserHelper::arrayGet($values, 0, 0));
        } else {
            $level = 0;
        }

        $depth = $frame->depth;
        $level = ($level > 0) ? $depth - $level + 1 : -$level;
        if ($level > $depth || $level < 0) {
            return;
        }

        if ($level > 0) {
            // It should be impossible to alter the name of the current page without triggering a new parser expansion with new frames, so don't disable cache for that.
            $frame->setVolatile();
        }

        if ($level == $depth) {
            return $parser->getTitle();
        }

        while ($frame && $level > 0) {
            $frame = $frame->parent;
            $level--;
        }

        return isset($frame) ? $frame->title : null;
    }

    /**
     * getVarDirect
     *
     * @param PPFrame_Hash $frame
     * @param mixed $varName
     *
     * @return string|PPNode_Hash_Tree|null
     */
    public static function getVarDirect(PPTemplateFrame_Hash $frame, $varName)
    {
        $value = null;
        if (isset($frame->namedArgs)) {
            $value = ParserHelper::arrayGet($frame->namedArgs, $varName);
        }

        if (!isset($value) && isset($frame->numberedArgs)) {
            $value = ParserHelper::arrayGet($frame->numberedArgs, $varName);
        }

        return $value;
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
        }

        // Called even if numeric to catch cases where template called using, i.e. {{template|1=first}}
        if ($anyCase) {
            foreach (self::findAnyCaseNames($frame, $varName) as $anyCaseName) {
                unset($frame->namedArgs[$anyCaseName], $frame->namedExpansionCache[$anyCaseName]);
            }
        } else {
            unset($frame->namedArgs[$varName], $frame->namedExpansionCache[$varName]);
        }
    }
}
