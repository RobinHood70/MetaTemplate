<?php
class MetaTemplateCategoryVars
{
    /** @var string */
    public $catGroup;

    /** @var string */
    public $catLabel;

    /** @var string */
    public $catTextPost;

    /** @var string */
    public $catTextPre;

    /** @var string */
    public $setLabel;

    /** @var Title */
    public $setPage;

    /** @var bool */
    public $setRedirect;

    /** @var string */
    public $setSeparator;

    /** @var bool */
    public $setSkip;

    /** @var string */
    public $setSortKey;

    /** @var string */
    public $setTextPost;

    /** @var string */
    public $setTextPre;

    /** @var ?MagicWordArray */
    private static $catParams;

    public function __construct(PPFrame $frame, Title $title, string $templateOutput)
    {
        if (!isset(self::$catParams)) {
            self::$catParams = new MagicWordArray([
                MetaTemplateCategoryViewer::VAR_CATGROUP,
                MetaTemplateCategoryViewer::VAR_CATLABEL,
                MetaTemplateCategoryViewer::VAR_CATTEXTPOST,
                MetaTemplateCategoryViewer::VAR_CATTEXTPRE,

                MetaTemplateCategoryViewer::VAR_SETANCHOR,
                MetaTemplateCategoryViewer::VAR_SETLABEL,
                MetaTemplateCategoryViewer::VAR_SETPAGE,
                MetaTemplateCategoryViewer::VAR_SETREDIRECT,
                MetaTemplateCategoryViewer::VAR_SETSEPARATOR,
                MetaTemplateCategoryViewer::VAR_SETSKIP,
                MetaTemplateCategoryViewer::VAR_SETSORTKEY,
                MetaTemplateCategoryViewer::VAR_SETTEXTPOST,
                MetaTemplateCategoryViewer::VAR_SETTEXTPRE
            ]);
        }

        // While these aren't actually attributes, the function does exactly what's needed.
        $args = ParserHelper::transformAttributes($frame->getArguments(), self::$catParams);

        $this->catGroup = $args[MetaTemplateCategoryViewer::VAR_CATGROUP] ?? null;
        $this->catLabel = isset($args[MetaTemplateCategoryViewer::VAR_CATLABEL])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_CATLABEL])
            : ($templateOutput === ''
                ? $title->getFullText()
                : Sanitizer::removeHTMLtags($templateOutput));
        $this->catTextPost = isset($args[MetaTemplateCategoryViewer::VAR_CATTEXTPOST])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_CATTEXTPOST])
            : '';
        $this->catTextPre = isset($args[MetaTemplateCategoryViewer::VAR_CATTEXTPRE])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_CATTEXTPRE])
            : '';
        $this->setSkip = $args[MetaTemplateCategoryViewer::VAR_SETSKIP] ?? false;
        if ($this->setSkip) {
            return;
        }

        $setPage = $args[MetaTemplateCategoryViewer::VAR_SETPAGE] ?? null;
        $setPage = $setPage === $title->getFullText()
            ? null
            : Title::newFromText($setPage);
        $setAnchor = $args[MetaTemplateCategoryViewer::VAR_SETANCHOR] ?? null;
        if (!empty($setAnchor) && $setAnchor[0] === '#') {
            $setAnchor = substr($setAnchor, 1);
        }

        if (!empty($setAnchor)) {
            // Cannot be merged with previous check since we might be altering the value.
            $setPage = ($setPage ?? $title)->createFragmentTarget($setAnchor);
        }

        $this->setPage = $setPage;

        // Take full text of setpagetemplate ($templateOutput) only if setlabel is not defined. If that's blank, use
        // the normal text.
        // Temporarily accepts catlabel as synonymous with setlabel if setlabel is not defined. This is done solely for
        // backwards compatibility and it can be removed once all existing catpagetemplates have been converted.
        $setLabel =
            $args[MetaTemplateCategoryViewer::VAR_SETLABEL] ??
            $args[MetaTemplateCategoryViewer::VAR_CATLABEL] ??
            ($templateOutput === ''
                ? null
                : $templateOutput);
        if (!is_null($setLabel)) {
            $setLabel = Sanitizer::removeHTMLtags($setLabel);
        }

        $this->setLabel = $setLabel;
        $this->setRedirect = $args[MetaTemplateCategoryViewer::VAR_SETREDIRECT] ?? null;
        $this->setSeparator = isset($args[MetaTemplateCategoryViewer::VAR_SETSEPARATOR])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_SETSEPARATOR])
            : null;
        $this->setSortKey = isset($args[MetaTemplateCategoryViewer::VAR_SETSORTKEY])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_SETSORTKEY])
            : $setLabel ?? $setPage ?? $title->getFullText();
        $this->setTextPost = isset($args[MetaTemplateCategoryViewer::VAR_SETTEXTPOST])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_SETTEXTPOST])
            : '';
        $this->setTextPre = isset($args[MetaTemplateCategoryViewer::VAR_SETTEXTPRE])
            ? Sanitizer::removeHTMLtags($args[MetaTemplateCategoryViewer::VAR_SETTEXTPRE])
            : '';
    }
}
