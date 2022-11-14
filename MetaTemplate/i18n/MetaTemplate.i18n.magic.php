<?php

// While it's good form to do this anyway, this line MUST be here or the entire wiki will come crashing to a halt
// whenever you try to add new magic words.
$magicWords = [];

$magicWords['en'] = [
	MetaTemplate::NA_NESTLEVEL => [0, 'nestlevel'],
	MetaTemplate::NA_SHIFT => [0, 'shift', 'shiftdown'],
	MetaTemplateData::NA_NAMESPACE => [0, 'namespace'],
	MetaTemplateData::NA_ORDER => [0, 'order'],
	MetaTemplateData::NA_PAGENAME => [0, 'pagename'],
	MetaTemplateData::NA_SAVEMARKUP => [0, 'savemarkup'],
	MetaTemplateData::NA_SET => [0, 'set', 'subset'],

	MetaTemplate::PF_DEFINE => [0, 'define'],
	MetaTemplate::PF_FULLPAGENAMEx => [0, 'FULLPAGENAMEx'],
	MetaTemplate::PF_INHERIT => [0, 'inherit'],
	MetaTemplate::PF_LOCAL => [0, 'local'],
	MetaTemplate::PF_NAMESPACEx => [0, 'NAMESPACEx'],
	MetaTemplate::PF_PAGENAMEx => [0, 'PAGENAMEx'],
	MetaTemplate::PF_PREVIEW => [0, 'preview'],
	MetaTemplate::PF_RETURN => [0, 'return'],
	MetaTemplate::PF_UNSET => [0, 'unset'],
	MetaTemplateData::PF_LISTSAVED => [0, 'listsaved'],
	MetaTemplateData::PF_LOAD => [0, 'load'],
	MetaTemplateData::PF_LOADLIST => [0, 'loadlist'],
	MetaTemplateData::PF_SAVE => [0, 'save'],

	MetaTemplateCategoryPage::TG_CATPAGETEMPLATE => [0, 'catpagetemplate'],
	MetaTemplateData::TG_SAVEMARKUP => [0, 'savemarkup'],

	MetaTemplate::VR_FULLPAGENAME0 => [0, 'FULLPAGENAME0'],
	MetaTemplate::VR_NAMESPACE0 => [0, 'NAMESPACE0'],
	MetaTemplate::VR_NESTLEVEL => [0, 'NESTLEVEL'],
	MetaTemplate::VR_PAGENAME0 => [0, 'PAGENAME0'],
];
