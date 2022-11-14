<?php

class MetaTemplateCategoryViewer extends CategoryViewer
{
    private static $templateData = [];

    public static function doCatPageTemplate(string $input, array $args, Parser $parser, PPFrame $frame = NULL): void
    {
        // page needs to be re-parsed everytime, otherwise categories get printed without the template being read
        $parser->getOutput()->updateCacheExpiry(0);
        $templateData = [
            'template' => $input,
            'parser' => $parser,
            'frame' => $frame
        ];

        // Manually transfer args so that case can be changed.
        // Also be careful not to let an arg override the basic templatedata.
        $doPage = true;
        $doSubcat = true;
        foreach ($args as $argName => $argValue) {
            $argName = strtolower($argName);
            if ($argName === 'stdpage' || $argName === 'subcatonly') {
                $doPage = false;
            } elseif ($argName === 'pageonly' || $argName === 'stdsubcat') {
                $doSubcat = false;
            } elseif (!isset($templateData[$argName])) {
                $templateData[$argName] = $argValue;
            }
        }

        if ($doPage) {
            MetaTemplateCategoryViewer::$templateData['page'] = $templateData;
        }

        if ($doSubcat) {
            self::$templateData['subcat'] = $templateData;
        }
    }

    public static function hasTemplate(): bool
    {
        return (bool)count(self::$templateData);
    }

    /**
     * Add a page in the image namespace
     * This is here mainly so I remember that the function exists
     * But at the moment, there isn't anything to tweak in processing
     * * If it's an image gallery, there's no text or start_char
     * * Otherwise, parent already simply calls addPage, which is where my customizations will kick in
     *
     * 2014-09-22: At least as of 1.19, the above assertion is untrue for __NOGALLERY__
     * so added appropriate processing. -RH70
     */
    function addImage(Title $title, $sortkey, $pageLength, $isRedirect = false)
    {
        if ($this->showGallery) {
            parent::addImage($title, $sortkey, $pageLength, $isRedirect);
            return;
        }

        $retsets = $this->processTemplate($title, $sortkey, $pageLength, $isRedirect);
        foreach ($retsets as $retvals) {
            $this->imgsNoGallery[] = $retvals['link'];
            $this->imgsNoGallery_start_char[] = $retvals['start_char'];
        }
    }

    /**
     * Add a miscellaneous page
     */
    function addPage($title, $sortkey, $pageLength, $isRedirect = false)
    {
        if (!isset(self::$templateData['page'])) {
            parent::addPage($title, $sortkey, $pageLength, $isRedirect);
            return;
        }

        $retsets = $this->processTemplate($title, $sortkey, $pageLength, $isRedirect);
        foreach ($retsets as $retvals) {
            $this->articles[] = $retvals['link'];
            $this->articles_start_char[] = $retvals['start_char'];
        }
    }

    function addSubcategoryObject(Category $cat, $sortkey, $pageLength)
    {
        if (!isset(self::$templateData['subcat'])) {
            parent::addSubcategoryObject($cat, $sortkey, $pageLength);
            return;
        }

        $title = $cat->getTitle();
        $retsets = $this->processTemplate($title, $sortkey, $pageLength, false, true);
        foreach ($retsets as $retvals) {
            $this->children[] = $retvals['link'];
            $this->children_start_char[] = $retvals['start_char'];
        }
    }
}
