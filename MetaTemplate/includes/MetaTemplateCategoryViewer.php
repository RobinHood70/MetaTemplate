<?php

use Wikimedia\Rdbms\IResultWrapper;

/* In theory, this process could be optimized further by subdividing <catpagetemplate> into a section for pages and a
 * section for sets so that only the set portion is parsed inside the loop at the end of processTemplate(). Given the
 * syntax changes already being introduced in this version and the extra level of user knowledge that a pages/sets
 * style would require, I don't think it's especially useful.
 */

class MetaTemplateCategoryViewer extends CategoryViewer
{
    // CategoryViewer does not define these despite wide-spread internal usage in later versions, so we do. If that
    // changes in the future, these can be removed and the code altered, or they can be made synonyms for the CV names.
    public const CV_FILE = 'file';
    public const CV_PAGE = 'page';
    public const CV_SUBCAT = 'subcat';

    public const NA_IMAGE = 'metatemplate-image';
    public const NA_PAGE = 'metatemplate-page';
    public const NA_SORTKEY = 'metatemplate-sortkey';
    public const NA_SUBCAT = 'metatemplate-subcat';

    public const TG_CATPAGETEMPLATE = 'metatemplate-catpagetemplate';

    public const VAR_CATGROUP = 'metatemplate-catgroup';
    public const VAR_CATLABEL = 'metatemplate-catlabel';
    public const VAR_CATTEXTPOST = 'metatemplate-cattextpost';
    public const VAR_CATTEXTPRE = 'metatemplate-cattextpre';

    public const VAR_SETANCHOR = 'metatemplate-setanchor';
    public const VAR_SETLABEL = 'metatemplate-setlabel';
    public const VAR_SETPAGE = 'metatemplate-setpage';
    public const VAR_SETREDIRECT = 'metatemplate-setredirect';
    public const VAR_SETSEPARATOR = 'metatemplate-setseparator';
    public const VAR_SETSKIP = 'metatemplate-setskip';
    public const VAR_SETSORTKEY = 'metatemplate-setsortkey';
    public const VAR_SETTEXTPOST = 'metatemplate-settextpost';
    public const VAR_SETTEXTPRE = 'metatemplate-settextpre';

    private const KEY_BULK_LOAD = MetaTemplate::KEY_METATEMPLATE . '#bulkLoad';
    private const KEY_CPT_LOAD = MetaTemplate::KEY_METATEMPLATE . '#loadViaCPT';
    private const KEY_FRAME = MetaTemplate::KEY_METATEMPLATE . '#frame';
    private const KEY_PARSER = MetaTemplate::KEY_METATEMPLATE . '#parser';
    private const KEY_TEMPLATES = MetaTemplate::KEY_METATEMPLATE . '#templates';

    /** @var ?MagicWordArray */
    private static $catArgs = null;

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

    /** @var ParserOutput */
    private static $parserOutput = null;

    /** @var string[] */
    private  static $templates = [];

    public static function doCatPageTemplate(string $content, array $attributes, Parser $parser, PPFrame $frame = NULL): string
    {
        if ($parser->getTitle()->getNamespace() !== NS_CATEGORY || !strlen(trim($content))) {
            return '';
        }

        $output = $parser->getOutput();
        self::$parser = $parser;
        self::$parserOutput = $output;
        self::$frame = $frame;
        $output->setExtensionData(self::KEY_PARSER, $parser);
        $output->setExtensionData(self::KEY_FRAME, $frame);

        $attributes = ParserHelper::transformAttributes($attributes, self::$catArgs);
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

        $output->setExtensionData(self::KEY_TEMPLATES, self::$templates);

        // All communication with #load and its results is done via the parser's ExtensionData commands so as not to
        // corrupt anything in the frame.
        $output->setExtensionData(self::KEY_CPT_LOAD, true);

        // If a #load is present, this will parse it in a special bulk-loading mode and return the results in
        // $parser-->getExtensionData(self::KEY_BULK_LOAD).
        self::$parser->recursiveTagParse($content, $frame);
        $output->setExtensionData(self::KEY_CPT_LOAD, null);

        return '';
    }

    public static function hasTemplate(): bool
    {
        return !empty(self::$templates);
    }

    public static function init(ParserOutput $parserOutput = null)
    {
        if (!self::$parserOutput && $parserOutput) {
            // We got here via a the parser cache (Article::view(), case 2)
            self::$parserOutput = $parserOutput;
            self::$parser = self::$parser ?? $parserOutput->getExtensionData(self::KEY_PARSER);
            self::$frame = self::$frame ?? $parserOutput->getExtensionData(self::KEY_FRAME);
            self::$templates = $parserOutput->getExtensionData(self::KEY_TEMPLATES);
        }

        // While we could just import the global $wgContLang here, the global function still works and isn't deprecated
        // as of MediaWiki 1.40. In 1.32, however, MediaWiki introduces the method used on the commented out line, and
        // it seems likely they'll eventually make that the official method. Given that it's valid for so much longer
        // via this method, however, there's little point in versioning it via ParserHelper unless this code is used
        // outside our own wikis; we can just switch once we get to 1.32.
        self::$contLang = self::$contLang ?? wfGetLangObj(true);
        // self::$contLang = self::$contLang ?? MediaWikiServices::getInstance()->getContentLanguage();

        self::$catArgs = self::$catArgs ?? new MagicWordArray([self::NA_IMAGE, self::NA_PAGE, self::NA_SUBCAT]);
        self::$mwPagelength = self::$mwPagelength ?? MagicWord::get(MetaTemplateData::NA_PAGELENGTH);
        self::$mwPagename = self::$mwPagename ?? MagicWord::get(MetaTemplateData::NA_PAGENAME);
        self::$mwSet = self::$mwSet ?? MagicWord::get(MetaTemplateData::NA_SET);
        self::$mwSortkey = self::$mwSortkey ?? MagicWord::get(self::NA_SORTKEY);
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
                self::$parserOutput->setExtensionData(self::KEY_BULK_LOAD, $pageSets);
            } else {
                self::$parserOutput->setExtensionData(self::KEY_BULK_LOAD, null);
            }
        }

        return $pageSets;
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
    public static function onMetaTemplateBeforeLoadMain(Parser $parser, PPFrame $frame, array $magicArgs, array $values)
    {
        if (is_null(self::$parserOutput)) {
            $parserOutput = $parser->getOutput();
            self::$parserOutput = $parserOutput;
            self::$parser = self::$parser ?? $parser;
            self::$frame = self::$frame ?? $frame;
            self::$templates = $parserOutput->getExtensionData(self::KEY_TEMPLATES);
        }

        if (self::$parserOutput->getExtensionData(self::KEY_CPT_LOAD) ?? false) {
            unset($values[0]);
            $translations = MetaTemplate::getVariableTranslations($frame, $values, MetaTemplateData::SAVE_VARNAME_WIDTH);
            $anyCase = MetaTemplate::checkAnyCase($magicArgs);
            $varsToLoad = MetaTemplateData::getVarList($frame, $translations, $anyCase);
            self::$parserOutput->setExtensionData(MetaTemplate::KEY_PRELOADED, $varsToLoad);
        }
    }

    public function addImage(Title $title, $sortkey, $pageLength, $isRedirect = false)
    {
        $type = isset(self::$templates[self::CV_FILE])
            ? self::CV_FILE
            : (isset(self::$templates[self::CV_PAGE])
                ? self::CV_PAGE
                : null);
        $template = self::$templates[$type] ?? null;
        if (!$this->showGallery && !is_null($type) && !is_null($template)) {
            [$group, $link]  = $this->processTemplate($template, $type, $title, $sortkey, $pageLength, $isRedirect);
            $this->imgsNoGallery[] = $link;
            $this->imgsNoGallery_start_char[] = $group;
        } else {
            parent::addImage($title, $sortkey, $pageLength, $isRedirect);
        }
    }

    public function addPage($title, $sortkey, $pageLength, $isRedirect = false)
    {
        // RHshow('Add page: ', $title->getFullText());
        $type = self::CV_PAGE;
        $template = self::$templates[$type] ?? null;
        if (!is_null($template)) {
            [$group, $link]  = $this->processTemplate($template, self::CV_PAGE, $title, $sortkey, $pageLength, $isRedirect);
            $this->articles[] = $link;
            $this->articles_start_char[] = $group;
        } else {
            parent::addPage($title, $sortkey, $pageLength, $isRedirect);
        }
    }

    public function addSubcategoryObject(Category $cat, $sortkey, $pageLength)
    {
        $type = self::CV_SUBCAT;
        $template = self::$templates[$type] ?? null;
        if (!is_null($template)) {
            $title = $cat->getTitle();
            [$group, $link] = $this->processTemplate($template, self::CV_SUBCAT, $title, $sortkey, $pageLength);
            $this->children[] = $link;
            $this->children_start_char[] = $group;
        } else {
            parent::addSubcategoryObject($cat, $sortkey, $pageLength);
        }
    }

    public function finaliseCategoryState()
    {
        self::$parserOutput->setExtensionData(self::KEY_BULK_LOAD, null);
    }

    private static function createFrame(Title $title, MetaTemplateSet $set, ?string $sortkey, int $pageLength): PPTemplateFrame_Hash
    {
        $frame = self::$frame->newChild([], $title);
        MetaTemplate::setVar($frame, self::$mwPagelength->getSynonym(0), $pageLength);
        MetaTemplate::setVar($frame, self::$mwPagename->getSynonym(0), $title->getFullText());
        MetaTemplate::setVar($frame, self::$mwSet->getSynonym(0), $set->setName);
        MetaTemplate::setVar($frame, self::$mwSortkey->getSynonym(0), explode("\n", $sortkey)[0]);
        foreach ($set->variables as $varName => $varValue) {
            MetaTemplate::setVar($frame, $varName, $varValue->value);
        }

        return $frame;
    }

    private function processTemplate(string $template, string $type, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false): array
    {
        $output = self::$parserOutput;
        $allPages = $output->getExtensionData(self::KEY_BULK_LOAD) ?? false;

        /** @var ?MetaTemplateSet[] $setsFound */
        $setsFound = $allPages[$title->getArticleID()] ?? null;

        // RHshow('Sets found: ', count($setsFound), "\n", $setsFound);
        $defaultSet = $setsFound[''] ?? new MetaTemplateSet('');
        unset($setsFound['']);
        // RHshow('Sets found, sorted: ', count($setsFound), "\n", $setsFound);
        $catVars = $this->parseCatPageTemplate($template, $title, $defaultSet, $sortkey, $pageLength);

        /* $catGroup does not need sanitizing as MW runs it through htmlspecialchars later in the process.
         * Unfortunately, that means you can't make links without deriving formatList(), which can then call either
         * static::columnList() instead of self::columnList() and the same for shortList() so that those two methods
         * can be statically derived. Are we having fun yet?
         */
        $catGroup = $catVars->catGroup ?? ($type === self::CV_SUBCAT
            ? $this->getSubcategorySortChar($title, $sortkey)
            : self::$contLang->convert($this->collation->getFirstLetter($sortkey)));
        $catText = $catVars->catTextPre . $this->generateLink($type, $title, $isRedirect, $catVars->catLabel) . $catVars->catTextPost;
        $texts = [];
        if (count($setsFound) && (!is_null($catVars->setLabel) || !is_null($catVars->setPage))) {
            foreach (array_values($setsFound) as $setkey => $setValues) {
                // RHshow('Set: ', $setValues->setName, ' => ', $setValues);
                $setVars = $this->parseCatPageTemplate($template, $title, $setValues, null, -1);
                if ($setVars) {
                    $texts[$setVars->setSortKey . '.' . $setkey] = is_null($setVars->setPage)
                        ? $setVars->setLabel
                        : $this->generateLink(
                            $type,
                            $setVars->setPage ?? $title,
                            $setVars->setRedirect ?? $isRedirect,
                            $setVars->setLabel ?? $title->getFullText()
                        );
                }
            }
        }

        ksort($texts, SORT_NATURAL);
        $text = implode($catVars->setSeparator, $texts);
        if (strlen($text)) {
            $text = $catVars->setTextPre . $text . $catVars->setTextPost;
        }

        return [$catGroup, $catText . $text];
    }

    private function parseCatPageTemplate(string $template, Title $title, MetaTemplateSet $set, ?string $sortkey, int $pageLength): MetaTemplateCategoryVars
    {
        $frame = self::createFrame($title, $set, $sortkey, $pageLength);
        $templateOutput = self::$parser->recursiveTagParse($template, $frame);
        $retval = new MetaTemplateCategoryVars($frame, $title, $templateOutput);
        return $retval->setSkip ? null : $retval;
    }
}
