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

	public const NA_ORDER = 'metatemplate-order';
	public const NA_SAVEMARKUP = 'metatemplate-savemarkupattr';
	public const NA_SET = 'metatemplate-set';

	public const PF_LISTSAVED = 'listsaved';
	public const PF_LOAD = 'load';
	public const PF_PRELOAD = 'preload';
	public const PF_SAVE = 'save';

	public const TG_SAVEMARKUP = 'metatemplate-savemarkuptag';
	#endregion

	#region Private Constants
	/**
	 * Key to use when saving the {{#preload}} information to the template page.
	 *
	 * @var string (string[])
	 */
	private const KEY_PRELOAD_DATA = MetaTemplate::KEY_METATEMPLATE . '#preload';

	/**
	 * Key for the data from all #save operations on a page.
	 *
	 * @var string (?bool)
	 */
	private const KEY_SAVE_DATA = MetaTemplate::KEY_METATEMPLATE . '#saveData';

	/**
	 * Key for the value indicating that a #save operation was attempted during a #listsaved operation and ignored.
	 *
	 * @var string (?bool)
	 */
	private const KEY_SAVE_IGNORED = MetaTemplate::KEY_METATEMPLATE . '#saveIgnored';

	private const PRELOAD_SEP = '|';
	private const SAVE_SETNAME_WIDTH = 50;
	private const SAVE_VARNAME_WIDTH = 50;
	private const STRIP_MARKERS = '/(<!--(IW)?LINK (.*?)-->|' . Parser::MARKER_PREFIX . '-.*?-[0-9A-Fa-f]+' . Parser::MARKER_SUFFIX . ')/';
	#endregion

	#region Public Static Properties

	/**
	 * Keyword to use for 'set' value in #listsaved and <catpagetemplate>.
	 *
	 * @var ?string $mwSet
	 */
	public static $mwSet = null;

	/**
	 * Copy of #preload list for cases like catpagetemplate that want an immediate return value.
	 *
	 * @var MetaTemplatePage[] $preloadCache
	 */
	public static $preloadCache = [];

	/**
	 * Copy of #preload list for cases like catpagetemplate that want an immediate return value.
	 *
	 * @var MetaTemplateSet[] $preloadVarSets
	 */
	public static $preloadVarSets = [];

	/**
	 * Save mode in use.
	 *     0 = not saving
	 *     1 = saving normally
	 *     2 = saving markup via parameter
	 *     3 = #local assigning value
	 *
	 * @var int $saveMode
	 */
	public static $saveMode = 0;
	#endregion

	#region Private Static Properties
	/**
	 * The title from the previous save() call. Due to how this handles parsing #load-ed variables, there are often two
	 * attempts in a row to parse the same title. This prevents doing so if the same title was just parsed.
	 *
	 * @var ?Title
	 */
	private static $prevId;
	#endregion

	#region Public Static Functions
	/**
	 * Queries the database based on the conditions provided and creates a list of templates, one for each row in the
	 * results.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
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
		$parser->addTrackingCategory('metatemplate-tracking-listsaved');

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
			return [''];
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

		// Pre-expand everything so we correctly parse wikitext parameters.
		foreach ($values as $key => &$value) {
			$value = trim($frame->expand($value));
			if (strlen($value) === 0) {
				unset($values[$key]);
			}
		}

		unset($value);

		/**
		 * @var array $conditions
		 * @var array $extras
		 */
		[$conditions, $extras] = ParserHelper::splitNamedArgs($frame, $values);
		foreach ($conditions as $key => $value) {
			if (strlen(trim($value)) === 0) {
				unset($conditions[$key]);
			}
		}

		if (!count($conditions)) {
			// Is this actually an error? Could there be a condition of wanting all rows (perhaps in namespace)?
			return [ParserHelper::error('metatemplate-listsaved-conditions-missing')];
		}

		if (!empty($extras)) {
			// Extra parameters are now irrelevant, so we track and report any calls that still use the old format.
			$parser->addTrackingCategory('metatemplate-tracking-listsaved-extraparams');
			$output->addWarning(wfMessage('metatemplate-listsaved-warn-extraparams')->plain());
		}

		// Set up database queries to include all condition and preload data.
		if (isset($magicArgs[MetaTemplate::NA_NAMESPACE])) {
			$namespace = trim($frame->expand($magicArgs[MetaTemplate::NA_NAMESPACE]));
			$contLang = VersionHelper::getInstance()->getContentLanguage();
			$namespace = $contLang->getNsIndex($namespace);
		} else {
			$namespace = null;
		}

		#RHshow('namespaceId', $namespace ?? '<null>');
		$setName = isset($magicArgs[self::NA_SET]) ? trim($frame->expand($magicArgs[self::NA_SET])) : null;
		self::$preloadVarSets = [];
		$preloads = MetaTemplateSql::getInstance()->loadSetsFromPage($templateTitle->getArticleID(), [self::KEY_PRELOAD_DATA]);
		foreach ($preloads as $varSet) {
			$varNames = explode(self::PRELOAD_SEP, $varSet->variables[self::KEY_PRELOAD_DATA]);
			$vars = [];
			foreach ($varNames as $varName) {
				$vars[$varName] = false;
			}

			self::$preloadVarSets[$varSet->name] = new MetaTemplateSet($varSet->name, $vars);
		}

		$sortOrder = [];
		if (isset($magicArgs[self::NA_ORDER])) {
			$orderArg = trim($frame->expand($magicArgs[self::NA_ORDER]));
			if (strlen($orderArg) > 0) {
				$sortOrder = explode(',', $orderArg);
			}
		}

		$setLimit = is_null($setName) ? [] : [$setName];
		$fieldLimit = [];
		if (!empty(self::$preloadVarSets)) {
			foreach (self::$preloadVarSets as $preloadSet) {
				$fieldLimit = array_merge($fieldLimit, array_keys($preloadSet->variables));
			}

			if (!empty($fieldLimit)) {
				$fieldLimit = array_merge(
					array_unique($fieldLimit),
					$sortOrder
				);
			}
		}

		$rows = MetaTemplateSql::getInstance()->loadListSavedData($namespace, $setName, $conditions, $setLimit, $fieldLimit);
		self::sortRows($rows, $frame, $sortOrder);
		// Add conditions to cache if not already loaded, since we know what those values must be.
		if (!empty($fieldLimit)) {
			foreach ($conditions as $key => $value) {
				if (!isset($fieldLimit[$key])) {
					foreach ($rows as &$row) {
						$row[$key] = $value;
					}

					unset($row);
				}
			}
		}

		self::$preloadCache = self::pagifyRows($rows);

		$templateName = $templateTitle->getNamespace() === NS_TEMPLATE ? $templateTitle->getText() : $templateTitle->getFullText();
		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
		$retval = self::createTemplates($templateName, $rows, ParserHelper::getSeparator($magicArgs));
		if (!$debug) {
			$output->setExtensionData(self::KEY_SAVE_IGNORED, false);
			// This is the first time we dom/expand since loading. Any raw arguments signify an unkown value at save
			// time, so don't parse those.
			$dom = $parser->preprocessToDom($retval);
			$retval = $frame->expand($dom);
			if ($output->getExtensionData(self::KEY_SAVE_IGNORED)) {
				$retval = ParserHelper::error('metatemplate-listsaved-template-saveignored', $templateTitle->getFullText()) . $retval;
			}

			$output->setExtensionData(self::KEY_SAVE_IGNORED, null);
		}

		self::$preloadVarSets = [];
		return ParserHelper::formatPFForDebug($retval, $debug, true);
	}

	/**
	 * Loads variable values from another page.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
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
	public static function doLoad(Parser $parser, PPFrame $frame, array $args)
	{
		$parser->addTrackingCategory('metatemplate-tracking-load');

		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			MetaTemplate::NA_CASE,
			ParserHelper::NA_DEBUG,
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

		$loadTitle = trim($frame->expand($values[0]));
		$loadTitle = Title::newFromText($loadTitle);
		if (
			!$loadTitle ||
			!$loadTitle->canExist()
		) {
			return;
		}

		unset($values[0]);
		$pageId = $loadTitle->getArticleID();
		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
		$output = $parser->getOutput();
		#RHecho($loadTitle->getFullText(), ' ', $page->getId(), ' ', $page->getLatest());
		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists.
		$latestRev = $loadTitle->getLatestRevID();
		$title = $parser->getTitle();
		if (!$loadTitle->equals($title)) {
			$output->addTemplate($loadTitle, $pageId, $latestRev);
		}

		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		$setName = isset($magicArgs[self::NA_SET])
			? substr($magicArgs[self::NA_SET], 0, self::SAVE_SETNAME_WIDTH)
			: null;
		$set = new MetaTemplateSet($setName, []);
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		$loads = [];
		foreach ($translations as $srcName => $destName) {
			if ($debug) {
				// As the array key, $srcName will have been cast to int if it looks like one, so cast it back.
				$loads[] = (string)$srcName === $destName
					? $srcName
					: "$srcName->$destName";
			}

			[, $dom] = MetaTemplate::getVarDirect($frame, $destName, $anyCase);
			if (is_null($dom)) {
				$set->variables[$srcName] = false;
			}
		}

		if ($debug) {
			$debugInfo = [
				'Requested' => $loads,
				'Needed' => array_keys($set->variables)
			];
		}

		if (!empty($set->variables)) {
			// Next, check preloaded variables.
			/** @var MetaTemplatePage $cachePage */
			$preloadVars = self::$preloadVarSets[$setName]->variables ?? [];
			$cachePage = self::$preloadCache[$pageId] ?? null;
			$cacheVars = $cachePage ? $cachePage->sets[$setName]->variables ?? [] : [];
			$preloadVars = array_merge($preloadVars, $cacheVars);
			if (!empty($preloadVars)) {
				#RHecho('Preload \'', Title::newFromID($pageId)->getFullText(), '\' Set \'', $setName, "'\n\$set: ", $set, "\n\n\$cacheSet: ", $cacheSet);
				$intersect = array_intersect_key($set->variables, $preloadVars);
				foreach ($intersect as $srcName => $ignored) {
					if (isset($cacheVars[$srcName])) {
						MetaTemplate::setVar($frame, $translations[$srcName], $cacheVars[$srcName]);
					}

					unset($set->variables[$srcName]);
				}

				if ($debug && !empty($intersect)) {
					$debugInfo += ['From cache' => array_keys($intersect)];
				}
			} elseif ($cachePage) {
				// There's a cached page with no sets. This means there's no data saved on the page at all, so clear everything.
				$set->variables = [];
			}
		}

		if (!empty($set->variables)) {
			if ($debug) {
				$debugInfo += ['From DB' => array_keys($set->variables)];
			}

			if ($pageId !== $parser->getTitle()->getArticleID() || !self::loadFromSaveData($output, $set)) {
				if (!MetaTemplateSql::getInstance()->loadSetFromPage($pageId, $set)) {
					$loadTitle = WikiPage::factory($loadTitle)->getRedirectTarget();
					if (!is_null($loadTitle) && $loadTitle->exists()) {
						$pageId = $loadTitle->getArticleID();
						$output->addTemplate($loadTitle, $pageId, $loadTitle->getLatestRevID());
						MetaTemplateSql::getInstance()->loadSetFromPage($pageId, $set);
					}
				}
			}

			foreach ($set->variables as $srcName => $varValue) {
				if ($varValue !== false) {
					$destName = $translations[$srcName];
					// Faulty markers make preprocessToDom crash, so remove them.
					$varValue = self::removeMarkers($varValue);
					$dom = $parser->preprocessToDom($varValue);
					// If any {{{args}}} appear in the result, it's because they were unresolved at save time; we
					// exclude them here so that we don't pick up local values.
					$varValue = $frame->expand($dom, PPFrame::NO_ARGS);
					MetaTemplate::setVarDirect($frame, $destName, $dom, $varValue);
				}
			}
		}

		if ($debug && !empty($debugInfo)) {
			$maxLen = 0;
			foreach ($debugInfo as $key => $value) {
				$len = strlen($key);
				if ($len > $maxLen) {
					$maxLen = $len;
				}
			}

			$retval = [];
			foreach ($debugInfo as $key => $value) {
				$retval[] =
					str_pad($key, $maxLen, ' ', STR_PAD_LEFT) .
					': ' .
					print_r(implode('|', $value), true);
			}

			return ParserHelper::formatPFForDebug(implode("\r", $retval), $debug, false, 'Load Information');
		}

		return;
	}

	/**
	 * Saves the specified variable names as metadata to be used by #listsaved.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments: The data to preload. Names must be as they're stored in the database.
	 *
	 * @return void
	 */
	public static function doPreload(Parser $parser, PPFrame $frame, array $args): void
	{
		$parser->addTrackingCategory('metatemplate-tracking-load');
		if ($frame->depth > 0) {
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

		if (isset(self::$preloadVarSets[$setName])) {
			$set = self::$preloadVarSets[$setName];
		} else {
			$set = new MetaTemplateSet($setName);
			self::$preloadVarSets[$setName] = $set;
		}

		foreach ($values as $value) {
			$varName = trim($frame->expand(ParserHelper::getKeyValue($frame, $value)[1]));
			$set->variables[$varName] = false;
		}

		if (!$parser->getOptions()->getIsPreview()) {
			$varList = implode(self::PRELOAD_SEP, array_keys($set->variables));
			self::addToSet($parser, $setName, [self::KEY_PRELOAD_DATA => $varList]);
		}
	}

	/**
	 * Saves the specified values to the database.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
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

		/** @var MetaTemplateVariable[] $varsToSave */
		$varsToSave = [];
		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		self::$saveMode = ($magicArgs[self::NA_SAVEMARKUP] ?? false) ? 2 : 1;
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		#RHDebug::writeFile('Translations: ', $translations);
		foreach ($translations as $srcName => $destName) {
			[, $dom] = MetaTemplate::getVarDirect($frame, $srcName, $anyCase);
			if ($dom) {
				// Reparses the value as if included, so includeonly works as expected.
				$varValue = trim($frame->expand($dom, PPFrame::RECOVER_ORIG));
				$dom = $parser->preprocessToDom($varValue, Parser::PTD_FOR_INCLUSION);
				// Now, re-expand to save candidate.
				$varValue = $frame->expand($dom, self::$saveMode === 2 ? PPFrame::NO_TEMPLATES : 0);
				$varValue = self::stripAll($parser, $varValue);
				if (self::$saveMode === 2) {
					// Full expansion allows check for <savemarkup> vs. |savemarkup=1.
					$frame->expand($dom);
				}

				// At last, if it's not empty, we can add the value to the save list.
				$varsToSave[$destName] = $varValue;
			}
		}

		self::$saveMode = 0;
		#RHshow('Vars to Save', $varsToSave);

		// Normally, the "ignored" value will be null when #save is run. If it's false, then we know we got here via
		// #listsaved. We should *never* save anything during a #listsaved operation! If something is trying, we flip
		// the flag value to true to let the caller know that that's what happened. The check for false instead of not
		// null makes sure we only set it to true if we haven't already done so.
		$output = $parser->getOutput();
		if ($output->getExtensionData(self::KEY_SAVE_IGNORED) === false) {
			$output->setExtensionData(self::KEY_SAVE_IGNORED, true);
		}

		if ($title->getNamespace() !== NS_TEMPLATE) {
			$setName = substr($magicArgs[self::NA_SET] ?? '', 0, self::SAVE_SETNAME_WIDTH);
			// This is effectively what saves the variables, though the actual save comes at the end in
			// onParserAfterTidy().
			self::addToSet($parser, $setName, $varsToSave);
		}

		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
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
	public static function doSaveMarkupTag($content, array $attributes, Parser $parser, PPFrame $frame): array
	{
		$parser->addTrackingCategory('metatemplate-tracking-save');

		#RHshow('Frame', ' ', $frame->depth, ' ', $frame->title->getFullText(), ' ', $frame->getArguments());
		switch (self::$saveMode) {
			case 1: // Normal save but may have <savemarkup>
				$dom = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
				$content = $frame->expand($dom, PPFrame::NO_TEMPLATES);
				break;
			case 2: // Overlapping <savemarkup> and {{#save:...|savemarkup=1}}
				$msg = wfMessage('metatemplate-listsaved-savemarkup-overlap')->text();
				$parser->getOutput()->addWarning($msg);
				// $parser->addTrackingCategory('metatemplate-tracking-savemarkup-overlap');
				break;
			case 3: // Setting with #local or variants
				// This variant retains both templates and inclusion info.
				$dom = $parser->preprocessToDom($content);
				$content = '<savemarkup>' . $frame->expand($dom, PPFrame::NO_TEMPLATES | PPFrame::NO_IGNORE) . '</savemarkup>';
				break;
			default: // Displaying, not saving
				$dom = $parser->preprocessToDom($content);
				$content = $frame->expand($dom);
				break;
		}

		#RHshow(self::$saveMode . ' Content', $content);
		return [$content, 'markerType' => 'none'];
	}

	public static function init()
	{
		if (MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			self::$mwSet = self::$mwSet ?? VersionHelper::getInstance()->getMagicWord(self::NA_SET)->getSynonym(0);
		}
	}

	/**
	 * Saves all pending data.
	 *
	 * @param int $pageId The page id of the data to be saved.
	 * @param mixed $revision
	 */
	public static function save(WikiPage $page): void
	{
		#RHDebug::writeFile(__METHOD__, ': ', $page->getTitle()->getPrefixedText());
		if (!MetaTemplate::getSetting(MetaTemplate::STTNG_ENABLEDATA)) {
			return;
		}

		// Before saving, double-check that we're actually saving the data to a valid page that's not a duplicate of
		// the previous call.
		$latest = $page->getLatest();
		if ($latest <= 0 || $latest === self::$prevId) {
			return;
		}

		self::$prevId = $latest;
		$title = $page->getTitle();
		$options = $page->makeParserOptions('canonical');
		$parserOutput = $page->getParserOutput($options, null, true);
		if (!$parserOutput) {
			// Not sure it's possible for the revision to not be found, but abort if it ever happens.
			return;
		}

		#RHDebug::writeFile(__METHOD__, ' - Saving: ', $page->getTitle()->getPrefixedText());
		/** @var MetaTemplateSetCollection $sd */
		$sd = $parserOutput->getExtensionData(self::KEY_SAVE_DATA);
		if ($sd && $sd->isPreview) {
			return;
		}

		$sql = MetaTemplateSql::getInstance();
		$pageId = $page->getId();
		if ($sd && !empty($sd->sets)) {
			if ($sd->articleId === 0) {
				// I'm not sure if this is possible anymore, but leaving it here in case.
				$sd->articleId = $pageId;
				$sd->revId = $latest;
			} elseif ($sd->articleId !== $pageId) {
				// This should never happen!
				$conflictTitle = Title::newFromID($sd->articleId)->getPrefixedText();
				wfWarn("Page ID conflict: trying to save [[$conflictTitle]] data to [[{$title->getPrefixedText()}]]; save aborted.");
				return;
			}

			$doUpdates = $sd->revId > 0 ? $sql->saveVars($sd) : false;
		} else {
			// Check whether the page used to have variables; if not, delete should trigger an update.
			$doUpdates = $sql->hasPageVariables($pageId) && $sql->deleteVariables($pageId);
		}

		if ($doUpdates) {
			VersionHelper::getInstance()->doSecondaryDataUpdates($page, $parserOutput, $options);
		}
	}
	#endregion

	#region Private Static Functions
	/**
	 * Adds the provided list of variables to the set provided and to the parser output.
	 *
	 * @param Title $title The page the variables should be added to.
	 * @param string $setName The set to be added to.
	 * @param array $variables The variables to add.
	 *
	 * @return void
	 */
	private static function addToSet(Parser $parser, string $setName, array $variables): void
	{
		#RHDebug::writeFile(__METHOD__, $setName, ' => ', $variables);
		if (count($variables)) {
			$output = $parser->getOutput();
			/** @var MetaTemplateSetCollection $data */
			$data = $output->getExtensionData(self::KEY_SAVE_DATA);
			if (is_null($data)) {
				$title = $parser->getTitle();
				$data = new MetaTemplateSetCollection(
					$title->getArticleID(),
					$title->getLatestRevID(),
					$parser->getOptions()->getIsPreview()
				);
			}

			$data->addToSet(0, $setName, $variables);
			$output->setExtensionData(self::KEY_SAVE_DATA, $data);
		}
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
	private static function createTemplates(string $templateName, array $rows, string $separator): string
	{
		$retval = '';
		$open = '{{';
		$close = '}}';
		foreach ($rows as $row) {
			$retval .= "$separator$open$templateName";
			$retval .= '|' . MetaTemplate::$mwFullPageName . '=' . $row[MetaTemplate::$mwFullPageName];
			$retval .= '|' . MetaTemplate::$mwNamespace . '=' . $row[MetaTemplate::$mwNamespace];
			$retval .= '|' . MetaTemplate::$mwPageName . '=' . $row[MetaTemplate::$mwPageName];
			$retval .= '|' . self::$mwSet . '=' . $row[self::$mwSet];
			$retval .= $close;
		}

		return strlen($retval)
			? substr($retval, strlen($separator))
			: $retval;
	}

	/**
	 * Retrieves the requested set of variables already on the page as though they had been loaded from the database.
	 *
	 * @param MetaTemplateSet $set The set to load.
	 *
	 * @return bool True if all variables were loaded.
	 */
	private static function loadFromSaveData(ParserOutput $output, MetaTemplateSet &$set): bool
	{
		/** @var MetaTemplateSetCollection $data */
		$data = $output->getExtensionData(self::KEY_SAVE_DATA);
		if (!$data) {
			return false;
		}

		#RHshow('Page Variables', $pageVars);
		$pageSet = $data->sets[$set->name] ?? false;
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
				if (isset($retval[$pageId])) {
					$page = $retval[$pageId];
				} else {
					$page = new MetaTemplatePage($row[MetaTemplate::$mwNamespace], $row[MetaTemplate::$mwPageName]);
					// $retval[$pageId] does not maintain order; array_merge does, with RHS overriding LHS in the event of
					// duplicates.
					$retval += [$pageId => $page];
				}
			} else {
				$page = $retval[$pageId];
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

			$page->sets[$setName] = new MetaTemplateSet($setName, $row);
		}

		return $retval;
	}

	/**
	 * Sorts the listsaved rows in place, expanding the text before sorting so that output from templates is taken into
	 * account.
	 *
	 * @param array $rows The rows to sort.
	 * @param PPFrame $frame The frame in use.
	 */
	private static function sortRows(array &$rows, PPFrame $frame, array $sortOrder): void
	{
		foreach ($rows as $row) {
			foreach ($row as $field => &$value) {
				$value = $frame->expand($value, PPFrame::NO_ARGS);
			}

			unset($value);
		}

		$sortOrder[] = MetaTemplate::$mwPageName;
		$sortOrder[] = self::$mwSet;
		$used = [];
		$args = [];
		foreach ($sortOrder as $field) {
			if (!isset($used[$field])) {
				// We can't use array_column here since rows are not guaranteed to have $field.
				$arg = [];
				foreach ($rows as $key => $data) {
					$arg[$key] = $data[$field] ?? false;
				}

				$args[] = $arg;
				$used[$field] = true;
			}
		}

		$args[] = &$rows;
		call_user_func_array('array_multisort', $args);
	}

	private static function removeMarkers(string $text)
	{
		return preg_replace(self::STRIP_MARKERS, '', $text);
	}

	private static function stripAll(Parser $parser, ?string $text)
	{
		$versionHelper = VersionHelper::getInstance();
		$oldValue = null;
		while (preg_match(self::STRIP_MARKERS, $text) && $oldValue !== $text) {
			$oldValue = $text;
			$text = $versionHelper->getStripState($parser)->unstripBoth($text);
			VersionHelper::getInstance()->replaceLinkHoldersText($parser, $text);
		}

		return $text;
	}
	#endregion
}
