<?php

/**
 * Data functions of MetaTemplate (#listsaved, #load, #save).
 */
class MetaTemplateData
{
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
	 * Key for the value holding the variables to save at the end of the page.
	 *
	 * @var string (?MetaTemplateSetCollection)
	 */
	public const KEY_SAVE = MetaTemplate::KEY_METATEMPLATE . '#save';

	#region Constants
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

	public const NA_NAMESPACE = 'metatemplate-namespace';
	public const NA_ORDER = 'metatemplate-order';
	public const NA_PAGELENGTH = 'metatemplate-pagelength';
	public const NA_PAGENAME = 'metatemplate-pagename';
	public const NA_SAVEMARKUP = 'metatemplate-savemarkupattr';
	public const NA_SET = 'metatemplate-set';

	public const PF_LISTSAVED = 'metatemplate-listsaved';
	public const PF_LOAD = 'metatemplate-load';
	public const PF_PRELOAD = 'metatemplate-preload';
	public const PF_SAVE = 'metatemplate-save';

	public const PRELOAD_SEP = '|';
	public const SAVE_SETNAME_WIDTH = 50;
	public const SAVE_VARNAME_WIDTH = 50;

	public const TG_SAVEMARKUP = 'metatemplate-savemarkuptag';

	/**
	 * Key for the value indicating whether we're currently processing a {{#save:...|savemarkup=1}}.
	 *
	 * @var string (?true)
	 */
	private const KEY_PARSEONLOAD = MetaTemplate::KEY_METATEMPLATE . '#parseOnLoad';

	/**
	 * Key for the value indicating if we're in save mode.
	 *
	 * @var string (?bool) True if in save mode.
	 */
	private const KEY_SAVE_MODE = MetaTemplate::KEY_METATEMPLATE . '#saving';

	/**
	 * Key for the value indicating that a #save operation was attempted during a #listsaved operation and ignored.
	 *
	 * @var string (?bool)
	 */
	private const KEY_SAVE_IGNORED = MetaTemplate::KEY_METATEMPLATE . '#saveIgnored';
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
			MetaTemplateData::NA_SET,
			ParserHelper::NA_DEBUG,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			ParserHelper::NA_SEPARATOR,
			self::NA_NAMESPACE,
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
		$namespace = isset($magicArgs[self::NA_NAMESPACE]) ? $frame->expand($magicArgs[self::NA_NAMESPACE]) : null;
		$setName = isset($magicArgs[MetaTemplateData::NA_SET]) ? $frame->expand($magicArgs[MetaTemplateData::NA_SET]) : null;
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
			if ($output->getExtensionData(self::KEY_SAVE_IGNORED) ?? false) {
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
		if (($preloadSet = $output->getExtensionData(self::KEY_VAR_CACHE_WANTED)[$setName] ?? null) &&
			($bulkPage = $output->getExtensionData(self::KEY_VAR_CACHE)[$pageId] ?? null) &&
			($bulkSet = $bulkPage->sets[$setName] ?? null)
		) {
			#RHecho('Preload \'', Title::newFromID($pageId)->getFullText(), '\' Set \'', $setName, "'\nWant set: ", $set, "\n\nGot set: ", $bulkSet);
			foreach ($preloadSet->variables as $varName => $value) {
				$varValue = $bulkSet->variables[$varName] ?? null;
				if (!is_null($varValue)) {
					MetaTemplate::setVar($frame, $varName, $varValue, $anyCase);
				}

				unset($set->variables[$varName]);
			}

			if (!count($set->variables)) {
				return;
			}
		}

		#RHshow('Trying to load vars from page [[', $loadTitle->getFullText(), ']]', $set);
		if (!self::loadFromOutput($output, $set)) {
			MetaTemplateSql::getInstance()->loadSetFromPage($pageId, $set);
		}

		foreach ($set->variables as $varName => $varValue) {
			if ($varValue !== false) {
				MetaTemplate::setVar($frame, $varName, $varValue, $anyCase);
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
		self::addToSet($parser->getTitle(), $output, $setName, [self::KEY_PRELOAD_DATA => $varList]);
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

		$output = $parser->getOutput();
		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		$saveMarkup = $magicArgs[self::NA_SAVEMARKUP] ?? false;
		/** @var MetaTemplateVariable[] $varsToSave */
		$varsToSave = [];
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		#RHshow('Translations', $translations);
		foreach ($translations as $srcName => $destName) {
			$varNodes = MetaTemplate::getVar($frame, $srcName, $anyCase);
			if ($varNodes) {
				$output->setExtensionData(self::KEY_SAVE_MODE, $saveMarkup);

				// For some reason, this seems to be necessary at all expansion levels during save, not just the top.
				MetaTemplate::unsetVar($frame, $srcName, $anyCase);
				$varValue = $frame->expand($varNodes, $saveMarkup ? MetaTemplate::getVarExpandFlags() : PPFrame::STRIP_COMMENTS);
				MetaTemplate::setVarDirect($frame, $srcName, $varNodes);

				$varValue = VersionHelper::getInstance()->getStripState($parser)->unstripBoth($varValue);
				$parseOnLoad =
					($saveMarkup && self::treeHasTemplate($varNodes)) ||
					$output->getExtensionData(self::KEY_PARSEONLOAD);
				$output->setExtensionData(self::KEY_PARSEONLOAD, null);
				$output->setExtensionData(self::KEY_SAVE_MODE, null);
				$varsToSave[$destName] = new MetaTemplateVariable($varValue, $parseOnLoad);
			}
		}

		#RHshow('Vars to Save', $varsToSave);

		// Normally, the "ignored" value will be null when #save is run. If it's false, then we know we got here via
		// #listsaved. If that's the case, then we've hit an abnormal condition that should probably never happen, so
		// we flip the flag value to true to let the caller know that that's what happened. This will only occur if all
		// checks were passed and this is unambiguously active code. The check for false instead of !is_null() makes
		// sure we only set it to true if we haven't already done so.
		if ($output->getExtensionData(self::KEY_SAVE_IGNORED) === false) {
			$output->setExtensionData(self::KEY_SAVE_IGNORED, true);
		}

		$setName = substr($magicArgs[self::NA_SET] ?? '', 0, self::SAVE_SETNAME_WIDTH);
		self::addToSet($title, $output, $setName, $varsToSave);

		if ($debug && count($varsToSave)) {
			$out = [];
			foreach ($varsToSave as $key => $value) {
				$text = $key;
				if ($value->parseOnLoad) {
					$text .= ' (parse on load)';
				}

				$text .= ' = ' . (string)$value->value;
				$out[] = $text;
			}

			$out = implode("\n", $out);
			#RHshow('Test', $out);
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
	 * @return string The value text with templates and tags left unparsed.
	 */
	public static function doSaveMarkupTag($content, array $attributes, Parser $parser, PPFrame_Hash $frame): array
	{
		$output = $parser->getOutput();
		$saveMode = $output->getExtensionData(self::KEY_SAVE_MODE);
		if (is_null($saveMode)) { // Not saving, just displaying <savemarkup> or {{#save:...|savemarkup=1}}
			$value = $parser->recursiveTagParse($content, $frame);
			#RHshow('Recursive Tag Parse', $value);
			return [$value, 'markerType' => 'general'];
		}

		$value = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
		if (self::treeHasTemplate($value)) {
			$output->setExtensionData(self::KEY_PARSEONLOAD, true);
		}

		if ($saveMode) { // Saving <savemarkup> inside {{#save:...|savemarkup=1}}
			$parent = $frame->parent ?? $frame;
			$value = $parent->expand($value, MetaTemplate::getVarExpandFlags());
			#RHshow('Double Parsed', $value);
		} else {
			// Saving with standard {{#save:...|savemarkup=1}}
			$value = $frame->expand($value, MetaTemplate::getVarExpandFlags());
			$value = VersionHelper::getInstance()->getStripState($parser)->unstripBoth($value);
			#RHshow('Value', $value);
		}

		return [$value, 'markerType' => 'nowiki'];
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
	private static function addToSet(Title $title, ParserOutput $output, string $setName, array $variables): void
	{
		#RHshow('addVars', $variables);
		if (!count($variables)) {
			return;
		}

		$page = WikiPage::factory($title);
		$revId = $page->getLatest();
		/** @var MetaTemplateSetCollection $pageVars */
		$pageVars = $output->getExtensionData(self::KEY_SAVE);
		if (!$pageVars) {
			$pageVars = new MetaTemplateSetCollection($title, $revId);
			$output->setExtensionData(self::KEY_SAVE, $pageVars);
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
	 * Sorts the results according to user-specified order (if any), then page name, and finally set.
	 *
	 * @param array $arr The array of page/set data to sort.
	 * @param string[] $sortOrder A list of field names to sort by. In the event of duplication, only the first instance
	 *                         counts.
	 *
	 * @note This function serves to not only add the data to the cache, but also to convert the data into pages.
	 *
	 * @return MetaTemplatePage[] The sorted array.
	 */
	private static function pagifyRows(array $rows): array
	{
		// Now that sorting is done, morph records into MetaTemplatePages.
		$retval = [];
		$pageId = 0;
		foreach ($rows as $row) {
			if ($row['pageid'] !== $pageId) {
				$pageId = $row['pageid'];
				$page = new MetaTemplatePage($row['namespace'], $row['pagename']);
				// $retval[$pageId] does not maintain order; array_merge does, with RHS overriding LHS in the event of
				// duplicates.
				$retval += [$pageId => $page];
			}

			// Unset the parent data/sortable fields, leaving only the set data.
			$setName = $row['set'];
			unset(
				$row['namespace'],
				$row['pageid'],
				$row['pagename'],
				$row['set'],
			);

			$page->sets += [$setName => $row];
		}

		return $retval;
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
			$pagename = strtr($mtPage->pagename, '_', ' ');
			foreach (array_keys($mtPage->sets) as $setname) {
				$retval .= "$separator$open$templateName|namespace={$mtPage->namespace}|pagename=$pagename|set=$setname$close";
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
	private static function loadFromOutput(ParserOutput $output, MetaTemplateSet &$set): bool
	{
		/** @var MetaTemplateSetCollection $pageVars */
		$pageVars = $output->getExtensionData(self::KEY_SAVE);
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
