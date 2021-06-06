<?php
/*
namespace MediaWiki\Extension\MetaTemplate;
*/

/**
 * [Description ToMove]
 */
class ToMove
{
    const NA_EXPLODE = 'parserhelper-explode';
    const NA_NOWIKI = 'parserhelper-nowiki';
    const NA_SEED = 'tomove-seed';
    const NA_SEPARATOR = 'tomove-separator';

    const PF_ARG = 'tomove-arg'; // From DynamicFunctions
    const PF_IFEXISTX = 'tomove-ifexistx';
    const PF_INCLUDE = 'metatemplate-include';
    const PF_PICKFROM = 'tomove-pickfrom';
    const PF_RAND = 'tomove-rand'; // From DynamicFunctions
    const PF_SPLITARGS = 'metatemplate-splitargs';
    const PF_TRIMLINKS = 'tomove-trimlinks';
    const TG_CLEANSPACE = 'tomove-cleanspace';
    const TG_CLEANTABLE = 'tomove-cleantable';
    const TG_DISPLAYCODE = 'tomove-displaycode';
    const VR_SKINNAME = 'tomove-skinname'; // From DynamicFunctions

    private static $allMagicWords = [
        self::NA_EXPLODE,
        self::NA_NOWIKI,
        self::NA_SEED,
        self::NA_SEPARATOR,
    ];

    // From DynamicFunctions
    /**
     * doArg
     *
     * @param Parser $parser
     * @param string $name
     * @param string $default
     *
     * @return string
     */
    public static function doArg(Parser $parser, PPFrame $frame, array $args)
    {
        $values = ParserHelper::expandArray($frame, $args);
        $request = RequestContext::getMain()->getRequest();
        return $request->getVal($values[0], isset($values[1]) ? $values[1] : '');
    }

    /**
     * doIfExistX
     *
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array $args
     *
     * @return string
     */
    public static function doIfExistX(Parser $parser, PPFrame $frame, array $args)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );
        $titleText = trim($frame->expand(ParserHelper::arrayGet($values, 0, '')));
        $title = Title::newFromText($titleText);
        if ($title && ParserHelper::checkIfs($magicArgs)) {
            $then = ParserHelper::arrayGet($values, 1);
            $else = ParserHelper::arrayGet($values, 2);
            $retval = $else;
            $parser->getFunctionLang()->findVariantLink($titleText, $title, true);
            $ns = $title->getNamespace();
            if ($ns === NS_MEDIA) {
                if ($parser->incrementExpensiveFunctionCount()) {
                    $file = wfFindFile($title);
                    if ($file) {
                        $parser->mOutput->addImage(
                            $file->getName(),
                            $file->getTimestamp(),
                            $file->getSha1()
                        );

                        $retval = $file->exists() ? $then : $else;
                    }
                }
            } elseif ($ns === NS_SPECIAL) {
                $retval = SpecialPageFactory::exists($title->getDBkey()) ? $then : $else;
            } elseif (!$title->isExternal()) {
                $pdbk = $title->getPrefixedDBkey();
                $lc = LinkCache::singleton();
                if (
                    $lc->getGoodLinkID($pdbk) !== 0 ||
                    (!$lc->isBadLink($pdbk) &&
                        $parser->incrementExpensiveFunctionCount() &&
                        $title->exists())
                ) {
                    $retval = $then;
                }
            }
        }

        return trim($frame->expand($retval));
    }

    /**
     * doInclude
     *
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array $args
     *
     * @return string
     */
    public static function doInclude(Parser $parser, PPFrame $frame, array $args)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT
        );
        if (count($values) > 0 && ParserHelper::checkIfs($magicArgs)) {
            $nodes = [];
            foreach ($values as $pageName) {
                $pageName = $frame->expand($pageName);
                $t = Title::newFromText($pageName, NS_TEMPLATE);
                if ($t && $t->exists()) {
                    $nodes[] = self::createTemplateNode($pageName);
                }
            }

            return $frame->expand($nodes);
        }
    }

    // IMP: List is still randomized, even if all items will be returned.
    /**
     * doPickFrom
     *
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array $args
     *
     * @return string
     */
    public static function doPickFrom(Parser $parser, PPFrame $frame, array $args)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            ParserHelper::NA_IF,
            ParserHelper::NA_IFNOT,
            self::NA_SEED,
            self::NA_SEPARATOR
        );
        $npick = intval(array_shift($values));
        if ($npick <= 0 || count($values) == 0 || !ParserHelper::checkIfs($magicArgs)) {
            return '';
        }

        $separator = $frame->expand(ParserHelper::arrayGet($magicArgs, self::NA_SEPARATOR, "\n"));
        if (strlen($separator) > 1) {
            $separator = stripcslashes($separator);
            $first = $separator[0];
            if (in_array($first, ['\'', '`', '"']) && $first === substr($separator, -1, 1)) {
                $separator = substr($separator, 1, -1);
                $parser->addTrackingCategory('metatemplate-pickfromquotes-category');
            }
        }

        if (isset($magicArgs[self::NA_SEED])) {
            // Shuffle uses the basic randomizer, so we seed with srand if requested.
            // As of PHP 7.1.0, shuffle uses the mt randomizer, but srand is then aliased to mt_srand, so no urgent need to change it.
            srand(($frame->expand($magicArgs[self::NA_SEED])));
        }

        shuffle($values); // randomize list
        if ($npick < count($values)) {
            array_splice($values, $npick); // cut off unwanted items
        }

        $retval = [];
        foreach ($values as $value) {
            $retval[] = trim($frame->expand($value));
        }

        return implode($separator, $retval);
    }

    // IMP: Now allows a seed
    // IMP: Defaults to d6.
    /**
     * doRand
     *
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array $args
     *
     * @return int
     */
    public static function doRand(Parser $parser, PPFrame $frame, array $args)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            self::NA_SEED
        );
        $values = ParserHelper::expandArray($frame, $values);
        if (isset($magicArgs[self::NA_SEED])) {
            mt_srand(($frame->expand($magicArgs[self::NA_SEED])));
        }

        $low = ParserHelper::arrayGet($values, 0);
        if (count($values) == 1) {
            $high = $low;
            $low = 1;
        } else {
            $high = intval(ParserHelper::arrayGet($values, 1, $low));
        }

        if ($low != $high) {
            $frame->setVolatile();
        }

        return ($low > $high) ? mt_rand($high, $low) : mt_rand($low, $high);
    }

    // From DynamicFunctions
    /**
     * doSkinName
     *
     * @param Parser $parser
     *
     * @return string
     */
    public static function doSkinName(PPFrame $frame)
    {
        $frame->setVolatile();
        $skin = RequestContext::getMain()->getSkin();
        return $skin->getSkinName();
    }

    // IMP: Adds nowiki option to view actual template calls for debugging. Only works in preview mode by default; set to 'always' to have it display that way when saved.
    // IMP: All anonymous and named arguments in the list are added to each call.
    /**
     * doSplitArgs
     *
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array $args
     *
     * @return string
     */
    public static function doSplitArgs(Parser $parser, PPFrame $frame, array $args)
    {
        list($magicArgs, $values) = ParserHelper::getMagicArgs(
            $frame,
            $args,
            self::NA_NOWIKI,
            self::NA_EXPLODE,
            self::NA_SEPARATOR
        );

        // Figure out what we're dealing with and populate appropriately.
        $checkFormat = $frame->expand($values[1]);
        if (!is_numeric($checkFormat) && count($values) > 3) {
            // Old #explodeargs; can be deleted once all are converted.
            $values = ParserHelper::expandArray($frame, $values);
            $nargs = intval($values[3]);
            $templateName = $values[2];
            $separator = $checkFormat;
            $values = explode($separator, $values[0]);
            $parser->addTrackingCategory('metatemplate-tracking-explodeargs');
            $nowiki = false;
        } else {
            $nowiki = ParserHelper::arrayGet($magicArgs, self::NA_NOWIKI, false);
            $nowiki = $parser->getOptions()->getIsPreview() ? boolval($nowiki) : in_array($nowiki, ParserHelper::getMagicWordNames(ParserHelper::AV_ALWAYS));
            $templateName = $frame->expand($values[0]);
            $nargs = intval($checkFormat);
            $values = array_slice($values, 2);
            if (isset($magicArgs[self::NA_EXPLODE])) {
                $separator = ParserHelper::arrayGet($magicArgs, self::NA_SEPARATOR, ',');
                $values = array_merge(explode($separator, $magicArgs[self::NA_EXPLODE]), $values);
            }

            if (empty($values)) {
                $values = $frame->getNumberedArguments();
                foreach ($frame->getNamedArguments() as $key => $value) {
                    $numKey = intval($key);
                    if ($numKey > 0) {
                        $values[$numKey] = $value;
                    }
                }
            }
        }

        if ($nargs == 0) {
            $nargs = count($values);
        }

        list($named, $values) = self::splitNamedArgs($frame, $values);
        if (count($values) > 0) {
            $templates = [];
            for ($index = 0; $index < count($values); $index += $nargs) {
                $newTemplate = self::createTemplateNode($templateName);
                for ($paramNum = 0; $paramNum < $nargs; $paramNum++) {
                    $var = ParserHelper::arrayGet($values, $index + $paramNum, '');
                    $param = self::createPartNode($paramNum + 1, $var, true);
                    $newTemplate->addChild($param);
                }

                foreach ($named as $name => $value) {
                    $partNode = self::createPartNode($name, $value, false);
                    $newTemplate->addChild($partNode);
                }

                $templates[] = $newTemplate;
            }

            return $frame->expand($templates, $nowiki ? PPFrame::RECOVER_ORIG : 0);
        }
    }

    // IMP: Ignores Category and Image links unless they're forced links; better parsing of edge cases (nested links, nowiki, etc.).
    /**
     * doTrimLinks
     *
     * @param Parser $parser
     * @param PPFrame $frame
     * @param array $args
     *
     * @return string
     */
    public static function doTrimLinks(Parser $parser, PPFrame $frame, array $args)
    {
        if (!isset($args[0])) {
            return '';
        }

        $preprocessor = new Preprocessor_Hash($parser);
        $rootNode = $preprocessor->preprocessToObj($args[0], 1);
        self::trimLinksParseNode($parser, $rootNode);
        return $frame->expand($rootNode);
    }

    public static function init()
    {
        ParserHelper::cacheMagicWords(self::$allMagicWords);
    }

    /**
     * createPartNode
     *
     * @param string|int $name
     * @param mixed $value
     * @param bool $forceAnonymous
     *
     * @return PPNode_Hash_Tree
     */
    private static function createPartNode($name, $value, $forceAnonymous = false)
    {
        $nameNode = new PPNode_Hash_Tree('name');
        $anonymous = boolval(is_null($forceAnonymous) ? is_int($name) : $forceAnonymous);
        $nameChild = $anonymous
            ? new PPNode_Hash_Attr('index', intval($name))
            : new PPNode_Hash_Text($name);
        $nameNode->addChild($nameChild);
        $valueNode = ($value instanceof PPNode_Hash_Tree && $value->getName() === 'value')
            ? $value
            : PPNode_Hash_Tree::newWithText('value', $value);
        $newNode = new PPNode_Hash_Tree('part');
        $newNode->addChild($nameNode);
        if (!$anonymous) {
            $newNode->addChild(new PPNode_Hash_Text('='));
        }

        $newNode->addChild($valueNode);
        return $newNode;
    }

    /**
     * createTemplateNode
     *
     * @param mixed $templateName
     *
     * @return PPNode_Hash_Tree
     */
    private static function createTemplateNode($templateName)
    {
        $newTemplate = new PPNode_Hash_Tree('template');
        $titleNode = PPNode_Hash_Tree::newWithText('title', $templateName);
        $newTemplate->addChild($titleNode);

        return $newTemplate;
    }

    /**
     * splitNamedArgs
     *
     * @param PPFrame $frame
     * @param array|null $args
     *
     * @return array
     */
    private static function splitNamedArgs(PPFrame $frame, array $args = null)
    {
        $named = [];
        $unnamed = [];
        if (!is_null($args)) {
            foreach ($args as $arg) {
                list($name, $value) = ParserHelper::getKeyValue($frame, $arg);
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
     * trimLinksParseNode
     *
     * @param Parser $parser
     * @param PPNode $node
     *
     * @return void
     */
    private static function trimLinksParseNode(Parser $parser, PPNode $node)
    {
        if ($node instanceof PPNode_Hash_Text && $node == '[[') {
            $content = $node->getNextSibling();
            if ($content instanceof PPNode_Hash_Text) {
                $contentText = (string)$content;
                $close = strpos($contentText, ']]'); // Should always be the end of the content text.
                if ($close == strlen($contentText) - 2) {
                    $link = substr($contentText, 0, $close);
                    $split = explode('|', $link, 2);
                    $titleText = trim($split[0]);
                    $leadingColon = $titleText[0] == ':';
                    $title = Title::newFromText($titleText);
                    $ns = $title ? $title->getNamespace() : 0;
                    if ($leadingColon) {
                        $titleText = substr($titleText, 1);
                        if ($ns === NS_MEDIA) {
                            $leadingColon = false;
                        }
                    }

                    if ($leadingColon || !in_array($ns, [NS_CATEGORY, NS_FILE, NS_MEDIA, NS_SPECIAL])) {
                        $node->value = '';
                        $content->value = substr($contentText, $close + 2);
                        if (isset($split[1])) {
                            // If display text was provided, preserve formatting but put nowiki pairs at each end to break any accidental formatting that results.
                            $node->value = $parser->insertStripItem('<nowiki/>') . $split[1] . $parser->insertStripItem('<nowiki/>');
                        } else {
                            // For title-only links, formatting should not be applied at all, so just surround the entire thing with nowiki tags.
                            $text = $title ? $title->getPrefixedText() : $titleText;
                            $node->value = $parser->insertStripItem("<nowiki>$text</nowiki>");
                        }
                    }
                }
            }
        } elseif ($node instanceof PPNode_Hash_Tree) {
            $child = $node->getFirstChild();
            while ($child) {
                self::trimLinksParseNode($parser, $child);
                $child = $child->getNextSibling();
            }
        }
    }
}
