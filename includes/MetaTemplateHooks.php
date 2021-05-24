<?php
// namespace MediaWiki\Extension\MetaTemplate;
use MediaWiki\MediaWikiServices;
// use MediaWiki\DatabaseUpdater;

// TODO: Add {{#define/local/preview:a=b|c=d}}
/**
 * [Description MetaTemplateHooks]
 */
class MetaTemplateHooks
{
	/**
	 * onPageContentSaveComplete
	 *
	 * @param mixed $wikiPage
	 * @param mixed $user
	 * @param mixed $mainContent
	 * @param mixed $summaryText
	 * @param mixed $isMinor
	 * @param mixed $isWatch
	 * @param mixed $section
	 * @param mixed $flags
	 * @param mixed $revision
	 * @param mixed $status
	 * @param mixed $originalRevId
	 * @param mixed $undidRevId
	 *
	 * @return void
	 */
	public static function onPageContentSaveComplete($wikiPage, $user, $mainContent, $summaryText, $isMinor, $isWatch, $section, $flags, $revision, $status, $originalRevId, $undidRevId)
	{
		MetaTemplateData::saveCache();
	}

	// Initial table setup/modifications from v1.
	/**
	 * onLoadExtensionSchemaUpdates
	 *
	 * @param DatabaseUpdater $updater
	 *
	 * @return void
	 */
	public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater)
	{
		$dir = dirname(__DIR__);
		if (MetaTemplate::can('EnableData')) {
			$db = $updater->getDB();
			if ($db->textFieldSize(MetaTemplateData::TABLE_SET, 'mt_set_subset') < 50) {
				// MW 1.30-
				foreach (['id', 'page_id', 'rev_id', 'subset'] as $field) {
					$updater->modifyExtensionField(MetaTemplateData::TABLE_SET, "mt_set_$field", "$dir/sql/patch-" . MetaTemplateData::TABLE_SET . ".$field.sql");
				}

				foreach (['id', 'parsed', 'value'] as $field) {
					$updater->modifyExtensionField(MetaTemplateData::TABLE_DATA, "mt_save_$field", "$dir/sql/patch-" . MetaTemplateData::TABLE_DATA . ".$field.sql");
				}

				$updater->dropExtensionField(MetaTemplateData::TABLE_SET, 'time', "$dir/sql/patch-" . MetaTemplateData::TABLE_SET . ".time.sql");
				// MW 1.31+
				// $updater->modifyExtensionTable( $saveSet, "$dir/sql/patch-$saveSet.sql" );
				// $updater->modifyExtensionTable( $saveData, "$dir/sql/patch-$saveData.sql" );
			}
		} else {
			// Always run both unconditionally in case only one or the other was created previously.
			// Updater will automatically skip each if the table exists.
			$updater->addExtensionTable(MetaTemplateData::TABLE_SET, "$dir/sql/" . MetaTemplateData::TABLE_SET . '.sql');
			$updater->addExtensionTable(MetaTemplateData::TABLE_DATA, "$dir/sql/" . MetaTemplateData::TABLE_DATA . '.sql');
		}
	}

	// This is the best place to disable individual magic words;
	// To disable all magic words, disable the hook that calls this function
	/**
	 * onMagicWordwgVariableIDs
	 *
	 * @param array $aCustomVariableIds
	 *
	 * @return void
	 */
	public static function onMagicWordwgVariableIDs(array &$aCustomVariableIds)
	{
		$aCustomVariableIds[] = MetaTemplate::VR_FULLPAGENAME0;
		$aCustomVariableIds[] = MetaTemplate::VR_NAMESPACE0;
		$aCustomVariableIds[] = MetaTemplate::VR_NESTLEVEL;
		$aCustomVariableIds[] = MetaTemplate::VR_PAGENAME0;
		$aCustomVariableIds[] = ToMove::VR_SKINNAME;
	}

	/**
	 * onParserAfterTidy
	 *
	 * @param Parser $parser
	 * @param mixed $text
	 *
	 * @return void
	 */
	/*
	public static function onParserAfterTidy(Parser $parser, &$text)
	{
		MetaTemplateData::saveCache();
	}
	*/

	// Register any render callbacks with the parser
	/**
	 * onParserFirstCallInit
	 *
	 * @param Parser $parser
	 *
	 * @return void
	 */
	public static function onParserFirstCallInit(Parser $parser)
	{
		self::initParserFunctions($parser);
		self::initTagFunctions($parser);
		ParserHelper::init();
		MetaTemplate::init();
		ToMove::init();
	}

	/**
	 * onParserGetVariableValueSwitch
	 *
	 * @param Parser $parser
	 * @param array $variableCache
	 * @param mixed $magicWordId
	 * @param mixed $ret
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function onParserGetVariableValueSwitch(Parser $parser, array &$variableCache, $magicWordId, &$ret, PPFrame $frame)
	{
		switch ($magicWordId) {
			case MetaTemplate::VR_FULLPAGENAME0:
				$ret = MetaTemplate::doFullPageNameX($parser, $frame);
				break;
			case MetaTemplate::VR_NAMESPACE0:
				$ret = MetaTemplate::doNamespaceX($parser, $frame);
				break;
			case MetaTemplate::VR_NESTLEVEL:
				$ret = MetaTemplate::doNestLevel($frame);
				break;
			case MetaTemplate::VR_PAGENAME0:
				$ret = MetaTemplate::doPageNameX($parser, $frame);
				break;
			case ToMove::VR_SKINNAME:
				$ret = ToMove::doSkinName($frame);
				break;
		}
	}

	/**
	 * onRegister
	 *
	 * @return void
	 */
	public static function onRegister()
	{
		// This should work until at least 1.33 and I'm pretty sure 1.34. As of 1.35, it will fail, and the path
		// forward is unclear. We might be able to override the Parser class itself with a custom one, or we may have
		// to modify the source files to insert our own parser and/or preprocessor.
		global $wgParserConf;
		$wgParserConf['preprocessorClass'] = "MetaTemplatePreprocessor";
	}

	/**
	 * initParserFunctions
	 *
	 * @param Parser $parser
	 *
	 * @return void
	 */
	private static function initParserFunctions(Parser $parser)
	{
		$parser->setFunctionHook(MetaTemplate::PF_DEFINE, 'MetaTemplate::doDefine', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(MetaTemplate::PF_FULLPAGENAMEx, 'MetaTemplate::doFullPageNameX', SFH_OBJECT_ARGS | SFH_NO_HASH);
		$parser->setFunctionHook(MetaTemplate::PF_INHERIT, 'MetaTemplate::doInherit', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(MetaTemplate::PF_LOCAL, 'MetaTemplate::doLocal', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(MetaTemplate::PF_NAMESPACEx, 'MetaTemplate::doNamespaceX', SFH_OBJECT_ARGS | SFH_NO_HASH);
		$parser->setFunctionHook(MetaTemplate::PF_PAGENAMEx, 'MetaTemplate::doPageNameX', SFH_OBJECT_ARGS | SFH_NO_HASH);
		$parser->setFunctionHook(MetaTemplate::PF_PREVIEW, 'MetaTemplate::doPreview', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(MetaTemplate::PF_RETURN, 'MetaTemplate::doReturn', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(MetaTemplate::PF_UNSET, 'MetaTemplate::doUnset', SFH_OBJECT_ARGS);

		$parser->setFunctionHook(ToMove::PF_ARG, 'ToMove::doArg', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(ToMove::PF_IFEXISTX, 'ToMove::doIfExistX', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(ToMove::PF_INCLUDE, 'ToMove::doInclude', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(ToMove::PF_PICKFROM, 'ToMove::doPickFrom', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(ToMove::PF_RAND, 'ToMove::doRand', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(ToMove::PF_SPLITARGS, 'ToMove::doSplitargs', SFH_OBJECT_ARGS);
		$parser->setFunctionHook(ToMove::PF_TRIMLINKS, 'ToMove::doTrimLinks', SFH_OBJECT_ARGS);

		if (MetaTemplate::can('EnableData')) {
			// $parser->setFunctionHook( MetaTemplateData::PF_LISTSAVED, 'MetaTemplateData::doListsaved', SFH_OBJECT_ARGS );
			$parser->setFunctionHook(MetaTemplateData::PF_LOAD, 'MetaTemplateData::doLoad', SFH_OBJECT_ARGS);
			$parser->setFunctionHook(MetaTemplateData::PF_SAVE, 'MetaTemplateData::doSave', SFH_OBJECT_ARGS);
		}
	}

	/**
	 * initTagFunctions
	 *
	 * @param Parser $parser
	 *
	 * @return void
	 */
	private static function initTagFunctions(Parser $parser)
	{
		self::setAllSynonyms($parser, MetaTemplateData::NA_SAVEMARKUP, 'MetaTemplateData::doSaveMarkupTag');
		// $parser->setHook(ToMove::TG_CLEANSPACE, 'efMetaTemplateCleanspace');
		// $parser->setHook(ToMove::TG_CLEANTABLE, 'efMetaTemplateCleantable');
		// $parser->setHook(ToMove::TG_DISPLAYCODE, 'efMetaTemplateDisplaycode');
		if (MetaTemplate::can('EnableCatPageTemplate')) {
			// $parser->setHook(MetaTemplate::TG_CATPAGETEMPLATE, 'MetaTemplateInit::efMetaTemplateCatPageTemplate');
		}
	}

	/**
	 * setAllSynonyms
	 *
	 * @param Parser $parser
	 * @param mixed $id
	 * @param callable $callback
	 *
	 * @return void
	 */
	private static function setAllSynonyms(Parser $parser, $id, callable $callback)
	{
		foreach (MagicWord::get($id)->getSynonyms() as $synonym) {
			$parser->setHook($synonym, $callback);
		}
	}
}
