<?php

use Wikimedia\Rdbms\IResultWrapper;

/** @todo For now, this code is sufficient, but like its predecessor, it can produce incorrect counts of items in the
 *  category, sometimes leading to unnecessary "refreshCounts" jobs (see CategoryViewer->getMessageCounts). Try moving
 *  the set entries to an array of their own, to be retrieved after the parent entry is done. This will remove them
 *  from consideration for the count and allow things to work as intended.
 */
class MetaTemplateCategoryViewer extends CategoryViewer
{
    public const NA_IMAGE = 'metatemplate-image';
    public const NA_PAGE = 'metatemplate-page';
    public const NA_SORTKEY = 'metatemplate-sortkey';
    public const NA_SUBCAT = 'metatemplate-subcat';

    public const VAR_CATANCHOR = 'metatemplate-catanchor';
    public const VAR_CATGROUP = 'metatemplate-catgroup';
    public const VAR_CATLABEL = 'metatemplate-catlabel';
    public const VAR_CATPAGE = 'metatemplate-catpage';
    public const VAR_CATREDIRECT = 'metatemplate-catredirect';
    public const VAR_CATSKIP = 'metatemplate-catskip';
    // public const VAR_CATSORTKEY = 'metatemplate-catsortkey';
    public const VAR_CATTEXTPOST = 'metatemplate-cattextpost';
    public const VAR_CATTEXTPRE = 'metatemplate-cattextpre';

    // CategoryViewer does not define these despite wide-spread internal usage in later versions, so we do. If that
    // changes in the future, these can be removed and the code altered, or they can be made synonyms for the CV names.
    private const CV_FILE = 'file';
    private const CV_PAGE = 'page';
    private const CV_SUBCAT = 'subcat';

    private const KEY_BULK_LOAD = MetaTemplate::KEY_METATEMPLATE . '#bulkLoad';
    private const KEY_CPT_LOAD = MetaTemplate::KEY_METATEMPLATE . '#loadViaCPT';

    /** @var ?MagicWordArray */
    private static $catArgs = null;

    /** @var ?MagicWordArray */
    private static $catParams = null;

    /** @var Language */
    private static $contLang = null;

    /** @var PPFrame */
    private static $frame = null;

    /** @var ?MagicWord */
    private static $mwPagelength = null;

    /** @var ?MagicWord */
    private  static $mwPagename = null;

    /** @var ?MagicWord */
    private  static $mwSet = null;

    /** @var ?MagicWord */
    private  static $mwSortkey = null;

    /** @var Parser */
    private static $parser = null;

    /** @var string[] */
    private  static $templates = [];

    public static function doCatPageTemplate(string $content, array $attributes, Parser $parser, PPFrame $frame = NULL): string
    {
        if ($parser->getTitle()->getNamespace() !== NS_CATEGORY || !strlen(trim($content))) {
            return '';
        }

        $output = $parser->getOutput();

        // Page needs to be re-parsed every time, otherwise categories are printed from cache instead of being fully processed.
        $output->updateCacheExpiry(0);

        self::$parser = $parser;
        self::$frame = $frame;
        self::$mwPagelength = self::$mwPagelength ?? MagicWord::get(MetaTemplateData::NA_PAGELENGTH);
        self::$mwPagename = self::$mwPagename ?? MagicWord::get(MetaTemplateData::NA_PAGENAME);
        self::$mwSet = self::$mwSet ?? MagicWord::get(MetaTemplateData::NA_SET);
        self::$mwSortkey = self::$mwSortkey ?? MagicWord::get(self::NA_SORTKEY);

        $attributes = ParserHelper::getInstance()->transformAttributes($attributes, self::$catArgs);
        $none = !isset($attributes[self::NA_IMAGE]) && !isset($attributes[self::NA_PAGE]) && !isset($attributes[self::NA_SUBCAT]);
        if (isset($attributes[self::NA_IMAGE]) || $none) {
            self::$templates[self::CV_FILE] = $content;
        }

        if (isset($attributes[self::NA_PAGE]) || $none) {
            self::$templates[self::CV_PAGE] = $content;
        }

        if (isset($attributes[self::NA_SUBCAT]) || $none) {
            self::$templates[self::CV_SUBCAT] = $content;
        }

        // All communication with #load and its results is done via the parser's ExtensionData commands so as not to
        // corrupt anything in the frame.
        $output->setExtensionData(self::KEY_CPT_LOAD, true);

        // If a #load is present, this will parse it in a special bulk-loading mode and return the results in
        // $parser-->getExtensionData(self::KEY_BULK_LOAD).
        self::$parser->recursiveTagParse($content, $frame);
        $output->setExtensionData(self::KEY_CPT_LOAD, null);

        return '';
    }

    /**
     * This variant of #load strictly pulls out the values to be pre-loaded. It's made active temporarily while
     * doCatPageTemplate() is being evaluated, then reverts immediately after.
     *
     * @param Parser $parser The parser in use.
     * @param PPFrame $frame The frame in use.
     * @param array $magicArgs Function arguments (`case=any` is the only one used here).
     * @param array $values All other function arguments. These are the ones that are evaluted for pre-loading.
     *
     * @return bool Whether the function ran or not. The only way this will return false is if it's called outside of
     *              #load, in which case it will revert to the normal #load routine.
     *
     */
    public static function onMetaTemplateDoLoadMain(Parser $parser, PPFrame $frame, array $magicArgs, array $values): bool
    {
        if (!($parser->getOutput()->getExtensionData(self::KEY_CPT_LOAD) ?? false)) {
            return false;
        }

        $translations = MetaTemplate::getVariableTranslations($frame, $values, MetaTemplateData::SAVE_VARNAME_WIDTH);
        $anyCase = ParserHelper::getInstance()->checkAnyCase($magicArgs);
        $varsToLoad = MetaTemplateData::getVarList($frame, $translations, $anyCase);
        $parser->getOutput()->setExtensionData(MetaTemplate::KEY_PRELOADED, $varsToLoad);

        return true;
    }

    public static function hasTemplate(): bool
    {
        return !empty(self::$templates);
    }

    public static function init()
    {
        self::$catArgs = new MagicWordArray([self::NA_IMAGE, self::NA_PAGE, self::NA_SUBCAT]);
        self::$catParams = new MagicWordArray([
            self::VAR_CATANCHOR,
            self::VAR_CATGROUP,
            self::VAR_CATLABEL,
            self::VAR_CATPAGE,
            self::VAR_CATREDIRECT,
            self::VAR_CATSKIP,
            // self::VAR_CATSORTKEY,
            self::VAR_CATTEXTPOST,
            self::VAR_CATTEXTPRE
        ]);

        // While we could just import the global $wgContLang here, the global function still works and isn't deprecated
        // as of MediaWiki 1.40. In 1.32, however, MediaWiki introduces the method used on the commented out line, and
        // it seems likely they'll eventually make that the official method. Given that it's valid for so much longer
        // via this method, however, there's little point in versioning it via ParserHelper unless this code is used
        // outside our own wikis; we can just switch once we get to 1.32.
        self::$contLang = wfGetLangObj(true);
        // self::$contLang = MediaWikiServices::getInstance()->getContentLanguage();
    }

    public static function onDoCategoryQuery(string $type, IResultWrapper $result)
    {
        $pageSets = [];
        if (self::$parser) {
            if ($result->numRows() > 0) {
                for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
                    $pageSets[$row['page_id']] = [];
                }

                $result->rewind();
                MetaTemplateSql::getInstance()->catQuery($pageSets);
                self::$parser->getOutput()->setExtensionData(self::KEY_BULK_LOAD, $pageSets);
            } else {
                self::$parser->getOutput()->setExtensionData(self::KEY_BULK_LOAD, null);
            }
        }

        return $pageSets;
    }

    public function addImage(Title $title, $sortkey, $pageLength, $isRedirect = false)
    {
        $type = isset(self::$templates[self::CV_FILE])
            ? self::CV_FILE
            : (isset(self::$templates[self::CV_PAGE])
                ? self::CV_PAGE
                : null);
        if (!$this->showGallery && !is_null($type)) {
            $retset = $this->processTemplate($type, $title, $sortkey, $pageLength, $isRedirect);
            $this->imgsNoGallery[] = $retset['link'];
            $this->imgsNoGallery_start_char[] = $retset['start_char'];
        } else {
            parent::addImage($title, $sortkey, $pageLength, $isRedirect);
        }
    }

    public function addPage($title, $sortkey, $pageLength, $isRedirect = false)
    {
        if (isset(self::$templates[self::CV_PAGE])) {
            $retset = $this->processTemplate(self::CV_PAGE, $title, $sortkey, $pageLength, $isRedirect);
            $this->articles[] = $retset['link'];
            $this->articles_start_char[] = $retset['start_char'];
        } else {
            parent::addPage($title, $sortkey, $pageLength, $isRedirect);
        }
    }

    public function addSubcategoryObject(Category $cat, $sortkey, $pageLength)
    {
        if (isset(self::$templates[self::CV_SUBCAT])) {
            $title = $cat->getTitle();
            $retset = $this->processTemplate(self::CV_SUBCAT, $title, $sortkey, $pageLength);
            $this->children[] = $retset['link'];
            $this->children_start_char[] = $retset['start_char'];
        } else {
            parent::addSubcategoryObject($cat, $sortkey, $pageLength);
        }
    }

    public function finaliseCategoryState()
    {
        self::$parser->getOutput()->setExtensionData(self::KEY_BULK_LOAD, null);
    }

    private static function createFrame(Title $title, MetaTemplateSet $set, string $sortkey, int $pageLength): PPTemplateFrame_Hash
    {
        $frame = self::$frame->newChild([], $title);
        MetaTemplate::setVar($frame, self::$mwPagelength->getSynonym(0), strval($pageLength));
        MetaTemplate::setVar($frame, self::$mwPagename->getSynonym(0), $title->getFullText());
        MetaTemplate::setVar($frame, self::$mwSet->getSynonym(0), $set->getSetName());
        MetaTemplate::setVar($frame, self::$mwSortkey->getSynonym(0), explode("\n", $sortkey)[0]);
        foreach ($set->getVariables() as $varName => $varValue) {
            MetaTemplate::setVar($frame, $varName, $varValue->getValue());
        }

        return $frame;
    }

    private function parseCatVariables(string $type, Title $title, string $templateOutput, bool $isRedirect, array $args): array
    {
        // RHshow('Args: ', $args);
        $catPage = isset($args[self::VAR_CATPAGE])
            ? Title::newFromText($args[self::VAR_CATPAGE])
            : $title;

        $catAnchor = $args[self::VAR_CATANCHOR] ?? '';
        if (strLen($catAnchor) && $catAnchor[0] === '#') {
            $catAnchor = substr($catAnchor, 1);
        }

        if (!empty($catAnchor)) {
            $catPage = $catPage->createFragmentTarget($catAnchor);
        }

        // Take full text of catpagetemplate ($templateOutput) only if #catlabel is not defined. If that's blank,
        // use the normal text.
        $catLabel = $args[self::VAR_CATLABEL] ??
            ($templateOutput === ''
                ? $catPage->getFullText()
                : $templateOutput);

        $catRedirect = $args[self::VAR_CATREDIRECT] ?? $isRedirect;
        $link = $this->generateLink($type, $catPage, $catRedirect, $catLabel);
        if (isset($args[self::VAR_CATTEXTPRE])) {
            $link = $args[self::VAR_CATTEXTPRE] . ' ' . $link;
        }

        if (isset($args[self::VAR_CATTEXTPOST])) {
            $link .= ' ' . $args[self::VAR_CATTEXTPOST];
        }

        return [$catPage, $link];
    }

    private function processTemplate(string $type, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false): array
    {
        $template = self::$templates[$type] ?? '';
        if (!strlen($template)) {
            return [];
        }

        $output = self::$parser->getOutput();
        $allPages = $output->getExtensionData(self::KEY_BULK_LOAD) ?? false;

        /** @var MetaTemplateSet[] $setsFound */
        $setsFound = $allPages[$title->getArticleID()] ?? null;

        // RHshow('Sets found: ', count($setsFound), "\n", $setsFound);
        $defaultSet = $setsFound[''] ?? new MetaTemplateSet('');
        unset($setsFound['']);
        ksort($setsFound, SORT_NATURAL);
        // RHshow('Sets found sorted: ', count($setsFound), "\n", $setsFound);

        [$templateOutput, $args] = $this->parseCatPageTemplate($template, $title, $defaultSet, $sortkey, $pageLength);
        [$catPage, $text] = $this->parseCatVariables($type, $title, $templateOutput, $isRedirect, $args);
        $catGroup = $args[self::VAR_CATGROUP] ?? $type === self::CV_SUBCAT
            ? $this->getSubcategorySortChar($catPage, $sortkey)
            : self::$contLang->convert($this->collation->getFirstLetter($sortkey));

        if (empty($args[self::VAR_CATSKIP] ?? false) && count($setsFound)) {
            // The code below adds multiple entries to a category listing where there would normally be only one, but
            // the code in the base CategoryViewer just works on an arbitrary array of entries, presumably to handle
            // the final set of category items being smaller than all others, so it has no issues with the extra
            // entries.
            $atStart = true;
            /** @var MetaTemplateSet $setValues */
            foreach (array_values($setsFound) as $setValues) {
                // RHshow("Set: $set => ", $setValues);
                [$templateOutput, $args] = $this->parseCatPageTemplate($template, $title, $setValues, $sortkey, $pageLength);
                if ($args[self::VAR_CATSKIP] ?? false) {
                    break;
                }

                $text .= $this->parseCatVariables($type, $title, $templateOutput, $isRedirect, $args)[1];
                if ($atStart) {
                    $atStart = false;
                }
            }
        }

        return [
            'start_char' => $catGroup,
            'link' => $text
        ];
    }

    private function parseCatPageTemplate(string $template, Title $title, MetaTemplateSet $set, string $sortkey, int $pageLength)
    {
        $frame = self::createFrame($title, $set, $sortkey, $pageLength);
        $templateOutput = self::$parser->recursiveTagParse($template, $frame);
        $args = ParserHelper::getInstance()->transformAttributes($frame->getArguments(), self::$catParams);

        return empty($args[self::VAR_CATSKIP])
            ? [$templateOutput, $args]
            : ['', []];
    }
}
