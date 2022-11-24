<?php

// While it's good form to do this anyway, this line MUST be here or the entire wiki will come crashing to a halt
// whenever you try to add new magic words.
$magicWords = [];

$magicWords['en'] = [
	MetaTemplate::NA_NESTLEVEL => [0, 'nestlevel'],
	MetaTemplate::NA_SHIFT => [0, 'shift', 'shiftdown'],

	MetaTemplate::PF_DEFINE => [0, 'define'],
	MetaTemplate::PF_FULLPAGENAMEx => [0, 'FULLPAGENAMEx'],
	MetaTemplate::PF_INHERIT => [0, 'inherit'],
	MetaTemplate::PF_LOCAL => [0, 'local'],
	MetaTemplate::PF_NAMESPACEx => [0, 'NAMESPACEx'],
	MetaTemplate::PF_PAGENAMEx => [0, 'PAGENAMEx'],
	MetaTemplate::PF_PREVIEW => [0, 'preview'],
	MetaTemplate::PF_RETURN => [0, 'return'],
	MetaTemplate::PF_UNSET => [0, 'unset'],

	MetaTemplate::VR_FULLPAGENAME0 => [0, 'FULLPAGENAME0'],
	MetaTemplate::VR_NAMESPACE0 => [0, 'NAMESPACE0'],
	MetaTemplate::VR_NESTLEVEL => [0, 'NESTLEVEL'],
	MetaTemplate::VR_PAGENAME0 => [0, 'PAGENAME0'],
];

if (MetaTemplate::can(MetaTemplate::STTNG_ENABLECPT)) {
	$magicWords['en'] = array_merge($magicWords['en'], [
		MetaTemplateCategoryPage::TG_CATPAGETEMPLATE => [0, 'catpagetemplate'],

		MetaTemplateCategoryViewer::NA_IMAGE => [0, 'image'],
		MetaTemplateCategoryViewer::NA_PAGE => [0, 'page'],
		MetaTemplateCategoryViewer::NA_SORTKEY => [0, 'sortkey'],
		MetaTemplateCategoryViewer::NA_SUBCAT => [0, 'subcat'],

		MetaTemplateCategoryViewer::VAR_CATANCHOR => [0, 'catanchor'],
		MetaTemplateCategoryViewer::VAR_CATGROUP => [0, 'catgroup'],
		MetaTemplateCategoryViewer::VAR_CATLABEL => [0, 'catlabel'],
		MetaTemplateCategoryViewer::VAR_CATPAGE => [0, 'catpage'],
		MetaTemplateCategoryViewer::VAR_CATREDIRECT => [0, 'catredirect'],
		MetaTemplateCategoryViewer::VAR_CATSKIP => [0, 'catskip'],
		MetaTemplateCategoryViewer::VAR_CATSORTKEY => [0, 'catsortkey'],
		MetaTemplateCategoryViewer::VAR_CATTEXTPOST => [0, 'cattextpost'],
		MetaTemplateCategoryViewer::VAR_CATTEXTPRE => [0, 'cattextpre'],
	]);
}

if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
	$magicWords['en'] = array_merge($magicWords['en'], [
		MetaTemplateData::NA_NAMESPACE => [0, 'namespace'],
		MetaTemplateData::NA_ORDER => [0, 'order'],
		MetaTemplateData::NA_PAGENAME => [0, 'pagename'],
		MetaTemplateData::NA_SAVEMARKUP => [0, 'savemarkup'],
		MetaTemplateData::NA_SET => [0, 'set', 'subset'],

		MetaTemplateData::PF_LISTSAVED => [0, 'listsaved'],
		MetaTemplateData::PF_LOAD => [0, 'load'],
		MetaTemplateData::PF_LOADLIST => [0, 'loadlist'],
		MetaTemplateData::PF_SAVE => [0, 'save'],

		MetaTemplateData::TG_SAVEMARKUP => [0, 'savemarkup'],
	]);
}
