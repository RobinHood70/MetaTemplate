<?php

class MetaTemplateCategoryPage extends CategoryPage
{
	function closeShowCategory()
	{
		$this->mCategoryViewerClass = MetaTemplateCategoryViewer::hasTemplate()
			? MetaTemplateCategoryViewer::class
			: (class_exists('CategoryTreeCategoryViewer', false)
				? 'CategoryTreeCategoryViewer'
				: 'CategoryViewer');
		parent::closeShowCategory();
	}
}
