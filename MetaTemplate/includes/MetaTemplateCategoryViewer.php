<?php

use Elastica\Exception\InvalidException;
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

    private const KEY_CPTDATA = MetaTemplate::KEY_METATEMPLATE . '#cptData';

    /** @var Language */
    private static $contLang = null;

    /** @var ?PPFrame_Hash */
    private static $frame = null;

    /** @var ?string */
    private static $mwPagelength = null;

    /** @var ?string */
    private  static $mwPagename = null;

    /** @var ?string */
    private  static $mwSet = null;

    /** @var ?string */
    private  static $mwSortkey = null;

    /** @var ?Parser */
    private static $parser = null;

    /** @var ?ParserOutput */
    private static $parserOutput = null;

    /** @var ?string[] */
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

        static $magicWords;
        $magicWords = $magicWords ?? new MagicWordArray([
            self::NA_IMAGE,
            self::NA_PAGE,
            self::NA_SUBCAT
        ]);

        $attributes = ParserHelper::transformAttributes($attributes, $magicWords);
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

        $output->setExtensionData(MetaTemplateData::KEY_IGNORE_SET, true);
        $parser->recursiveTagParse($content); // We don't care about the results, just that any #preload gets parsed.
        $output->setExtensionData(MetaTemplateData::KEY_IGNORE_SET, null);
        $output->setExtensionData(self::KEY_CPTDATA, [$parser, $frame, self::$templates]);
        return '';
    }

    public static function hasTemplate(): bool
    {
        return !empty(self::$templates);
    }

    public static function init(ParserOutput $parserOutput = null)
    {
        // Article::view();
        if (!self::$parserOutput && $parserOutput) {
            // We got here via the parser cache (Article::view(), case 2), so reload everything we don't have.
            /** @todo Can we actually cache the results instead of the objects, as was probably intended? */
            self::$parserOutput = $parserOutput;
            $cptData = $parserOutput->getExtensionData(self::KEY_CPTDATA);
            if ($cptData) {
                self::$parser = self::$parser ?? $cptData[0];
                self::$frame = self::$frame ?? $cptData[1];
                self::$templates = self::$templates ?? $cptData[2];
            }
        }

        // While we could just import the global $wgContLang here, the global function still works and isn't deprecated
        // as of MediaWiki 1.40. In 1.32, however, MediaWiki introduces the method used on the commented out line, and
        // it seems likely they'll eventually make that the official method. Given that it's valid for so much longer
        // via this method, however, there's little point in versioning it via ParserHelper unless this code is used
        // outside our own wikis; we can just switch once we get to 1.32.
        self::$contLang = self::$contLang ?? wfGetLangObj(true);
        // self::$contLang = self::$contLang ?? MediaWikiServices::getInstance()->getContentLanguage();
        self::$mwPagelength = self::$mwPagelength ?? MagicWord::get(MetaTemplateData::NA_PAGELENGTH)->getSynonym(0);
        self::$mwPagename = self::$mwPagename ?? MagicWord::get(MetaTemplateData::NA_PAGENAME)->getSynonym(0);
        self::$mwSet = self::$mwSet ?? MagicWord::get(MetaTemplateData::NA_SET)->getSynonym(0);
        self::$mwSortkey = self::$mwSortkey ?? MagicWord::get(self::NA_SORTKEY)->getSynonym(0);
    }

    public static function onDoCategoryQuery(string $type, IResultWrapper $result)
    {
        if (!self::$parser || $result->numRows() === 0 || !class_exists('MetaTemplateData', false)) {
            return;
        }

        /** @var MetaTemplateSet[] $varNames */
        $varNames = self::$parserOutput->getExtensionData(MetaTemplateData::KEY_PRELOAD);
        if (!$varNames) {
            return;
        }

        $varNames = array_keys($varNames['']->variables ?? []);
        #RHshow('Has varnames', $varNames);
        self::$parserOutput->setExtensionData(MetaTemplateData::KEY_BULK_LOAD, null);
        for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
            $pageIds[$row['page_id']] = new MetaTemplatePage($row['page_namespace'], $row['page_title']);
        }

        $result->rewind();

        MetaTemplateSql::getInstance()->catQuery($pageIds, $varNames ?? []);
        self::$parserOutput->setExtensionData(MetaTemplateData::KEY_BULK_LOAD, $pageIds);
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
        #RHshow('Add page', $title->getFullText());
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
        self::$parserOutput->setExtensionData(MetaTemplateData::KEY_BULK_LOAD, null);
    }

    private static function createFrame(Title $title, MetaTemplateSet $set, ?string $sortkey, int $pageLength): PPTemplateFrame_Hash
    {
        $frame = self::$frame->newChild([], $title);
        MetaTemplate::setVar($frame, self::$mwPagelength, (string)$pageLength);
        MetaTemplate::setVar($frame, self::$mwPagename, $title->getFullText());
        MetaTemplate::setVar($frame, self::$mwSet, $set->setName);
        MetaTemplate::setVar($frame, self::$mwSortkey, explode("\n", $sortkey)[0]);
        foreach ($set->variables as $varName => $varValue) {
            MetaTemplate::setVar($frame, $varName, $varValue->value);
        }

        return $frame;
    }

    private function processTemplate(string $template, string $type, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false): array
    {
        $output = self::$parserOutput;
        $allPages = $output->getExtensionData(MetaTemplateData::KEY_BULK_LOAD) ?? false;

        /** @var ?MetaTemplatePage $mtPage */
        $setsFound = $allPages[$title->getArticleID()]->sets ?? [];
        #RHshow('Sets found', count($setsFound), "\n", $setsFound);

        $defaultSet = $setsFound[''] ?? new MetaTemplateSet('');
        unset($setsFound['']);
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
                #RHshow('Set', $setValues->setName, ' => ', $setValues);
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
