<?php

/**
 * Data functions of MetaTemplate (#listsaved, #load, #save).
 */
class MetaTemplateData
{
	public const KEY_BULK_LOAD = MetaTemplate::KEY_METATEMPLATE . '#bulkLoad';
	public const KEY_IGNORE_SET = MetaTemplate::KEY_METATEMPLATE . '#ignoreSet';
	public const KEY_PRELOAD = MetaTemplate::KEY_METATEMPLATE . '#preload';
	public const KEY_PRELOAD_DATA = MetaTemplate::KEY_METATEMPLATE . '#preloadData';
	public const KEY_SAVE = MetaTemplate::KEY_METATEMPLATE . '#save';

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

	private const KEY_PARSEONLOAD = MetaTemplate::KEY_METATEMPLATE . '#parseOnLoad';
	private const KEY_SAVE_MODE = MetaTemplate::KEY_METATEMPLATE . '#saving';
	private const KEY_SAVE_IGNORED = MetaTemplate::KEY_METATEMPLATE . '#saveIgnored';

	public const SAVE_MARKUP_FLAGS = PPFrame::NO_TEMPLATES;

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
	 *
	 */
	public static function doListSaved(Parser $parser, PPFrame $frame, array $args): array
	{
		static $magicWords;
		$magicWords = $magicWords ?? new MagicWordArray([
			MetaTemplate::NA_CASE,
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

		if (!isset($values[0])) { // Should be impossible, but better safe than crashy.
			return [ParserHelper::error('metatemplate-listsaved-template-empty')];
		}

		$template = trim($frame->expand($values[0]));
		if (!strlen($template)) {
			return [ParserHelper::error('metatemplate-listsaved-template-empty')];
		}

		unset($values[0]);

		/**
		 * @var array $conditions
		 * @var array $extrasnamed */
		[$conditions, $extras] = ParserHelper::splitNamedArgs($frame, $values);
		if (!count($conditions)) {
			return [ParserHelper::error('metatemplate-listsaved-conditions-missing')];
		}

		foreach ($conditions as $key => &$newValue) {
			$newValue = $frame->expand($newValue);
		}

		unset($newValue);
		$output = $parser->getOutput();
		if (!empty($extras)) {
			$parser->addTrackingCategory('metatemplate-tracking-listsaved-extraparams');
			$output->addWarning(wfMessage('metatemplate-listsaved-warn-extraparams')->plain());
		}

		$templateTitle = Title::newFromText($template, NS_TEMPLATE);
		if (!$templateTitle) {
			return [ParserHelper::error('metatemplate-listsaved-template-missing', $template)];
		}

		$page = WikiPage::factory($templateTitle);
		if (!$page) {
			return [ParserHelper::error('metatemplate-listsaved-template-missing', $template)];
		}

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

		$articleId = $templateTitle->getArticleID();

		/** @var MetaTemplateSet[] $sets */
		$sets = $output->getExtensionData(self::KEY_PRELOAD) ?? [];
		$preloadSet = new MetaTemplateSet(null, [self::KEY_PRELOAD_DATA => false]);
		MetaTemplateSql::getInstance()->getPreloadInfo($sets, $articleId, $preloadSet, self::PRELOAD_SEP);
		$output->setExtensionData(self::KEY_PRELOAD, $sets);

		$namespace = isset($magicArgs[self::NA_NAMESPACE])
			? $parser->getConverterLanguage()->getNsIndex($magicArgs[self::NA_NAMESPACE])
			: null;
		#RHecho($namespace, "\n", $conditions, "\n", $sets);
		$rows = MetaTemplateSql::getInstance()->loadListSavedData($namespace, $conditions, $sets, $frame);
		#RHshow('Pages', $pages);

		$orderNames = $magicArgs[self::NA_ORDER] ?? null;
		$orderNames = $orderNames ? explode(',', $orderNames) : [];
		$orderNames[] = 'pagename';
		$orderNames[] = 'set';
		$pages = self::processListSaved($rows, $orderNames);
		$output->setExtensionData(self::KEY_BULK_LOAD, $pages);

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

		return ParserHelper::formatPFForDebug($retval, $debug, true);
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
	 *
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

		$loadTitle = Title::newFromText($frame->expand($values[0]));
		if (
			!$loadTitle ||
			!$loadTitle->canExist()
		) {
			return;
		}

		unset($values[0]);
		$page = WikiPage::factory($loadTitle);
		$pageId = $page->getId();
		$output = $parser->getOutput();
		#RHecho($loadTitle->getFullText(), ' ', $page->getId(), ' ', $page->getLatest());
		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists.
		$output->addTemplate($loadTitle, $pageId, $page->getLatest());

		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		$setName = isset($magicArgs[self::NA_SET])
			? substr($magicArgs[self::NA_SET], 0, self::SAVE_SETNAME_WIDTH)
			: null;
		$set = new MetaTemplateSet($setName, [], $anyCase);
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		foreach ($translations as $key => $value) {
			if (!MetaTemplate::getVar($frame, $value, $anyCase, false)) {
				$set->variables[$key] = false;
			}
		}

		#RHshow('Set', $set);

		// If all are already loaded, there's nothing else to do.
		if (!count($set->variables)) {
			return;
		}

		#RHecho('Set will be loaded.');
		$pageId = $page->getId();

		// Next, check preloaded variables
		/** @var MetaTemplateSet $preloadSet */
		$preloadSet = $output->getExtensionData(self::KEY_PRELOAD)[$setName] ?? null;
		if ($preloadSet) {
			/** @var MetaTemplatePage $bulkPage */
			$bulkPage = $output->getExtensionData(self::KEY_BULK_LOAD)[$pageId] ?? null;
			if ($bulkPage) {
				$bulkSet = $bulkPage->sets[$setName] ?? null;
				if ($bulkSet) {
					#RHecho('Preload \'', Title::newFromID($pageId)->getFullText(), '\' Set \'', $setName, "'\nWant set: ", $set, "\n\nGot set: ", $bulkSet);
					$bulkSet->resolveVariables($frame);
					foreach ($preloadSet->variables as $varName => $value) {
						$varValue = $bulkSet->variables[$varName] ?? false;
						if ($varValue !== false) {
							MetaTemplate::setVar($frame, $varName, $varValue, $anyCase);
						}

						// We unset the variable whether or not it was found so that any future #loads don't try to get
						// something that we already know isn't there.
						#RHshow('Unsetting', $varName);
						unset($set->variables[$varName]);
					}

					// If we got everything, there's nothing else to do.
					if (!count($set->variables)) {
						return;
					}
				}
			}
		}

		#RHshow('Trying to load vars from page [[', $page->getTitle()->getFullText(), ']]', $set);
		/** @var MetaTemplateSetCollection $vars */
		$vars = $output->getExtensionData(MetaTemplateData::KEY_SAVE);
		if ($vars && self::loadFromOutput($output, $set)) {
			$set->resolveVariables($frame);
			foreach ($set->variables as $varName => $varValue) {
				if ($varValue !== false) {
					MetaTemplate::setVar($frame, $varName, $varValue, $set->anyCase);
					unset($set->variables[$varName]);
				}
			}
		}

		// If all are already loaded, there's nothing else to do.
		if (!count($set->variables)) {
			return;
		}

		#RHshow('Trying to load vars from database [[', $page->getTitle()->getFullText(), ']]', $set);
		$success = MetaTemplateSql::getInstance()->loadSetFromDb($pageId, $set);
		if ($success) {
			$set->resolveVariables($frame);
			foreach ($set->variables as $varName => $varValue) {
				if ($varValue !== false) {
					MetaTemplate::setVar($frame, $varName, $varValue, $set->anyCase);
				}
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
	 *
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
		$sets = $output->getExtensionData(self::KEY_PRELOAD) ?? [];
		if (isset($sets[$setName])) {
			$set = $sets[$setName];
		} else {
			$set = new MetaTemplateSet($setName);
			$sets[$setName] = $set;
		}

		foreach ($values as $value) {
			$varName = $frame->expand(ParserHelper::getKeyValue($frame, $value)[1]);
			$set->variables[$varName] = new MetaTemplateVariable(false, false);
		}

		$varList = implode(self::PRELOAD_SEP, array_keys($set->variables));
		self::addToSet($parser->getTitle(), $output, $setName, [self::KEY_PRELOAD_DATA => new MetaTemplateVariable($varList, false)]);
		$output->setExtensionData(self::KEY_PRELOAD, $sets);
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
	 *
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
			/** @var PPNode_Hash_Tree|false */
			[$varNodes] = MetaTemplate::getVar($frame, $srcName, $anyCase, false);
			if ($varNodes) {
				$output->setExtensionData(self::KEY_SAVE_MODE, $saveMarkup);
				$varValue = $frame->expand($varNodes, $saveMarkup ? self::SAVE_MARKUP_FLAGS : 0);
				$varValue = VersionHelper::getInstance()->getStripState($parser)->unstripBoth($varValue);
				$parseOnLoad =
					($saveMarkup && self::treeHasTemplate($varNodes)) |
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
			$out = '';
			foreach ($varsToSave as $key => $value) {
				$out .= "\n" . $key;
				if ($value->parseOnLoad) {
					$out .= ' (parse on load)';
				}

				$out .= ' = ' . (string)$value->value;
			}

			#RHshow('Test', $out);
			return ParserHelper::formatPFForDebug(substr($out, 1), true);
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
	 *
	 */
	public static function doSaveMarkupTag($content, array $attributes, Parser $parser, PPFrame_Hash $frame): array
	{
		$saveMode = $parser->getOutput()->getExtensionData(self::KEY_SAVE_MODE);
		if (is_null($saveMode)) { // Not saving, just displaying <savemarkup> or {{#save:...|savemarkup=1}}
			$value = $parser->recursiveTagParse($content, $frame);
			#RHshow('Recursive Tag Parse', $value);
			return [$value, 'markerType' => 'general'];
		}

		if ($saveMode) { // Saving <savemarkup> inside {{#save:...|savemarkup=1}}
			$value = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
			if (self::treeHasTemplate($value)) {
				$parser->getOutput()->setExtensionData(self::KEY_PARSEONLOAD, true);
			}

			$parent = $frame->parent ?? $frame;
			$value = $parent->expand($value, self::SAVE_MARKUP_FLAGS);

			#RHshow('Double Parsed', $value);
			return [$value, 'markerType' => 'nowiki'];
		}

		// Saving with standard {{#save:...|savemarkup=1}}
		$value = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
		if (self::treeHasTemplate($value)) {
			$parser->getOutput()->setExtensionData(self::KEY_PARSEONLOAD, true);
		}

		$value = $frame->expand($value, self::SAVE_MARKUP_FLAGS);
		$value = VersionHelper::getInstance()->getStripState($parser)->unstripBoth($value);

		#RHshow('Value', $value);
		return [$value, 'markerType' => 'nowiki'];
	}

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
	 * Converts the results of loadListSavedData() to the text of the templates to execute.
	 *
	 * @param Language $language The language to use for namespace text.
	 * @param string $templateName The template's name. Like with wikitext template calls, this is assumed to be in
	 *                             Template space unless otherwise specified.
	 * @param MetaTemplatePage[] $pages The parameters to pass to each of the template calls.
	 * @param string $separator The separator to use between each template.
	 *
	 * @return string The text of the template calls.
	 *
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
	 * Sorts the results according to user-specified order (if any), then page name, and finally set.
	 *
	 * @param array $arr The array to sort.
	 * @param array $sortOrder A list of field names to sort by. In the event of duplication, only the first instance
	 *                         counts.
	 *
	 * @return array The sorted array.
	 *
	 */
	private static function processListSaved(array $arr, array $sortOrder): array
	{
		$used = [];
		$columns = [];
		foreach ($sortOrder as $field) {
			if (!isset($used[$field])) {
				// We can't use array_column here since rows are not guaranteed to have $field.
				$column = [];
				foreach ($arr as $key => $data) {
					$column[$key] = $data[$field] ?? false;
				}

				$columns[] = $column;
				$used[$field] = true;
			}
		}

		$columns[] = $arr;
		call_user_func_array('array_multisort', $columns);

		// Now that sorting is done, morph records into MetaTemplatePages.
		$retval = [];
		foreach ($arr as $record) {
			$pageId = $record['pageid'];
			if (!isset($retval[$record['pageid']])) {
				$page = new MetaTemplatePage($record['namespace'], $record['pagename']);
				$retval[$pageId] = $page;
			}

			// Unset the parent data/sortable fields, leaving only the set data.
			$setName = $record['set'];
			unset(
				$record['namespace'],
				$record['pageid'],
				$record['pagename'],
				$record['set'],
			);

			$page->addToSet($setName, $record);
		}

		return $retval;
	}

	/**
	 * Retrieves the requested set of variables already on the page as though they had been loaded from the database.
	 *
	 * @param ParserOutput $output The current ParserOutput object.
	 * @param MetaTemplateSet $set The set to load.
	 *
	 * @return bool True if variables were loaded.
	 *
	 */
	private static function loadFromOutput(ParserOutput $output, MetaTemplateSet &$set): bool
	{
		/** @var MetaTemplateSetCollection $pageVars */
		$pageVars = $output->getExtensionData(self::KEY_SAVE);
		if (!$pageVars) {
			return false; // $pageVars = new MetaTemplateSetCollection($pageId, 0);
		}

		#RHshow('Page Variables', $pageVars);
		$pageSet = $pageVars->sets[$set->setName];
		if (!$pageSet) {
			return false;
		}

		$retval = false;
		#RHshow('Page Set', $pageSet);
		if ($set->variables) {
			foreach ($set->variables as $varName => &$var) {
				if ($var === false && isset($pageSet->variables[$varName])) {
					$copy = $pageSet->variables[$varName];
					$var = new MetaTemplateVariable($copy->value, $copy->parseOnLoad);
					$retval = true;
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
	 *
	 */
	private static function treeHasTemplate(PPNode_Hash_Tree $node): bool
	{
		for ($child = $node->getFirstChild(); $child; $child = $child->getNextSibling()) {
			if (($child instanceof PPNode_Hash_Tree) && ($child->name === 'template' || self::treeHasTemplate($child))) {
				return true;
			}
		}

		return false;
	}
}
