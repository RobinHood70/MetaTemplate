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
	private const KEY_SAVE_IGNORED = MetaTemplate::KEY_METATEMPLATE . '#saveIgnored';

	private const SAVE_MARKUP_FLAGS = PPFrame::NO_TEMPLATES | PPFrame::NO_IGNORE;

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
	 *
	 */
	public static function doListSaved(Parser $parser, PPFrame $frame, array $args): array
	{
		[$magicArgs, $values] = ParserHelper::getMagicArgs(
			$frame,
			$args,
			self::NA_NAMESPACE,
			self::NA_ORDER,
			MetaTemplate::NA_CASE,
			ParserHelper::NA_DEBUG,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			ParserHelper::NA_SEPARATOR
		);

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

		// Track the page here rather than letting the output do it, since the template should be tracked even if it
		// doesn't exist.
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
		MetaTemplateSql::getInstance()->getPreloadInfo($sets, $articleId);
		$output->setExtensionData(self::KEY_PRELOAD, $sets);

		$language = $parser->getConverterLanguage();
		$namespace = $magicArgs[self::NA_NAMESPACE] ?? null;
		$namespace = is_null($namespace) ? -1 : $language->getNsIndex($namespace);
		#RHshow($namespace, "\n", $conditions, "\n", $sets);
		$pages = MetaTemplateSql::getInstance()->loadListSavedData($namespace, $conditions, $sets);
		#RHshow('Pages: ', $pages);

		$orderNames = $magicArgs[self::NA_ORDER] ?? null;
		$orderNames = $orderNames ? explode(',', $orderNames) : [];
		$orderNames[] = 'pagename';
		$orderNames[] = 'set';
		$pages = self::listSavedSort($pages, $orderNames);
		$output->setExtensionData(self::KEY_BULK_LOAD, $pages);

		$templateName = $templateTitle->getNamespace() === NS_TEMPLATE ? $templateTitle->getText() : $templateTitle->getFullText();
		$debug = ParserHelper::checkDebugMagic($parser, $frame, $magicArgs);
		$retval = self::createTemplates($language, $templateName, $pages, ParserHelper::getSeparator($magicArgs));
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
	 *
	 */
	public static function doLoad(Parser $parser, PPFrame $frame, array $args): void
	{
		[$magicArgs, $values] = ParserHelper::getMagicArgs(
			$frame,
			$args,
			MetaTemplate::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET
		);

		if (!ParserHelper::checkIfs($frame, $magicArgs)) {
			return;
		}

		if (count($values) < 2) {
			return;
		}

		$loadTitle = Title::newFromText($frame->expand($values[0]));
		if (
			!$loadTitle ||
			!$loadTitle->canExist() ||
			$loadTitle->getFullText() === $parser->getTitle()->getFullText()
		) {
			return;
		}

		unset($values[0]);
		$page = WikiPage::factory($loadTitle);
		$output = $parser->getOutput();
		#RHshow($loadTitle->getFullText(), ' ', $page->getId(), ' ', $page->getLatest());
		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists.
		$output->addTemplate($loadTitle, $page->getId(), $page->getLatest());

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

		#RHshow('Set: ', $set);

		// If all are already loaded, there's nothing else to do.
		if (!count($set->variables)) {
			return;
		}

		#RHshow('Set will be loaded.');
		$pageId = $page->getId();

		// Next, check preloaded variables
		/** @var MetaTemplateSet $preloadSet */
		$preloadSet = $output->getExtensionData(self::KEY_PRELOAD)[$setName] ?? null;
		if ($preloadSet) {
			/** @var MetaTemplatePage $bulkPage */
			$bulkSet = $output->getExtensionData(self::KEY_BULK_LOAD)[$pageId]->sets[$setName] ?? null;
			#RHshow('Preload \'', Title::newFromID($pageId)->getFullText(), '\' Set \'', $setName, "'\nWant set: ", $set, "\n\nGot set: ", $bulkSet);
			foreach ($preloadSet->variables as $key => $value) {
				if (isset($bulkSet->variables[$key])) {
					$varValue = $bulkSet->variables[$key];
					$varValue = $varValue->parseOnLoad
						? $parser->preprocessToDom($varValue->value)
						: $varValue->value;
					MetaTemplate::setVar($frame, $key, $varValue, $anyCase);
				}

				// We unset the variable whether or not it was found so that any future #loads don't try to get
				// something that we already know isn't there.
				unset($set->variables[$key]);
			}

			// If we got everything, there's nothing else to do.
			if (!count($set->variables)) {
				return;
			}
		}

		#RHshow('Vars to Load from page [[', $page->getTitle()->getFullText(), ']]: ', $set);
		$success = MetaTemplateSql::getInstance()->loadSetFromDb($pageId, $set);
		if ($success) {
			foreach ($set->variables as $varName => $var) {
				$varValue = $var->parseOnLoad
					? $parser->preprocessToDom($var->value)
					: $var->value;

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
	 * @param PPFrame $frame The frame in use.
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

		[$magicArgs, $values] = ParserHelper::getMagicArgs(
			$frame,
			$args,
			self::NA_SET
		);

		$output = $parser->getOutput();
		$setName = $parser->getOutput()->getExtensionData(self::KEY_IGNORE_SET)
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

		foreach ($values as &$value) {
			$arg = ParserHelper::getKeyValue($frame, $value)[1];
			$value = $frame->expand($arg);
			$var = new MetaTemplateVariable(false, false);
			$set->variables[$value] = $var;
		}

		$varList = implode(self::PRELOAD_SEP, array_keys($set->variables));
		self::addToSet($parser->getTitle(), $output, $setName, [self::KEY_PRELOAD_DATA => new MetaTemplateVariable($varList, false)]);
		$output->setExtensionData(self::KEY_PRELOAD, $sets);
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
	 *
	 */
	public static function doSave(Parser $parser, PPFrame $frame, array $args): void
	{
		$title = $parser->getTitle();
		if (
			$parser->getOptions()->getIsPreview() ||
			$title->getNamespace() === NS_TEMPLATE ||
			!$title->canExist()
		) {
			return;
		}

		[$magicArgs, $values] = ParserHelper::getMagicArgs(
			$frame,
			$args,
			MetaTemplate::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET,
			self::NA_SAVEMARKUP
		);

		if (!ParserHelper::checkIfs($frame, $magicArgs) || count($values) == 0) {
			return;
		}

		$output = $parser->getOutput();
		$anyCase = MetaTemplate::checkAnyCase($magicArgs);
		$saveMarkup = $magicArgs[self::NA_SAVEMARKUP] ?? false;
		$varsToSave = [];
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		foreach ($translations as $srcName => $destName) {
			$output->setExtensionData(self::KEY_PARSEONLOAD, true);
			$varValue = MetaTemplate::getVar($frame, $srcName, $anyCase, false, false);
			if ($varValue === false) {
				continue;
			}

			$varValue = $frame->expand($varValue, $saveMarkup ? self::SAVE_MARKUP_FLAGS : 0);
			if (!$output->getExtensionData(self::KEY_PARSEONLOAD)) {
				// The value of saveParseOnLoad changed during expansion, meaning that there are <savemarkup> tags
				// present.
				$parseOnLoad = true;
				$varValue = VersionHelper::getInstance()->getStripState($parser)->unstripGeneral($varValue);
			} else {
				$parseOnLoad = $saveMarkup;
			}

			// Double-check whether the value actually needs to be parsed. If the value is a single text node with no
			// siblings (i.e., plain text), it needs no further parsing. For anything else, parse it at the #load end.
			// We dont use self::$SAVE_MARKUP_FLAGS because at this point, anything that's left other than text should
			// be parsed.
			if ($parseOnLoad) {
				$parseCheck = $parser->preprocessToDom($varValue);
				$parseOnLoad = !self::nodeIsTextOnly($parseCheck);
			}

			$varsToSave[$destName] = new MetaTemplateVariable($varValue, $parseOnLoad);
		}

		// Normally, the error value will be null when #save is run. If it's false, then we know we got here via
		// #listsaved. If that's the case, then we've hit an error condition, so we flip the flag value to true. This
		// will only occur if all checks were passed and this is unambiguously active code. The check for false instead
		// of is_null() makes sure we only set it to true if we haven't already done so.
		#RHshow('#save: ', $varsToSave, "\n", $output->getExtensionData(self::KEY_SAVE_IGNORED) ?? 'null');
		if ($output->getExtensionData(self::KEY_SAVE_IGNORED) === false) {
			$output->setExtensionData(self::KEY_SAVE_IGNORED, true);
		}

		#RHshow('Vars to Save: ', $varsToSave, "\nSave All Markup: ", $saveMarkup ? 'Enabled' : 'Disabled');
		$output->setExtensionData(self::KEY_PARSEONLOAD, false); // Probably not necessary, but just in case...
		$setName = substr($magicArgs[self::NA_SET] ?? '', 0, self::SAVE_SETNAME_WIDTH);
		self::addToSet($title, $output, $setName, $varsToSave);
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
	public static function doSaveMarkupTag($content, array $attributes, Parser $parser, PPFrame $frame): string
	{
		if ($parser->getOutput()->getExtensionData(self::KEY_PARSEONLOAD)) {
			$parser->getOutput()->setExtensionData(self::KEY_PARSEONLOAD, false);
			$value = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
			$value = $frame->expand($value, self::SAVE_MARKUP_FLAGS);

			return $value;
		}

		return $parser->recursiveTagParse($content, $frame);
	}

	/**
	 * Initializes magic words.
	 *
	 * @return void
	 *
	 */
	public static function init(): void
	{
		ParserHelper::cacheMagicWords([
			self::NA_NAMESPACE,
			self::NA_ORDER,
			self::NA_PAGENAME,
			self::NA_SAVEMARKUP,
			self::NA_SET,
		]);
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
		#RHshow('addVars: ', $variables);
		if (!count($variables)) {
			return;
		}

		$page = WikiPage::factory($title);
		$pageId = $page->getId();
		$revId = $page->getLatest();
		/** @var MetaTemplateSetCollection $pageVars */
		$pageVars = $output->getExtensionData(self::KEY_SAVE);
		if (!$pageVars) {
			$pageVars = new MetaTemplateSetCollection($pageId, $revId);
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
	private static function createTemplates(Language $language, string $templateName, array $pages, string $separator): string
	{
		$retval = '';
		$open = '{{';
		$close = '}}';
		foreach ($pages as $page) {
			$namespaceName = $language->getNsText($page->namespace);
			$pageName = strtr($page->pagename, '_', ' ');

			ksort($page->sets, SORT_NATURAL);
			if (count($page->sets)) {
				foreach (array_keys($page->sets) as $setName) {
					$retval .= "$separator$open$templateName|namespace=$namespaceName|pagename=$pageName|set=$setName$close";
				}
			} else {
				$retval .= "$separator$open$templateName|namespace=$namespaceName|pagename=$pageName$close";
			}
		}

		return strlen($retval)
			? substr($retval, strlen($separator))
			: $retval;
	}

	/**
	 * Gets the #load results from the current page or its redirect.
	 *
	 * @param Parser $parser The parser in use.
	 * @param Title $loadTitle The title to load results from.
	 * @param WikiPage $page The page version of the previous title.
	 * @param MetaTemplateSet $set
	 * @param array $varsToLoad
	 *
	 * @return bool True if variables were loaded.
	 *
	 */
	private static function getResult(Parser $parser, WikiPage $page, MetaTemplateSet &$set): bool
	{
		$title = $page->getTitle();
		$articleId = $title->getArticleID();
		$output = $parser->getOutput();
		$success = false;
		if ($parser->getTitle()->getArticleID() === $articleId) {
			$success = self::loadFromOutput($output, $set);
		}

		if (!$success) {
			/** @var MetaTemplatePage[] $bulk */
			$bulk = $output->getExtensionData(self::KEY_BULK_LOAD);
			if (isset($bulk[$articleId])) {
				$article = $bulk[$articleId];
				$setName = $set->setName;
				if (isset($article->sets[$setName])) {
					$set = $article->sets[$setName];
				}

				if (!isset($set)) {
					MetaTemplateSql::getInstance()->loadSetFromDb($articleId, $set);
				}
			}
		}

		// If no results were returned and the page is a redirect, see if there are variables on the target page.
		if (!$set && $title->isRedirect()) {
			$page = WikiPage::factory($page->getRedirectTarget());
			$output->addTemplate($page->getTitle(), $page->getId(), $page->getLatest());
			if ($parser->getTitle()->getArticleID() === $articleId) {
				$success = self::loadFromOutput($output, $set);
			} else {
				/** @var MetaTemplatePage[] $bulk */
				$bulk = $output->getExtensionData(self::KEY_BULK_LOAD);
				$article = $bulk[$articleId];
				if (isset($article->sets[$set])) {
					$set = $article->sets[$set];
				}

				if (!isset($set)) {
					$set = MetaTemplateSql::getInstance()->loadSetFromDb($articleId, $set);
				}
			}
		}

		return true;
	}

	/**
	 * Sorts the results according to user-specified order (if any), then page name, and finally set.
	 *
	 * @param MetaTemplateSetCollection[] $arr The array to sort.
	 * @param array $sortOrder A list of field names to sort by. In the event of duplication, only the first instance
	 *                         counts.
	 *
	 * @return array The sorted array.
	 *
	 */
	private static function listSavedSort(array $arr, array $sortOrder): array
	{
		$used = [];
		$newOrder = [];
		foreach ($sortOrder as $field) {
			if (!in_array($field, $used, true)) {
				$col = [];
				/*
				foreach ($arr->sets as $setName => $set) {
					$col[$key] = $row[$field];
				}
				*/

				$newOrder[] = $col;
				$newOrder[] = SORT_NATURAL;
				$used[] = $field;
			}
		}

		$sortOrder[] = &$arr;
		call_user_func_array('array_multisort', $newOrder);
		return array_pop($sortOrder);
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

		$setName = $set->setName;
		#RHshow('Page Variables: ', $pageVars);
		$pageSet = $pageVars->sets[$set->setName];
		if (!$pageSet) {
			return false;
		}

		$retval = false;
		#RHshow('Page Set: ', $pageSet);
		if ($set->variables) {
			foreach ($set->variables as $varName => &$var) {
				if ($var === false) {
					$copy = $pageSet->variables[$varName];
					$var = new MetaTemplateVariable($copy->value, $copy->parseOnLoad);
					$retval = true;
				}
			}
		}

		return $retval;
	}

	/**
	 * Given a node (or string for $args[0]), determine if it's plain text.
	 *
	 * @param PPNode $node
	 *
	 * @return bool True if the node represents a text-only value.
	 *
	 */
	private static function nodeIsTextOnly($node)
	{
		if (is_string($node)) {
			// This is primitive, but should suffice to check the first parameter of template arguments, which is the
			// only time this should ever be true. The only other alternative is to fully parse it, which is what this
			// template aims to avoid.
			return
				strpos($node, '{{') >= 0 &&
				strpos($node, '[') >= 0;
		}

		if ($node instanceof PPNode_Hash_Text) {
			return true;
		}

		if ($node instanceof PPNode_Hash_Tree) {
			$first = $node->getFirstChild();
			if ($first instanceof PPNode_Hash_Text) {
				return !$first->getNextSibling();
			}
		}

		return false;
	}
}
