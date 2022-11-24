<?php
// namespace MediaWiki\Extension\MetaTemplate;
// use MediaWiki\DatabaseUpdater;

/** @todo Add {{#define/local/preview:a=b|c=d}} */
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

	/**
	 * If in Category space, this creates a new CategoryPage derivative that will open one of:
	 *   - A MetaTemplateCategoryViewer if a <catpagetemplate> is present on the category page.
	 *   - A CategoryTreeCategoryViewer if the CategoryTree extension is detected.
	 *   - A regular CategoryViewer in all other cases.
	 *
	 * @param Title $title The category's title.
	 * @param Article|null $article The new article page.
	 * @param IContextSource $context The request context.
	 *
	 * @return void
	 *
	 */
	public static function onArticleFromTitle(Title &$title, ?Article &$article, IContextSource $context): void
	{
		if ($title->getNamespace() === NS_CATEGORY) {
			$article = new MetaTemplateCategoryPage($title);
		}
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
		/** @todo This function is a placeholder until UespCustomCode is rewritten, at which point this can be
		 *  transferred there.
		 */

		/* Going with hard-coded values, since these are unlikely to change, even if we transfer them to other
		 * languages. If we do want to translate them, it's changed easily enough at that time.
		 */
		$bypassVars[] = 'ns_base';
		$bypassVars[] = 'ns_id';
	}

	/**
	 * During a move, this function moves data from the original page to the new one, then forces re-evaluation of the
	 * new page to ensure all information is up to date.
	 *
	 * @param MediaWiki\Linker\LinkTarget $old The original LinkTarget for the page.
	 * @param MediaWiki\Linker\LinkTarget $new The new LinkTarget for the page.
	 * @param MediaWiki\User\UserIdentity $userIdentity The user performing the move.
	 * @param int $pageid The original page ID.
	 * @param int $redirid The new page ID.
	 * @param string $reason The reason for the move.
	 * @param MediaWiki\Revision\RevisionRecord $revision The RevisionRecord.
	 *
	 * @return void
	 *
	 */
	public static function onPageMoveComplete(
		$old,
		$new,
		$userIdentity,
		int $pageid,
		int $redirid,
		string $reason,
		$revision
	): void {
		// The function header here takes advantage of PHP's loose typing and the fact that both 1.35+ and 1.34- have
		// the same number and order of parameters, just with different object types.
		MetaTemplateSql::getInstance()->moveVariables($pageid, $redirid);
		$title = $new instanceof MediaWiki\Linker\LinkTarget
			? Title::newFromLinkTarget($new)
			: $new;
		MetaTemplateSql::getInstance()->recursiveInvalidateCache($title);
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
	public static function onParserFirstCallInit(Parser $parser): void
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
			$parser->setFunctionHook(MetaTemplateData::PF_PRELOAD, 'MetaTemplateData::doPreload', SFH_OBJECT_ARGS);
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
	private static function initTagFunctions(Parser $parser): void
	{
		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLECPT)) {
			ParserHelper::getInstance()->setHookSynonyms($parser, MetaTemplateCategoryPage::TG_CATPAGETEMPLATE, 'MetaTemplateCategoryViewer::doCatPageTemplate');
		}

		if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
			ParserHelper::getInstance()->setHookSynonyms($parser, MetaTemplateData::TG_SAVEMARKUP, 'MetaTemplateData::doSaveMarkupTag');
		}
	}
}
