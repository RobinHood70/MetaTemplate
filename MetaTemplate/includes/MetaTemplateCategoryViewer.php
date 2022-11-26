<?php

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
    public const VAR_CATSORTKEY = 'metatemplate-catsortkey';
    public const VAR_CATTEXTPOST = 'metatemplate-cattextpost';
    public const VAR_CATTEXTPRE = 'metatemplate-cattextpre';

    // CategoryViewer does not define these, despite wide-spread internal usage in later versions, so we do. If that
    // changes in the future, these can be removed and the code altered, or they can be made synonyms for the CV names.
    private const CV_IMAGE = 'image';
    private const CV_PAGE = 'page';
    private const CV_SUBCAT = 'subcat';

    /** @var ?MagicWordArray */
    private static $catArgs = null;

    /** @var ?MagicWordArray */
    private static $catParams = null;

    /** @var Language */
    private static $contLang = null;

    /** @var PPFrame */
    private static $frame = null;

    /** @var ?MagicWord */
    private static $mwNamespace = null;

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

        // Page needs to be re-parsed everytime, otherwise categories get printed without the template being read.
        $parser->getOutput()->updateCacheExpiry(0);

        self::$parser = $parser;
        self::$frame = $frame;
        self::$mwNamespace = self::$mwNamespace ?? MagicWord::get(MetaTemplateData::NA_NAMESPACE);
        self::$mwPagelength = self::$mwPagelength ?? MagicWord::get(MetaTemplateData::NA_PAGELENGTH);
        self::$mwPagename = self::$mwPagename ?? MagicWord::get(MetaTemplateData::NA_PAGENAME);
        self::$mwSet = self::$mwSet ?? MagicWord::get(MetaTemplateData::NA_SET);
        self::$mwSortkey = self::$mwSortkey ?? MagicWord::get(self::NA_SORTKEY);

        $attributes = ParserHelper::getInstance()->transformAttributes($attributes, self::$catArgs);

        $none = !isset($attributes[self::NA_IMAGE]) && !isset($attributes[self::NA_PAGE]) && !isset($attributes[self::NA_SUBCAT]);
        if (isset($attributes[self::NA_IMAGE]) || $none) {
            self::$templates[self::CV_IMAGE] = $content;
        }

        if (isset($attributes[self::NA_PAGE]) || $none) {
            self::$templates[self::CV_PAGE] = $content;
        }

        if (isset($attributes[self::NA_SUBCAT]) || $none) {
            self::$templates[self::CV_SUBCAT] = $content;
        }

        return '';
    }

    public static function hasTemplate(): bool
    {
        return count(self::$templates);
    }

    public static function init()
    {
        self::$catArgs = new MagicWordArray([self::NA_PAGE, self::NA_SUBCAT]);
        self::$catParams = new MagicWordArray([
            self::VAR_CATANCHOR,
            self::VAR_CATGROUP,
            self::VAR_CATLABEL,
            self::VAR_CATPAGE,
            self::VAR_CATREDIRECT,
            self::VAR_CATSKIP,
            self::VAR_CATSORTKEY,
            self::VAR_CATTEXTPOST,
            self::VAR_CATTEXTPRE
        ]);

        self::$contLang = wfGetLangObj(true);
    }

    public function addImage(Title $title, $sortkey, $pageLength, $isRedirect = false)
    {
        $type = isset(self::$templates[self::CV_IMAGE])
            ? self::CV_IMAGE
            : (isset(self::$templates[self::CV_PAGE])
                ? self::CV_PAGE
                : null);
        if (!$this->showGallery && !is_null($type)) {
            $retsets = $this->processTemplate($type, $title, $sortkey, $pageLength, $isRedirect);
            foreach ($retsets as $retvals) {
                $this->imgsNoGallery[] = $retvals['link'];
                $this->imgsNoGallery_start_char[] = $retvals['start_char'];
            }
        } else {
            parent::addImage($title, $sortkey, $pageLength, $isRedirect);
        }
    }

    public function addPage($title, $sortkey, $pageLength, $isRedirect = false)
    {
        if (isset(self::$templates[self::CV_PAGE])) {
            $retsets = $this->processTemplate(self::CV_PAGE, $title, $sortkey, $pageLength, $isRedirect);
            foreach ($retsets as $retvals) {
                $this->articles[] = $retvals['link'];
                $this->articles_start_char[] = $retvals['start_char'];
            }
        } else {
            parent::addPage($title, $sortkey, $pageLength, $isRedirect);
        }
    }

    public function addSubcategoryObject(Category $cat, $sortkey, $pageLength)
    {
        if (isset(self::$templates[self::CV_SUBCAT])) {
            $title = $cat->getTitle();
            $retsets = $this->processTemplate(self::CV_SUBCAT, $title, $sortkey, $pageLength);
            foreach ($retsets as $retvals) {
                $this->children[] = $retvals['link'];
                $this->children_start_char[] = $retvals['start_char'];
            }
        } else {
            parent::addSubcategoryObject($cat, $sortkey, $pageLength);
        }
    }

    private function createFrame(Title $title, string $set, string $sortkey, int $pageLength): PPTemplateFrame_Hash
    {
        $frame = self::$frame->newChild([], $title);
        MetaTemplate::setVar($frame, self::$mwPagelength->getSynonym(0), strval($pageLength));
        MetaTemplate::setVar($frame, self::$mwPagename->getSynonym(0), $title->getFullText());
        MetaTemplate::setVar($frame, self::$mwSet->getSynonym(0), $set);
        MetaTemplate::setVar($frame, self::$mwSortkey->getSynonym(0), explode("\n", $sortkey)[0]);

        return $frame;
    }

    private function parseCatVariables(string $type, Title $title, string $templateOutput, bool $isRedirect, string $sortkey, array $args): array
    {
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
        $catLabel = $args[self::VAR_CATLABEL] ?? null;
        if (is_null($catLabel)) {
            $catLabel = $templateOutput === ''
                ? $catPage->getFullText()
                : $templateOutput;
        }

        $link = $this->generateLink($type, $catPage, $catRedirect ?? $isRedirect, $catLabel);
        if (isset($args[self::VAR_CATTEXTPRE])) {
            $link = $args[self::VAR_CATTEXTPRE] . ' ' . $link;
        }

        if (isset($args[self::VAR_CATTEXTPOST])) {
            $link .= ' ' . $args[self::VAR_CATTEXTPOST];
        }

        $catGroup = $args[self::VAR_CATGROUP] ?? $type === self::CV_SUBCAT
            ? $this->getSubcategorySortChar($catPage, $sortkey)
            : self::$contLang->convert(self::$contLang->firstChar($sortkey));;

        return [
            'start_char' => $catGroup,
            'link' => $link
        ];
    }

    private function processSet(array &$setList, string $type, $template, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false, string $set = NULL, array $setValues = [])
    {
        $frame = $this->createFrame($title, $set, $sortkey, $pageLength);
        foreach ($setValues as $setKey => $setValue) {
            $varValue = $setValue->getValue();
            if ($setValue->getParseOnLoad()) {
                $varValue = self::$parser->preprocessToDom($varValue());
                $varValue = $frame->expand($varValue);
            }

            if ($varValue !== false) {
                MetaTemplate::setVar($frame, $setKey, $varValue);
            }
        }

        $templateOutput = self::$parser->recursiveTagParse($template, $frame);
        $args = ParserHelper::getInstance()->transformAttributes($frame->getArguments(), self::$catParams);

        if (!($args[self::VAR_CATSKIP] ?? false)) {
            $setList[] = $this->parseCatVariables($type, $title, $templateOutput, $isRedirect, $sortkey, $args);
        }
    }

    private function processTemplate(string $type, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false): array
    {
        $template = self::$templates[$type] ?? '';
        if (!strlen($template)) {
            return [];
        }

        $frame = $this->createFrame($title, $sortkey, '', $pageLength);

        // Communicate to #load via back channel so as not to corrupt anything in the frame.
        $output = self::$parser->getOutput();
        $output->setExtensionData(MetaTemplate::KEY_CPT_LOAD, true);
        $trialRun = self::$parser->recursiveTagParse($template, $frame);
        $output->setExtensionData(MetaTemplate::KEY_CPT_LOAD, null);
        $args = ParserHelper::getInstance()->transformAttributes($frame->getArguments(), self::$catParams);
        if (!empty($args[self::VAR_CATSKIP])) {
            return [];
        }

        $setsFound = $output->getExtensionData(MetaTemplate::KEY_BULK_LOAD) ?? false;
        // RHshow('Sets found: ', $setsFound);
        $output->setExtensionData(MetaTemplate::KEY_BULK_LOAD, null);
        if (!is_array($setsFound)) {
            // There was no #load on the page, so return a single node with the appropriate values.
            // Also returns if there's a #load but no corresponding data.
            return [$this->parseCatVariables($type, $title, $trialRun, $isRedirect, $sortkey, $args)];
        }

        // The code below adds multiple entries to a category listing where there would normally be only one, but
        // the code in the base CategoryViewer just works on an arbitrary array of entries, presumably to handle
        // the final set of category items being smaller than all others, so it has no issues with the extra
        // entries.
        $setList = [];
        if (count($setsFound)) {
            foreach ($setsFound as $set => $setValues) {
                // RHshow('Set: ', is_null($set) ? '<null>' : "'$set'", ' => ', $setValues);
                $this->processSet($setList, $type, $template, $title, $sortkey, $pageLength, $isRedirect, $set, $setValues);
            }

            ksort($setList, SORT_NATURAL);
            // RHshow('Count setList: ', count($setList), 'Cat label: ', $catLabel, "\nSet list: ", $setList);
        }

        return $setList;
    }
}
