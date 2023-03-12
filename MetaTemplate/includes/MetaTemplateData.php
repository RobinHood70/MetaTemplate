<?php

/**
 * Data functions of MetaTemplate (#listsaved, #load, #save).
 */
class MetaTemplateData
{
	#region Public Constants
	/**
	 * Key for the value indicating whether catpagetemplate is in initial startup mode and should ignore the |set=
	 * parameter for anything that calls {{#preload}}.
	 *
	 * @var string (?true)
	 */
	public const KEY_IGNORE_SET = MetaTemplate::KEY_METATEMPLATE . '#ignoreSet';

	/**
	 * Key to use when saving the {{#preload}} information to the template page.
	 *
	 * @var string (string[])
	 */
	public const KEY_PRELOAD_DATA = MetaTemplate::KEY_METATEMPLATE . '#preload';

	/**
	 * Key for the value housing the {{#preload}} cache.
	 *
	 * @var string (?MetaTemplatePage[])
	 */
	public const KEY_VAR_CACHE = MetaTemplate::KEY_METATEMPLATE . '#cache';

	/**
	 * Key for the value holding the preload variables that should be loaded.
	 *
	 * @var string (?MetaTemplateSet[])
	 */
	public const KEY_VAR_CACHE_WANTED = MetaTemplate::KEY_METATEMPLATE . '#cacheWanted';

	public const NA_ORDER = 'metatemplate-order';
	public const NA_SAVEMARKUP = 'metatemplate-savemarkupattr';
	public const NA_SET = 'metatemplate-set';

	public const PF_LISTSAVED = 'listsaved';
	public const PF_LOAD = 'load';
	public const PF_PRELOAD = 'preload';
	public const PF_SAVE = 'save';

	public const PRELOAD_SEP = '|';
	public const SAVE_SETNAME_WIDTH = 50;
	public const SAVE_VARNAME_WIDTH = 50;

	public const TG_SAVEMARKUP = 'metatemplate-savemarkuptag';

	/** @var ?MetaTemplateSetCollection $saveData */
	private static $saveData;
	#endregion

	#region Private Constants
	/**
	 * Key for the value indicating if we're in save mode.
	 *
	 * @var string (?bool) True if in save mode.
	 */
	private const KEY_SAVE_MODE = MetaTemplate::KEY_METATEMPLATE . '#saving';
	private static $isSaving = false;

	/**
	 * Key for the value indicating that a #save operation was attempted during a #listsaved operation and ignored.
	 *
	 * @var string (?bool)
	 */
	private const KEY_SAVE_IGNORED = MetaTemplate::KEY_METATEMPLATE . '#saveIgnored';
	#endregion

	#region Public Static Properties
	/** @var ?string */
	public static $mwSet = null;
	#endregion

	#region Public Static Functions
	/**
	 * Queries the database based on the conditions provided and creates a list of templates, one for each row in the
	 * results.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *     debug: Set to PHP true to show the cleaned code on-screen during Show Preview. Set to 'always' to show
	 *            even when saved.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *     order: A comma-separated list of fields to sort by.
	 *         *: All other values are considered to be preload variables. Note: this is strictly for backwards
	 *            compatibility. These should be converted to #preload values in the template itself. As with #preload,
	 *            translations are not allowed and variable names should be as stored in the database.
	 *
	 * @return array The text of the templates to be called to make the list as well as the appropriate noparse value
	 *               depending whether it was an error message or a successful call.
	 */
	public static function doListSaved(Parser $parser, PPFrame $frame, array $args): array
	{
		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			MetaTemplate::NA_CASE,
			self::NA_SET,
			ParserHelper::NA_DEBUG,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			ParserHelper::NA_SEPARATOR,
			MetaTemplate::NA_NAMESPACE,
			self::NA_ORDER
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		if (!ParserHelper::checkIfs($frame, $magicArgs)) {
			return '';
		}

		$template = trim($frame->expand($values[0] ?? ''));
		if (!strlen($template)) {

			return [ParserHelper::error('metatemplate-listsaved-template-empty')];
		}

		unset($values[0]);

		$templateTitle = Title::newFromText($template, NS_TEMPLATE);
		if (!$templateTitle) {
			return [ParserHelper::error('metatemplate-listsaved-template-missing', $template)];
		}

		$page = WikiPage::factory($templateTitle);
		if (!$page) {
			return [ParserHelper::error('metatemplate-listsaved-template-missing', $templateTitle->getFullText())];
		}

		$output = $parser->getOutput();
		// Track the page here rather than earlier or later since the template should be tracked even if it doesn't
		// exist, but not if it's invalid. We use $page for values instead of $templateTitle
		$output->addTemplate($templateTitle, $page->getId(), $page->getLatest());
		if (!$page->exists()) {
			return [ParserHelper::error('metatemplate-listsaved-template-missing', $template)];
		}

		$maxLen = MetaTemplate::getConfig()->get('ListsavedMaxTemplateSize');
		$size = $templateTitle->getLength();
		if ($maxLen > 0 && $size > $maxLen) {
			return [ParserHelper::error('metatemplate-listsaved-template-toolong', $template, $maxLen)];
		}

		/**
		 * @var array $conditions
		 * @var array $extrasnamed
		 */
		[$conditions, $extras] = ParserHelper::splitNamedArgs($frame, $values);
		if (!count($conditions)) {
			// Is this actually an error? Could there be a condition of wanting all rows (perhaps in namespace)?
			return [ParserHelper::error('metatemplate-listsaved-conditions-missing')];
		}

		foreach ($conditions as &$value) {
			$value = $frame->expand($value);
		}

		#RHshow('Conditions', $conditions);

		if (!empty($extras)) {
			// Extra parameters are now irrelevant, so we track and report any calls that still use the old format.
			$parser->addTrackingCategory('metatemplate-tracking-listsaved-extraparams');
			$output->addWarning(wfMessage('metatemplate-listsaved-warn-extraparams')->plain());
		}

		$preloadVars = MetaTemplateSql::getInstance()->loadSetsFromPage($templateTitle->getArticleID(), [self::KEY_PRELOAD_DATA]);
		$varSets = [];
		foreach ($preloadVars as $varSet) {
			$varNames = explode(self::PRELOAD_SEP, $varSet->variables[self::KEY_PRELOAD_DATA]);
			$vars = [];
			foreach ($varNames as $varName) {
				$vars[$varName] = false;
			}

			$varSets[$varSet->name] = new MetaTemplateSet($varSet->name, $vars);
		}

		// Set up database queries to include all condition and preload data.
		$namespace = isset($magicArgs[MetaTemplate::NA_NAMESPACE]) ? $frame->expand($magicArgs[MetaTemplate::NA_NAMESPACE]) : null;
		$setName = isset($magicArgs[self::NA_SET]) ? $frame->expand($magicArgs[self::NA_SET]) : null;
		$sortOrder = isset($magicArgs[self::NA_ORDER]) ? $frame->expand($magicArgs[self::NA_ORDER]) : null;
		$rows = MetaTemplateSql::getInstance()->loadListSavedData($namespace, $setName, $sortOrder, $conditions, $varSets, $frame);
		$pages = self::pagifyRows($rows);
		self::cachePages($output, $pages, empty($varSets));
		#RHshow('Pages', $pages);

		$templateName = $templateTitle->getNamespace() === NS_TEMPLATE ? $templateTitle->getText() : $templateTitle->getFullText();
		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
		$retval = self::createTemplates($templateName, $pages, ParserHelper::getSeparator($magicArgs));
		if (!$debug) {
			$output->setExtensionData(self::KEY_SAVE_IGNORED, false);
			$dom = $parser->preprocessToDom($retval);
			$retval = $frame->expand($dom);
			if ($output->getExtensionData(self::KEY_SAVE_IGNORED)) {
				$retval = ParserHelper::error('metatemplate-listsaved-template-saveignored', $templateTitle->getFullText()) . $retval;
			}

			$output->setExtensionData(self::KEY_SAVE_IGNORED, null);
		}

		return ParserHelper::formatPFForDebug($retval, $debug);
	}

	/**
	 * Loads variable values from another page.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *         1: The page name to load from.
	 *        2+: The variable names to load.
	 *       set: The data set to load from.
	 *      case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *            'any', along with any translations or synonyms of it.
	 *        if: A condition that must be true in order for this function to run.
	 *     ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doLoad(Parser $parser, PPFrame $frame, array $args): void
	{
		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			MetaTemplate::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		if (!ParserHelper::checkIfs($frame, $magicArgs) || count($values) < 2) {
			return;
		}

		$loadTitle = $frame->expand($values[0]);
		$loadTitle = Title::newFromText($loadTitle);
		if (
			!$loadTitle ||
			!$loadTitle->canExist()
		) {
			return;
		}

		unset($values[0]);
		$pageId = $loadTitle->getArticleID();
		if ($pageId <= 0) {
			return;
		}

		$output = $parser->getOutput();
		#RHecho($loadTitle->getFullText(), ' ', $page->getId(), ' ', $page->getLatest());
		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists.
		$output->addTemplate($loadTitle, $pageId, $loadTitle->getLatestRevID());
		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		$setName = isset($magicArgs[self::NA_SET])
			? substr($magicArgs[self::NA_SET], 0, self::SAVE_SETNAME_WIDTH)
			: null;
		$set = new MetaTemplateSet($setName, []);
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		foreach ($translations as $key => $value) {
			if (!MetaTemplate::getVar($frame, $value, $anyCase)) {
				$set->variables[$key] = false;
			}
		}

		#RHshow('Set', $set);

		if (!count($set->variables)) {
			return;
		}

		// Next, check preloaded variables. Note that the if conditions are also assignments, for brevity. Inner
		// condition only occurs if all are non-null.
		/** @var MetaTemplateSet $preloadSet */
		/** @var MetaTemplatePage $bulkPage */
		$preloadSet = $output->getExtensionData(self::KEY_VAR_CACHE_WANTED)[$setName] ?? null;
		$bulkPage = $preloadSet ? $output->getExtensionData(self::KEY_VAR_CACHE)[$pageId] ?? null : null;
		$bulkSet = $bulkPage ? $bulkPage->sets[$setName] ?? null : null;
		if ($bulkSet) {
			#RHecho('Preload \'', Title::newFromID($pageId)->getFullText(), '\' Set \'', $setName, "'\nWant set: ", $set, "\n\nGot set: ", $bulkSet);
			foreach ($preloadSet->variables as $varName => $ignored) {
				$varValue = $bulkSet->variables[$varName] ?? null;
				if (!is_null($varValue)) {
					$set->variables[$varName] = $varValue;
				}
			}
		}

		#RHshow('Trying to load vars from page [[', $loadTitle->getFullText(), ']]', $set);
		if (!self::loadFromSaveData($set)) {
			MetaTemplateSql::getInstance()->loadSetFromPage($pageId, $set);
		}

		foreach ($set->variables as $varName => $varValue) {
			if ($varValue !== false) {
				MetaTemplate::unsetVar($frame, $varName, $anyCase);
				$dom = $parser->preprocessToDom($varValue);
				$varValue = $frame->expand($dom, PPFrame::NO_ARGS | PPFrame::NO_IGNORE | PPFrame::NO_TAGS);
				MetaTemplate::setVarDirect($frame, $varName, $dom, $varValue);
			}
		}
	}

	/**
	 * Saves the specified variable names as metadata to be used by #listsaved.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments: The data to preload. Names must be as they're stored in the database.
	 *
	 * @return void
	 */
	public static function doPreload(Parser $parser, PPFrame $frame, array $args): void
	{
		if ($frame->depth > 0 || $parser->getOptions()->getIsPreview()) {
			return;
		}

		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([self::NA_SET]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		$output = $parser->getOutput();
		$setName = $output->getExtensionData(self::KEY_IGNORE_SET)
			? ''
			: $magicArgs[self::NA_SET] ?? '';

		/** @var MetaTemplateSet[] $sets */
		$sets = $output->getExtensionData(self::KEY_VAR_CACHE_WANTED) ?? [];
		if (isset($sets[$setName])) {
			$set = $sets[$setName];
		} else {
			$set = new MetaTemplateSet($setName);
			$sets[$setName] = $set;
		}

		foreach ($values as $value) {
			$varName = $frame->expand(ParserHelper::getKeyValue($frame, $value)[1]);
			$set->variables[$varName] = false;
		}

		$varList = implode(self::PRELOAD_SEP, array_keys($set->variables));
		self::addToSet($parser->getTitle(), $setName, [self::KEY_PRELOAD_DATA => $varList]);
		$output->setExtensionData(self::KEY_VAR_CACHE_WANTED, $sets);
	}

	/**
	 * Saves the specified values to the database.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPTemplateFrame_Hash $frame The frame in use.
	 * @param array $args Function arguments:
	 *         1+: The variable names to save.
	 *        set: The data set to save to.
	 * savemarkup: Whether markup should be saved as is or fully expanded to text before saving. This applies to all
	 *             variables specified in the #save command; use a separate command if you need some variables saved
	 *             with markup and others not.
	 *       case: Whether the name matching should be case-sensitive or not. Currently, the only allowable value is
	 *             'any', along with any translations or synonyms of it.
	 *         if: A condition that must be true in order for this function to run.
	 *      ifnot: A condition that must be false in order for this function to run.
	 *
	 * @return void
	 */
	public static function doSave(Parser $parser, PPFrame $frame, array $args): array
	{
		$title = $parser->getTitle();
		if (!$title->canExist()) {
			return [''];
		}

		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			MetaTemplate::NA_CASE,
			ParserHelper::NA_DEBUG,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET,
			self::NA_SAVEMARKUP
		]);

		/** @var array $magicArgs */
		/** @var array $values */
		[$magicArgs, $values] = ParserHelper::getMagicArgs($frame, $args, $magicWords);
		if (!count($values) || !ParserHelper::checkIfs($frame, $magicArgs)) {
			return [''];
		}

		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
		if (!$debug && ($title->getNamespace() === NS_TEMPLATE || $parser->getOptions()->getIsPreview())) {
			return [''];
		}

		/** @var MetaTemplateVariable[] $varsToSave */
		$varsToSave = [];
		self::$isSaving = true;
		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		$saveMarkup = (bool)($magicArgs[self::NA_SAVEMARKUP] ?? false);
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		foreach ($translations as $srcName => $destName) {
			$dom = MetaTemplate::getVar($frame, $srcName, $anyCase);
			if ($dom) {
				// Reparses the value as if included, so includeonly works as expected. Also surrounds any remaining
				// {{{vars}}} with <nowiki> tags.
				MetaTemplate::unsetVar($frame, $srcName, $anyCase);
				$flags = $saveMarkup ? PPFrame::NO_TEMPLATES | PPFrame::NO_TAGS : PPFrame::NO_TAGS;
				$varValue = $frame->expand($dom, $flags);
				$dom = $parser->preprocessToDom($varValue, Parser::PTD_FOR_INCLUSION);
				MetaTemplate::setVarDirect($frame, $srcName, $dom, $varValue);
				$varsToSave[$destName] = $varValue;
			}
		}

		self::$isSaving = false;
		//$output->setExtensionData(self::KEY_SAVE_MODE, null);
		#RHshow('Vars to Save', $varsToSave);

		// Normally, the "ignored" value will be null when #save is run. If it's false, then we know we got here via
		// #listsaved. If that's the case, then we've hit an abnormal condition that should probably never happen, so
		// we flip the flag value to true to let the caller know that that's what happened. This will only occur if all
		// checks were passed and this is unambiguously active code. The check for false instead of !is_null() makes
		// sure we only set it to true if we haven't already done so.
		$output = $parser->getOutput();
		if ($output->getExtensionData(self::KEY_SAVE_IGNORED) === false) {
			$output->setExtensionData(self::KEY_SAVE_IGNORED, true);
		}

		$setName = substr($magicArgs[self::NA_SET] ?? '', 0, self::SAVE_SETNAME_WIDTH);
		self::addToSet($title, $setName, $varsToSave);
		if ($debug && count($varsToSave)) {
			$out = [];
			foreach ($varsToSave as $key => $value) {
				$out[] = "$key = $value";
			}

			$out = implode("\n", $out);

			return ParserHelper::formatPFForDebug($out, true);
		}

		return [''];
	}

	/**
	 * Handles the <savemarkup> tag.
	 *
	 * @param mixed $value The value inside the tags (the markup text).
	 * @param array $attributes Ignored - there are no attributes for this tag.
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The template frame in use.
	 *
	 * @return array The half-parsed text and marker type.
	 */
	public static function doSaveMarkupTag($content, array $attributes, Parser $parser, PPFrame_Hash $frame): array
	{
		if (!self::$isSaving) {
			return [$parser->recursiveTagParse($content, $frame), 'markerType' => 'general'];
		}

		$value = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
		$parent = $frame->parent ?? $frame;
		$value = $parent->expand($value, PPFrame::NO_TEMPLATES);
		return [$value, 'markerType' => 'none'];
	}

	public static function init()
	{
		if (MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			self::$mwSet = self::$mwSet ?? MagicWord::get(MetaTemplateData::NA_SET)->getSynonym(0);
		}
	}

	/**
	 * Saves all pending data.
	 *
	 * @param Title $title The title of the data to be saved.
	 *
	 * @return bool True if data was updated; otherwise, false.
	 *
	 */
	public static function save(Title $title): bool
	{
		// This algorithm is based on the assumption that data is rarely changed, therefore:
		// * It's best to read the existing DB data before making any DB updates/inserts.
		// * Chances are that we're going to need to read all the data for this save set, so best to read it all at
		//   once instead of individually or by set.
		// * It's best to use the read-only DB until we know we need to write.
		//
		// The wikiPage::onArticleEdit() calls ensure that data gets refreshed recursively, even on indirectly affected
		// pages such as where there's a #load of #save'd data. Those types of pages don't seem to have their caches
		// invalidated otherwise.

		/** @var MetaTemplateSetCollection $vars */
		$vars = self::$saveData;
		$sql = MetaTemplateSql::getInstance();

		$retval = false;
		if ($vars && !empty($vars->sets)) {
			// The above check below will only be satisfied on Template-space pages that use #save.
			if ($vars->revId !== -1 && $sql->saveVars($vars)) {
				$retval =  true;
				WikiPage::onArticleEdit($title);
			}
		} elseif ($sql->hasPageVariables($title) && $sql->deleteVariables($title)) {
			// Check whether the page used to have variables; if not, delete will cause cascading refreshes.
			$retval = true;
			WikiPage::onArticleEdit($title);
		}

		self::$saveData = null;
		return $retval;
	}
	#endregion

	#region Private Static Functions
	/**
	 * Adds the provided list of variables to the set provided and to the parser output.
	 *
	 * @param WikiPage $page The page the variables should be added to.
	 * @param ParserOutput $output The current parser's output.
	 * @param array $variables The variables to add.
	 * @param string $set The set to be added to.
	 *
	 * @return void
	 */
	private static function addToSet(Title $title, string $setName, array $variables): void
	{
		#RHshow('addVars', $variables);
		if (!count($variables)) {
			return;
		}

		/** @var MetaTemplateSetCollection $pageVars */
		$pageVars = self::$saveData;
		if (!$pageVars) {
			$pageVars = new MetaTemplateSetCollection($title, $title->getLatestRevID());
			self::$saveData = $pageVars;
		}

		$pageVars->addToSet(0, $setName, $variables);
	}

	/**
	 * Sets or updates pages in the cache from the #listsaved data as needed.
	 *
	 * @param ParserOutput $output The current ParserOutput.
	 * @param MetaTemplatePage[] $pages The pages to store in the cache.
	 * @param bool $fullyLoaded Whether or not all data was loaded from the page or only a subset.
	 *
	 * @return void
	 *
	 */
	private static function cachePages(ParserOutput $output, array $pages, bool $fullyLoaded): void
	{
		/** @var MetaTemplatePage[] $cache */
		$cache = $output->getExtensionData(self::KEY_VAR_CACHE);
		foreach ($pages as $pageId => $page) {
			$cachePage = &$cache[$pageId];
			if ($fullyLoaded || !isset($cachePage)) {
				$cachePage = $page;
			} else {
				foreach ($page->sets as $set) {
					$cachePage->addToSet($set->name, $set->variables);
				}
			}
		}

		$output->setExtensionData(self::KEY_VAR_CACHE, $cache);
	}

	/**
	 * Converts the results of loadListSavedData() to the text of the templates to execute.
	 *
	 * @param Language $language The language to use for namespace text.
	 * @param string $templateName The template's name. Like with wikitext template calls, this is assumed to be in
	 *                             Template space unless otherwise specified.
	 * @param MetaTemplatePage[] $pages The parameters to pass to each of the template calls.
	 * @param string $separator The separator to use between each template.
	 *
	 * @return string The text of the template calls.
	 */
	private static function createTemplates(string $templateName, array $pages, string $separator): string
	{
		$retval = '';
		$open = '{{';
		$close = '}}';
		foreach ($pages as $mtPage) {
			$title = Title::newFromText($mtPage->namespace . ':' . strtr($mtPage->pagename, '_', ' '));
			foreach (array_keys($mtPage->sets) as $setname) {
				$retval .= "$separator$open$templateName";
				$retval .= '|' . MetaTemplate::$mwFullPageName . '=' . $title->getPrefixedText();
				$retval .= '|' . MetaTemplate::$mwNamespace . '=' . $title->getNsText();
				$retval .= '|' . MetaTemplate::$mwPageName . '=' . $title->getText();
				$retval .= '|' . self::$mwSet . '=' . $setname;
				$retval .= $close;
			}
		}

		return strlen($retval)
			? substr($retval, strlen($separator))
			: $retval;
	}

	/**
	 * Retrieves the requested set of variables already on the page as though they had been loaded from the database.
	 *
	 * @param ParserOutput $output The current ParserOutput object.
	 * @param MetaTemplateSet $set The set to load.
	 *
	 * @return bool True if all variables were loaded.
	 */
	private static function loadFromSaveData(MetaTemplateSet &$set): bool
	{
		$pageVars = self::$saveData;
		if (!$pageVars) {
			return false; // $pageVars = new MetaTemplateSetCollection($pageId, 0);
		}

		#RHshow('Page Variables', $pageVars);
		$pageSet = $pageVars->sets[$set->name];
		if (!$pageSet) {
			return false;
		}

		$retval = true;
		#RHshow('Page Set', $pageSet);
		if ($set->variables) {
			foreach ($set->variables as $varName => &$var) {
				if ($var === false && isset($pageSet->variables[$varName])) {
					$var = $pageSet->variables[$varName];
				} else {
					$retval = false;
				}
			}

			unset($var);
		}

		return $retval;
	}

	/**
	 * Converts existing #listsaved row data into MetaTemplatePages.
	 *
	 * @param array $arr The array of rows to work on.
	 *
	 * @return MetaTemplatePage[] The sorted array.
	 */
	private static function pagifyRows(array $rows): array
	{
		// Now that sorting is done, morph records into MetaTemplatePages.
		$retval = [];
		$pageId = 0;
		foreach ($rows as $row) {
			if ($row[MetaTemplate::$mwPageId] !== $pageId) {
				$pageId = $row[MetaTemplate::$mwPageId];
				$page = new MetaTemplatePage($row[MetaTemplate::$mwNamespace], $row[MetaTemplate::$mwPageName]);
				// $retval[$pageId] does not maintain order; array_merge does, with RHS overriding LHS in the event of
				// duplicates.
				$retval += [$pageId => $page];
			}

			// Unset the parent data/sortable fields, leaving only the set data.
			$setName = $row[self::$mwSet];
			unset(
				$row[MetaTemplate::$mwFullPageName],
				$row[MetaTemplate::$mwNamespace],
				$row[MetaTemplate::$mwPageId],
				$row[MetaTemplate::$mwPageName],
				$row[self::$mwSet]
			);

			$page->sets += [$setName => $row];
		}

		return $retval;
	}

	/**
	 * Recursively searches a tree node to determine if it has a template.
	 *
	 * @param PPNode_Hash_Tree $node
	 *
	 * @return bool
	 */
	private static function treeHasTemplate($nodes): bool
	{
		if ($nodes instanceof PPNode_Hash_Tree) {
			if ($nodes->name === 'template') {
				return true;
			}

			for ($node = $nodes->getFirstChild(); $node; $node = $node->getNextSibling()) {
				if ($node instanceof PPNode_Hash_Tree || is_iterable($nodes)) {
					$result = self::treeHasTemplate($node);
					if ($result) {
						return $result;
					}
				}
			}
		} elseif (is_iterable($nodes)) {
			foreach ($nodes as $node) {
				if ($node instanceof PPNode_Hash_Tree || is_iterable($nodes)) {
					$result = self::treeHasTemplate($node);
					if ($result) {
						return $result;
					}
				}
			}
		}

		return false;
	}
	#endregion
}
