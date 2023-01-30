<?php

// While it's good form to do this anyway, this line MUST be here or the entire wiki will come crashing to a halt
// whenever you try to add new magic words.
$magicWords = [];
$magicWords['en'] = [
	MetaTemplate::AV_ANY => [0, 'any'],
	MetaTemplate::NA_CASE => [0, 'case'],
];

if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEPAGENAMES)) {
	$magicWords['en'] += [
		MetaTemplate::PF_FULLPAGENAMEx => [1, 'FULLPAGENAMEx'],
		MetaTemplate::PF_NAMESPACEx => [1, 'NAMESPACEx'],
		MetaTemplate::PF_PAGENAMEx => [1, 'PAGENAMEx'],

		MetaTemplate::VR_FULLPAGENAME0 => [1, 'FULLPAGENAME0'],
		MetaTemplate::VR_NAMESPACE0 => [1, 'NAMESPACE0'],
		MetaTemplate::VR_NESTLEVEL => [1, 'NESTLEVEL'],
		MetaTemplate::VR_NESTLEVEL_VAR => [1, 'nestlevel'],
		MetaTemplate::VR_PAGENAME0 => [1, 'PAGENAME0']
	];
}

if (MetaTemplate::can(MetaTemplate::STTNG_ENABLECPT)) {
	$magicWords['en'] += [
		MetaTemplateCategoryViewer::NA_IMAGE => [0, 'image'],
		MetaTemplateCategoryViewer::NA_PAGE => [0, 'page'],
		MetaTemplateCategoryViewer::NA_SORTKEY => [0, 'sortkey'],
		MetaTemplateCategoryViewer::NA_SUBCAT => [0, 'subcat'],

		MetaTemplateCategoryViewer::TG_CATPAGETEMPLATE => [0, 'catpagetemplate'],

		MetaTemplateCategoryViewer::VAR_CATGROUP => [0, 'catgroup'],
		MetaTemplateCategoryViewer::VAR_CATLABEL => [0, 'catlabel'],
		MetaTemplateCategoryViewer::VAR_CATTEXTPOST => [0, 'cattextpost'],
		MetaTemplateCategoryViewer::VAR_CATTEXTPRE => [0, 'cattextpre'],

		MetaTemplateCategoryViewer::VAR_SETANCHOR => [0, 'setanchor'],
		MetaTemplateCategoryViewer::VAR_SETLABEL => [0, 'setlabel'],
		MetaTemplateCategoryViewer::VAR_SETPAGE => [0, 'setpage'],
		MetaTemplateCategoryViewer::VAR_SETREDIRECT => [0, 'setredirect'],
		MetaTemplateCategoryViewer::VAR_SETSEPARATOR => [0, 'setseparator'],
		MetaTemplateCategoryViewer::VAR_SETSKIP => [0, 'setskip'],
		MetaTemplateCategoryViewer::VAR_SETSORTKEY => [0, 'setsortkey'],
		MetaTemplateCategoryViewer::VAR_SETTEXTPOST => [0, 'settextpost'],
		MetaTemplateCategoryViewer::VAR_SETTEXTPRE => [0, 'settextpre'],
	];
}

if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
	$magicWords['en'] += [
		MetaTemplateData::NA_NAMESPACE => [0, 'namespace'],
		MetaTemplateData::NA_ORDER => [0, 'order'],
		MetaTemplateData::NA_PAGELENGTH => [0, 'pagelength'],
		MetaTemplateData::NA_PAGENAME => [0, 'pagename'],
		MetaTemplateData::NA_SAVEMARKUP => [0, 'savemarkup'],
		MetaTemplateData::NA_SET => [0, 'set', 'subset'],

		MetaTemplateData::PF_LISTSAVED => [0, 'listsaved'],
		MetaTemplateData::PF_LOAD => [0, 'load'],
		MetaTemplateData::PF_PRELOAD => [0, 'preload'],
		MetaTemplateData::PF_SAVE => [0, 'save'],

		MetaTemplateData::TG_SAVEMARKUP => [0, 'savemarkup'],
	];
}

if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDEFINE)) {
	$magicWords['en'] += [
		MetaTemplate::NA_SHIFT => [0, 'shift', 'shiftdown'],

		MetaTemplate::PF_DEFINE => [0, 'define'],
		MetaTemplate::PF_INHERIT => [0, 'inherit'],
		MetaTemplate::PF_LOCAL => [0, 'local'],
		MetaTemplate::PF_PREVIEW => [0, 'preview'],
		MetaTemplate::PF_RETURN => [0, 'return'],
		MetaTemplate::PF_UNSET => [0, 'unset'],
	];
}
