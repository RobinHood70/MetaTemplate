<?php

class MetaTemplateCategoryPage extends CategoryPage
{
	function closeShowCategory()
	{
		$this->mCategoryViewerClass = MetaTemplate::$catViewer::hasTemplate()
			? MetaTemplate::$catViewer
			: (class_exists('CategoryTreeCategoryViewer')
				? 'CategoryTreeCategoryViewer'
				: 'CategoryViewer');
		parent::closeShowCategory();
	}
}
