<?php
class MetaTemplateCategoryVars
{
    /** @var string */
    public $catAnchor;

    /** @var string */
    public $catLabel;

    /** @var Title */
    public $catPage;

    /** @var bool */
    public $catRedirect;

    /** @var bool */
    public $catSkip;

    /** @var string */
    public $catTextPost;

    /** @var string */
    public $catTextPre;

    /** @var string */
    public $sortkey;

    /** @var ?MagicWordArray */
    private static $catParams;

    public function __construct(PPFrame $frame, Title $title, string $templateOutput, string $sortkey, bool $isRedirect)
    {
        if (!isset(self::$catParams))
            self::$catParams = new MagicWordArray([
                MetaTemplateCategoryViewer::VAR_CATANCHOR,
                MetaTemplateCategoryViewer::VAR_CATGROUP,
                MetaTemplateCategoryViewer::VAR_CATLABEL,
                MetaTemplateCategoryViewer::VAR_CATPAGE,
                MetaTemplateCategoryViewer::VAR_CATREDIRECT,
                MetaTemplateCategoryViewer::VAR_CATSKIP,
                // MetaTemplateCategoryViewer::VAR_CATSORTKEY,
                MetaTemplateCategoryViewer::VAR_CATTEXTPOST,
                MetaTemplateCategoryViewer::VAR_CATTEXTPRE
            ]);

        $args = ParserHelper::getInstance()->transformAttributes($frame->getArguments(), self::$catParams);

        // RHshow('Args: ', $args);
        $catPage = isset($args[MetaTemplateCategoryViewer::VAR_CATPAGE])
            ? Title::newFromText($args[MetaTemplateCategoryViewer::VAR_CATPAGE])
            : $title;
        $catAnchor = $args[MetaTemplateCategoryViewer::VAR_CATANCHOR] ?? '';
        if (strLen($catAnchor) && $catAnchor[0] === '#') {
            $catAnchor = substr($catAnchor, 1);
        }

        if (!empty($catAnchor)) {
            $catPage = $catPage->createFragmentTarget($catAnchor);
        }

        $this->catAnchor = $catAnchor;
        $this->catPage = $catPage;

        // Take full text of catpagetemplate ($templateOutput) only if #catlabel is not defined. If that's blank,
        // use the normal text.
        $this->catLabel = $args[MetaTemplateCategoryViewer::VAR_CATLABEL] ??
            ($templateOutput === ''
                ? $catPage->getFullText()
                : $templateOutput);

        $this->catGroup = $args[MetaTemplateCategoryViewer::VAR_CATGROUP] ?? null;
        $this->sortkey = $sortkey;

        $this->catRedirect = $args[MetaTemplateCategoryViewer::VAR_CATREDIRECT] ?? $isRedirect;
        $this->catSkip = $args[MetaTemplateCategoryViewer::VAR_CATSKIP] ?? false;
        $this->catTextPost = $args[MetaTemplateCategoryViewer::VAR_CATTEXTPOST] ?? '';
        $this->catTextPre = $args[MetaTemplateCategoryViewer::VAR_CATTEXTPRE] ?? '';
    }

    public function getCatGroup(CategoryViewer $cv, string $type, Language $contLang)
    {
        return $type === MetaTemplateCategoryViewer::CV_SUBCAT
            ? $cv->getSubcategorySortChar($this->catPage, $this->sortkey)
            : $contLang->convert($cv->collation->getFirstLetter($this->sortkey));
    }
}
