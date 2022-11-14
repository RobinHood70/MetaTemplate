<?php

class MetaTemplateCategoryPage extends CategoryPage
{
    const TG_CATPAGETEMPLATE = 'metatemplate-catpagetemplate';

    function closeShowCategory()
    {
        $this->mCategoryViewerClass = MetaTemplateCategoryViewer::hasTemplate()
            ? 'MetaTemplateCategoryViewer'
            : (class_exists('CategoryTreeCategoryViewer', false)
                ? 'CategoryTreeCategoryViewer'
                : 'CategoryViewer');
        parent::closeShowCategory();
    }
}
