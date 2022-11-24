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
    // changes in the future, these can be removed or copied from the original.
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

    private function processTemplate(string $type, Title $title, string $sortkey, int $pageLength, bool $isRedirect = false, string $curSet = NULL, array $setValues = []): array
    {
        $template = self::$templates[$type] ?? '';
        if (!strlen($template)) {
            return [];
        }

        $parentFrame = self::$frame;
        $frame = $parentFrame->newChild([], $title);
        $splitkey = explode("\n", $sortkey);
        $output = self::$parser->getOutput();
        MetaTemplate::setVar($frame, self::$mwPagename->getSynonym(0), $title->getFullText());
        MetaTemplate::setVar($frame, self::$mwSortkey->getSynonym(0), $splitkey[0]);
        if (is_null($curSet)) {
            // We communicate back-channel so as not to corrupt anything in the frame.
            $output->setExtensionData(MetaTemplate::STAR_SET, '*');
            $setName = '';
        } else {
            $setName = $curSet;
        }

        MetaTemplate::setVar($frame, self::$mwSet->getSynonym(0), $setName);
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

        // This does not check if the page is a category, since there's nothing we can do about it at this point.
        // Not yet handling possibility that the new title might be a redlink, or that pageLength might not be relevant any more.
        $catPage = isset($args[self::VAR_CATPAGE])
            ? Title::newFromText($args[self::VAR_CATPAGE])
            : $title;

        $catSkip = $args[self::VAR_CATSKIP] ?? null;
        if ($catSkip) {
            $retvals = [];
        } else {
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

            $retvals = [
                'start_char' => $catGroup,
                'link' => $link
            ];
        }

        // This is where the function gets called recursively to fill in multiple sets, if need be.
        $setList = [];
        $setsFound = $output->getExtensionData(MetaTemplate::STAR_SET) ?? [];
        // RHshow('Sets found: ', $setsFound);
        if (!isset($curSet) && $setsFound !== '*' && !empty($setsFound)) {
            // RHshow('Current set: ', is_null($curSet) ? '<null>' : "'$curSet'", "\nSets found: ", $setsFound);
            // RHshow($templateOutput);
            foreach ($setsFound as $set => $setValues) {
                // RHshow('Set: ', is_null($set) ? '<null>' : "'$set'", ' => ', $setValues);
                $setList = array_merge($setList, $this->processTemplate($type, $title, $sortkey, $pageLength, $isRedirect, $set, $setValues));
            }

            ksort($setList, SORT_NATURAL);
            // RHshow('Count setList: ', count($setList), 'Cat label: ', $catLabel, "\nSet list: ", $setList);

            // NB I am NOT doing anything to warn category page that multiple objects are being added
            // when it only expects one... there is code in the catpage that is supposed to be there
            // to handle such surprises
        } elseif (empty($catSkip)) {
            if (isset($curSet)) {
                $setList[$curSet] = $retvals;
            } else {
                $setList[0] = $retvals;
            }
        }

        return $setList;
    }
}
