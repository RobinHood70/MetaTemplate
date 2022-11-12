<?php
// namespace MediaWiki\Extension\MetaTemplate;
// use MediaWiki\DatabaseUpdater;

// TODO: Add {{#define/local/preview:a=b|c=d}}
class MetaTemplateHooks
{
	const OLDSET_TABLE = 'mt_save_set';
	const OLDDATA_TABLE = 'mt_save_data';

	/**
	 * Migrates the MetaTemplate 1.0 data table to the current version.
	 *
	 * @param DatabaseUpdater $updater
	 * @param string $dir
	 *
	 * @return void
	 *
	 */
	public static function migrateDataTable(DatabaseUpdater $updater, string $dir): void
	{
		$db = $updater->getDB();
		if (!$db->tableExists(self::OLDDATA_TABLE)) {
			$updater->addExtensionTable(MetaTemplateSql::DATA_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
			$updater->addExtensionUpdate([__CLASS__, 'migrateSet']);
		}
	}

	/**
	 * Migrates the MetaTemplate 1.0 set table to the current version.
	 *
	 * @param DatabaseUpdater $updater
	 * @param string $dir
	 *
	 * @return void
	 *
	 */
	public static function migrateSetTable(DatabaseUpdater $updater, string $dir): void
	{
		$db = $updater->getDB();
		if (!$db->tableExists(self::OLDSET_TABLE)) {
			$updater->addExtensionTable(MetaTemplateSql::SET_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
			$updater->addExtensionUpdate([__CLASS__, 'migrateSet']);
		}
	}

	/**
	 * Deletes all set-related data when a page is deleted.
	 *
	 * @param WikiPage $article The article that was deleted.
	 * @param User $user The user that deleted the article
	 * @param mixed $reason The reason the article was deleted.
	 * @param mixed $id The ID of the article that was deleted.
	 * @param mixed $content The content of the deleted article, or null in case of an error.
	 * @param LogEntry $logEntry The log entry used to record the deletion.
	 * @param mixed $archivedRevisionCount The number of revisions archived during the page delete.
	 *
	 * @return void
	 *
	 */
	public static function onArticleDeleteComplete(WikiPage &$article, User &$user, $reason, $id, $content, LogEntry $logEntry, $archivedRevisionCount): void
	{
		MetaTemplateSql::getInstance()->deleteVariables($article->getTitle());
	}

	// Initial table setup/modifications from v1.
	/**
	 * Migrates the old MetaTemplate tables to new ones. The basic functionality is the same, but names and indeces
	 * have been altered and the datestamp removed.
	 *
	 * @param DatabaseUpdater $updater
	 *
	 * @return void
	 *
	 */
	public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater): void
	{
		/** @var string $dir  */
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

	/**
	 * Enables MetaTemplate's variables.
	 *
	 * @param array $aCustomVariableIds The list of custom variables to add to.
	 *
	 * @return void
	 *
	 */
	public static function onMagicWordwgVariableIDs(array &$aCustomVariableIds): void
	{
		$aCustomVariableIds[] = MetaTemplate::VR_FULLPAGENAME0;
		$aCustomVariableIds[] = MetaTemplate::VR_NAMESPACE0;
		$aCustomVariableIds[] = MetaTemplate::VR_NESTLEVEL;
		$aCustomVariableIds[] = MetaTemplate::VR_PAGENAME0;
	}

	/**
	 * Adds ns_base and ns_id to the list of parameters that bypass the normal limitations on parameter evaluation when
	 * viewing a template on its native page.
	 *
	 * @param array $bypassVars The active list of variable names to bypass.
	 *
	 * @return void
	 *
	 */
	public static function onMetaTemplateSetBypassVars(array &$bypassVars): void
	{
		// TODO: This function is a placeholder until UespCustomCode is rewritten, at which point this can be
		// transferred there.
		// Going with hard-coded values, since these are unlikely to change, even if we transfer them to other
		// languages. If we do want to translate them, it's changed easily enough at that time.
		$bypassVars[] = 'ns_base';
		$bypassVars[] = 'ns_id';
	}

	public static function onPageMoveComplete(
		MediaWiki\Linker\LinkTarget $old,
		MediaWiki\Linker\LinkTarget $new,
		MediaWiki\User\UserIdentity $userIdentity,
		int $pageid,
		int $redirid,
		string $reason,
		MediaWiki\Revision\RevisionRecord $revision
	) {
		// <  MW 1.31: The RevisionRecord and UserIdentity classes do not exist and will show up as errors.
		// >= MW 1.35: This version will automatically become active. Note that $redirid is the old page ID regardless of
		//             whether it's a redirect or not.
		MetaTemplateSql::getInstance()->moveVariables($pageid, $redirid);
	}

	/**
	 * Writes all #saved data to the database.
	 *
	 * @param Parser $parser The parser in use.
	 * @param mixed $text The text of the article.
	 *
	 * @return void
	 *
	 */
	public static function onParserAfterTidy(Parser $parser, &$text): void
	{
		// RHwriteFile('onParserAfterTidy => ', $parser->getTitle()->getFullText(), ' / ', $parser->getRevisionId(), ' ', is_null($parser->getRevisionId() ? ' is null!' : ''));
		// RHwriteFile(substr($text, 0, 30) . "\n");
		MetaTemplateSql::getInstance()->saveVariables($parser);
	}

	/**
	 * Initialize parser and tag functions followed by MetaTemplate general initialization.
	 *
	 * @param Parser $parser The parser in use.
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
	 * Gets the value of the specified variable.
	 *
	 * @param Parser $parser The parser in use.
	 * @param array $variableCache The variable cache. Can be used to store values for faster evaluation in subsequent calls.
	 * @param mixed $magicWordId The magic word ID to evaluate.
	 * @param mixed $ret The return value.
	 * @param PPFrame $frame The frame in use.
	 *
	 * @return bool Always true
	 *
	 */
	public static function onParserGetVariableValueSwitch(Parser $parser, array &$variableCache, $magicWordId, &$ret, PPFrame $frame): bool
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

		return true;
	}

	/**
	 * Register's the pre-processor. Note: this will fail in MediaWiki 1.35+.
	 *
	 * @return void
	 *
	 */
	public static function onRegister(): void
	{
		// TODO: Investigate why preprocessor always running in <noinclude> mode on template save.

		// This should work until at least 1.33 and I'm pretty sure 1.34. As of 1.35, it will fail, and the path
		// forward is unclear. We might be able to override the Parser class itself with a custom one, or we may have
		// to modify the source files to insert our own parser and/or preprocessor.
		global $wgParserConf;
		$wgParserConf['preprocessorClass'] = "MetaTemplatePreprocessor";
	}

	public static function onTitleMoveComplete(Title &$title, Title &$newTitle, User $user, $oldid, $newid, $reason, Revision $revision)
	{
		// This function is deprecated as of 1.35. The corresponding onPageMoveComplete handles everything from that
		// point, so this function can be removed, along with the corresponding adjustment to extension.json.
		MetaTemplateSql::getInstance()->moveVariables($oldid, $newid);
	}

	/**
	 * Initialize parser functions.
	 *
	 * @param Parser $parser The parser in use.
	 *
	 * @return void
	 */
	private static function initParserFunctions(Parser $parser): void
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
			$parser->setFunctionHook(MetaTemplateData::PF_LISTSAVED, 'MetaTemplateData::doListsaved', SFH_OBJECT_ARGS);
			$parser->setFunctionHook(MetaTemplateData::PF_LOAD, 'MetaTemplateData::doLoad', SFH_OBJECT_ARGS);
			$parser->setFunctionHook(MetaTemplateData::PF_LOADLIST, 'MetaTemplateData::doLoadlist', SFH_OBJECT_ARGS);
			$parser->setFunctionHook(MetaTemplateData::PF_SAVE, 'MetaTemplateData::doSave', SFH_OBJECT_ARGS);
		}
	}

	/**
	 * Initialize tag functions.
	 *
	 * @param Parser $parser The parser in use.
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
