<?php
// namespace MediaWiki\Extension\MetaTemplate;
use MediaWiki\MediaWikiServices;
// use MediaWiki\DatabaseUpdater;

// TODO: Add {{#define/local/preview:a=b|c=d}}
class MetaTemplateHooks
{
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
			if ($db->textFieldSize(MetaTemplateSql::SET_TABLE, 'mt_set_subset') < 50) {
				// MW 1.30-
				foreach (['id', 'page_id', 'rev_id', 'subset'] as $field) {
					$updater->modifyExtensionField(MetaTemplateSql::SET_TABLE, "mt_set_$field", "$dir/sql/patch-" . MetaTemplateSql::SET_TABLE . ".$field.sql");
				}

				foreach (['id', 'parsed', 'value'] as $field) {
					$updater->modifyExtensionField(MetaTemplateSql::DATA_TABLE, "mt_save_$field", "$dir/sql/patch-" . MetaTemplateSql::DATA_TABLE . ".$field.sql");
				}

				$updater->dropExtensionField(MetaTemplateSql::SET_TABLE, 'time', "$dir/sql/patch-" . MetaTemplateSql::SET_TABLE . ".time.sql");
				// MW 1.31+
				// $updater->modifyExtensionTable( $saveSet, "$dir/sql/patch-$saveSet.sql" );
				// $updater->modifyExtensionTable( $saveData, "$dir/sql/patch-$saveData.sql" );
			} else {
				// Always run both unconditionally in case only one or the other was created previously.
				// Updater will automatically skip each if the table exists.
				$updater->addExtensionTable(MetaTemplateSql::SET_TABLE, "$dir/sql/" . MetaTemplateSql::SET_TABLE . '.sql');
				$updater->addExtensionTable(MetaTemplateSql::DATA_TABLE, "$dir/sql/" . MetaTemplateSql::DATA_TABLE . '.sql');
			}
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

	public static function onParserAfterTidy(Parser $parser, &$text)
	{
		$output = $parser->getOutput();
		// getTimeSinceStart is a kludge to detect if this is the real page we're processing or some small part of it that we don't care about.
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
		}
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

		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
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
		ParserHelper::getInstance()->setHookSynonyms($parser, MetaTemplateData::NA_SAVEMARKUP, 'MetaTemplateData::doSaveMarkupTag');
		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLECPT)) {
			// $parser->setHook(MetaTemplate::TG_CATPAGETEMPLATE, 'MetaTemplateInit::efMetaTemplateCatPageTemplate');
		}
	}
}
