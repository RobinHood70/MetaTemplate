<?php
// namespace MediaWiki\Extension\MetaTemplate;
// use MediaWiki\DatabaseUpdater;

// TODO: Add {{#define/local/preview:a=b|c=d}}
class MetaTemplateHooks
{
	const OLDSET_TABLE = 'mt_save_set';
	const OLDDATA_TABLE = 'mt_save_data';

	public static function migrateDataTable(DatabaseUpdater $updater, $dir)
	{
		$db = $updater->getDB();
		if (!$db->tableExists(self::OLDDATA_TABLE)) {
			$updater->addExtensionTable(MetaTemplateSql::DATA_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
			$updater->addExtensionUpdate([__CLASS__, 'migrateSet']);
		}
	}

	public static function migrateSetTable(DatabaseUpdater $updater, $dir)
	{
		$db = $updater->getDB();
		if (!$db->tableExists(self::OLDSET_TABLE)) {
			$updater->addExtensionTable(MetaTemplateSql::SET_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
			$updater->addExtensionUpdate([__CLASS__, 'migrateSet']);
		}
	}

	public static function onArticleDeleteComplete(WikiPage &$article, User &$user, $reason, $id, $content, LogEntry $logEntry, $archivedRevisionCount)
	{
		MetaTemplateSql::getInstance()->deleteVariables($article->getTitle());
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
		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
			$db = $updater->getDB();
			if (!$db->tableExists(MetaTemplateSql::SET_TABLE)) {
				$updater->addExtensionTable(MetaTemplateSql::SET_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
			}

			$updater->addExtensionUpdate([[__CLASS__, 'migrateSetTable'], $dir]);

			if (!$db->tableExists(MetaTemplateSql::DATA_TABLE)) {
				$updater->addExtensionTable(MetaTemplateSql::DATA_TABLE, "$dir/sql/create-" . MetaTemplateSql::DATA_TABLE . '.sql');
			}

			$updater->addExtensionUpdate([[__CLASS__, 'migrateDataTable'], $dir]);
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
	}

	public static function onMetaTemplateSetBypassVars(array &$bypassVars)
	{
		// TODO: This function is a placeholder until UespCustomCode is rewritten, at which point this can be
		// transferred there.
		// Going with hard-coded values, since these are unlikely to change, even if we transfer them to other
		// languages. If we do want to translate them, it's changed easily enough at that time.
		$bypassVars[] = 'ns_base';
		$bypassVars[] = 'ns_id';
	}

	public static function onParserAfterTidy(Parser $parser, &$text)
	{
		$output = $parser->getOutput();
		// getTimeSinceStart is a kludge to detect if this is the real page we're processing or some small part of it
		// that we don't care about. Saving varibles here is also a kludge. While it could use some more checking,
		// there didn't seem to be anywhere that only occurred on save that also occurred when doing a refreshLinks
		// operation.
		if (!$parser->getOptions()->getIsPreview() && !is_null($output->getTimeSinceStart('wall'))) {
			$pageVars = MetaTemplateData::getPageVariables($output);
			MetaTemplateSql::getInstance()->saveVariables($parser->getTitle(), $pageVars);
		}
	}

	// Register any render callbacks with the parser
	/**
	 * onParserFirstCallInit
	 *
	 * @param Parser $parser
	 *
	 * @return void
	 *
	 */
	public static function onParserFirstCallInit(Parser $parser)
	{
		self::initParserFunctions($parser);
		self::initTagFunctions($parser);
		MetaTemplate::init();
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
				$ret = MetaTemplate::doFullPageNameX($parser, $frame, null);
				break;
			case MetaTemplate::VR_NAMESPACE0:
				$ret = MetaTemplate::doNamespaceX($parser, $frame, null);
				break;
			case MetaTemplate::VR_NESTLEVEL:
				$ret = MetaTemplate::doNestLevel($frame);
				break;
			case MetaTemplate::VR_PAGENAME0:
				$ret = MetaTemplate::doPageNameX($parser, $frame, null);
				break;
		}
	}

	public static function onParserTestTables(array &$tables)
	{
		$tables[] = MetaTemplateSql::SET_TABLE;
		$tables[] = MetaTemplateSql::DATA_TABLE;
	}

	/**
	 * onRegister
	 *
	 * @return void
	 */
	public static function onRegister()
	{
		// TODO: Investigate why preprocessor always running in <noinclude> mode on template save.

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

		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA) && MetaTemplateSql::getInstance()->tablesExist()) {
			// $parser->setFunctionHook(MetaTemplateData::PF_LISTSAVED, 'MetaTemplateData::doListsaved', SFH_OBJECT_ARGS);
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
		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
			ParserHelper::getInstance()->setHookSynonyms($parser, MetaTemplateData::NA_SAVEMARKUP, 'MetaTemplateData::doSaveMarkupTag');
		}

		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLECPT)) {
			// $parser->setHook(MetaTemplate::TG_CATPAGETEMPLATE, 'MetaTemplateInit::efMetaTemplateCatPageTemplate');
		}
	}
}
