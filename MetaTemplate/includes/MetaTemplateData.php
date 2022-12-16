<?php

/**
 * Data functions of MetaTemplate (#listsaved, #load, #save).
 */
class MetaTemplateData
{
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

	public const TG_SAVEMARKUP = 'metatemplate-savemarkuptag';

	public const SAVE_SETNAME_WIDTH = 50;
	public const SAVE_VARNAME_WIDTH = 50;

	public const KEY_SAVE = MetaTemplate::KEY_METATEMPLATE . '#save';

	private const KEY_LISTSAVED_ERROR = MetaTemplate::KEY_METATEMPLATE . '#listSavedErr';
	private const KEY_PARSEONLOAD = MetaTemplate::KEY_METATEMPLATE . '#parseOnLoad';
	private const KEY_PRELOAD = MetaTemplate::KEY_METATEMPLATE . '#preload';

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
	 *
	 * @return array The text of the templates to be called to make the list as well as the appropriate noparse value
	 *               depending whether it was an error message or a successful call.
	 *
	 */
	public static function doListSaved(Parser $parser, PPFrame $frame, array $args): string
	{
		$setup = self::listSavedSetup($parser, $frame, $args);
		if (is_string($setup)) {
			return $setup;
		}

		/**
		 * @var Title $templateTitle
		 * @var array $magicArgs
		 * @var array $named
		 * @var array $unnamed
		 */
		list($templateTitle, $magicArgs, $named, $unnamed) = $setup;
		$articleId = $templateTitle->getArticleID();
		$preload = new MetaTemplateSet($magicArgs[self::NA_SET] ?? '', [self::KEY_PRELOAD]);
		MetaTemplateSql::getInstance()->loadTableVariables($articleId, $preload);
		$var = reset($preload->variables);
		if ($var !== false) {
			// $unnamed goes last in array_merge so specified parameters override #preload defaults.
			$unnamed = array_merge(explode("\n", $var->value), $unnamed);
		}

		$language = $parser->getConverterLanguage();
		$namespace = $magicArgs[self::NA_NAMESPACE] ?? null;
		$namespace = is_null($namespace) ? -1 : $language->getNsIndex($namespace);
		$translations = MetaTemplate::getVariableTranslations($frame, $unnamed, self::SAVE_VARNAME_WIDTH);
		$items = MetaTemplateSql::getInstance()->loadListSavedData($namespace, $named, $translations);

		$orderNames = $magicArgs[self::NA_ORDER] ?? null;
		$orderNames = $orderNames ? explode(',', $orderNames) : [];
		$orderNames[] = 'pagename';
		$orderNames[] = 'set';

		$data = self::listSavedSort($items, $orderNames);
		$templateName = $templateTitle->getNamespace() === NS_TEMPLATE ? $templateTitle->getText() : $templateTitle->getFullText();
		$templates = self::createTemplates($language, $templateName, $data);
		$retval = ParserHelper::formatPFForDebug($templates, $magicArgs[ParserHelper::NA_DEBUG] ?? false);

		$output = $parser->getOutput();
		$output->setExtensionData(self::KEY_LISTSAVED_ERROR, false);

		$dom = $parser->preprocessToDom($retval);
		$retval = $frame->expand($dom);

		if ($output->getExtensionData(self::KEY_LISTSAVED_ERROR) ?? false) {
			$retval = ParserHelper::error('metatemplate-listsaved-template-saveignored', $templateTitle->getFullText()) . $retval;
		}

		$output->setExtensionData(self::KEY_LISTSAVED_ERROR, null);
		return $retval;
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
		// RHshow('#load:', $parser->getTitle()->getFullText());
		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET
		);

		!Hooks::run('MetaTemplateBeforeLoadMain', [$parser, $frame, $magicArgs, $values]);
		self::doLoadMain($parser, $frame, $magicArgs, $values);
	}

	/**
	 * Saves the specified variable names as metadata to be used by #listsaved.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments: None.
	 *
	 * @return void
	 *
	 */
	public static function doPreload(Parser $parser, PPFrame $frame, array $args): void
	{
		if ($frame->depth > 0 || $parser->getOptions()->getIsPreview()) {
			return;
		}

		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			self::NA_SET
		);

		$values = [];
		foreach ($values as $arg) {
			$value = ParserHelper::getKeyValue($frame, $arg)[1];
			if (self::nodeIsTextOnly($value)) {
				$values[] = $frame->expand($value);
			}
		}

		$set = $magicArgs[self::NA_SET] ?? '';
		$value = implode("\n", $values);
		$var = new MetaTemplateVariable($value, false);
		self::addToSet(
			$parser->getTitle(),
			$parser->getOutput(),
			$set,
			[self::KEY_PRELOAD => $var]
		);
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

		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET,
			self::NA_SAVEMARKUP
		);

		if (!ParserHelper::checkIfs($frame, $magicArgs) || count($values) == 0) {
			return;
		}

		$output = $parser->getOutput();
		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$saveMarkup = $magicArgs[self::NA_SAVEMARKUP] ?? false;
		$varsToSave = [];
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		foreach ($translations as $srcName => $destName) {
			$output->setExtensionData(self::KEY_PARSEONLOAD, true);
			$varValue = MetaTemplate::getVar($frame, $srcName, $anyCase);
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

		// Only flag #listsaved error if all checks were passed and this is active code.
		// RHshow('#save: ', $varsToSave, "\n", $output->getExtensionData(self::KEY_LISTSAVED_ERROR) ?? 'null');
		if ($output->getExtensionData(self::KEY_LISTSAVED_ERROR) === false) {
			$output->setExtensionData(self::KEY_LISTSAVED_ERROR, true);
		}

		// RHshow('Vars to Save: ', $varsToSave, "\nSave All Markup: ", $saveMarkup ? 'Enabled' : 'Disabled');
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
	 * Gets the list of variables for #load to load.
	 *
	 * @param PPFrame $frame The frame in use.
	 * @param array $translations The variables to load as a translation matrix.
	 * @param bool $anyCase Whether to load variables case-insensitively.
	 *
	 * @return array The variables to load with the values in the key. This is to allow functions like array_diff_key,
	 *               which is significantly faster than array_diff.
	 *
	 */
	public static function getVarList(PPFrame $frame, array $translations, bool $anyCase): array
	{
		foreach ($translations as $srcName => $destName) {
			$varList[$srcName] = MetaTemplate::getVar($frame, $destName, $anyCase);
		}

		return $varList;
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
		// RHshow('addVars: ', $variables);
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
	 * @param array $params The parameters to pass to each of the template calls.
	 *
	 * @return string The text of the template calls.
	 *
	 */
	private static function createTemplates(Language $language, string $templateName, array $params): string
	{
		$retval = '';
		foreach ($params as $fields) {
			// RHshow($fields);
			$namespace = '';
			$pageName = '';
			$set = '';
			$vars = [];
			foreach ($fields as $key => $value) {
				switch ($key) {
					case 'namespace':
						$namespace = "|$key=" . $language->getNsText($value);
						break;
					case 'pagename':
						$pageName = "|$key=" . strtr($value, '_', ' ');
						break;
					case 'set':
						if ($value !== '') {
							$set = "|$key=$value";
						}

						break;
					default:
						$vars[$key] = $value;
						break;
				}
			}

			ksort($vars, SORT_NATURAL);
			$params = '';
			foreach ($vars as $key => $value) {
				$params .= "|$key=$value";
			}

			$retval .= '{{' . $templateName . $params . $namespace . $pageName . $set . '}}';
		}

		return $retval;
	}

	private static function doLoadMain(Parser $parser, PPFrame $frame, array $magicArgs, array $values)
	{
		$titleText = $values[0];
		unset($values[0]);
		if (!ParserHelper::checkIfs($frame, $magicArgs)) {
			return;
		}

		$titleText = $frame->expand($titleText);
		$loadTitle = Title::newFromText($titleText);
		if (!$loadTitle || !$loadTitle->canExist()) {
			return;
		}

		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists.
		$output = $parser->getOutput();
		$page = WikiPage::factory($loadTitle);
		// RHshow($loadTitle->getFullText(), ' ', $page->getId(), ' ', $page->getLatest());
		$output->addTemplate($loadTitle, $page->getId(), $page->getLatest());

		$set = substr($magicArgs[self::NA_SET] ?? '', 0, self::SAVE_SETNAME_WIDTH);
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::SAVE_VARNAME_WIDTH);
		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$varsToLoad = self::getVarList($frame, $translations, $anyCase);
		$preloaded = $output->getExtensionData(MetaTemplate::KEY_PRELOADED) ?? null;
		if ($preloaded) {
			$varsToLoad = array_diff_key($varsToLoad, $preloaded);
		}

		// If all variables to load are already defined or this is a bulk-load run, skip loading altogether.
		if (!$varsToLoad) {
			return;
		}

		$setToLoad = new MetaTemplateSet($set, $varsToLoad, $anyCase);
		$success = self::getResult($parser, $page, $setToLoad);
		if ($success) {
			self::updateFromSet($setToLoad, $parser, $frame);
		}
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
		$success = $parser->getTitle()->getFullText() === $title->getFullText()
			? self::loadFromOutput($output, $set) // $set == null should never happen, but don't crash if it does
			: MetaTemplateSql::getInstance()->loadTableVariables($articleId, $set);

		// If no results were returned and the page is a redirect, see if there are variables on the target page.
		if (!$success && $title->isRedirect()) {
			$page = WikiPage::factory($page->getRedirectTarget());
			$output->addTemplate($page->getTitle(), $page->getId(), $page->getLatest());
			$success = $parser->getTitle()->getFullText() === $title->getFullText()
				? self::loadFromOutput($output, $set)
				: MetaTemplateSql::getInstance()->loadTableVariables($articleId, $set);
		}

		return $success;
	}

	private static function updateFromSet(MetaTemplateSet $set, Parser $parser, PPFrame $frame)
	{
		foreach ($set->variables as $varName => $var) {
			$varValue = $var->value;
			if ($var->parseOnLoad) {
				$varValue = $parser->preprocessToDom($varValue);
				$varValue = $frame->expand($varValue);
				// RHshow('Parse on load: ', $value);
			}

			if ($varValue !== false) {
				MetaTemplate::setVar($frame, $varName, $varValue, $set->anyCase);
			}
		}
	}

	/**
	 * Handles all of the setup and data validation for doListSaved().
	 *
	 * @param PPFrame $frame THe frame in use.
	 * @param array $args Function arguments (see doListSaved for details).
	 *
	 * @return array An array of values to pass back to doListSaved: the template's WikiPage, magicArgs, query
	 *               parameters, and the list of variables to include in the results.
	 *
	 */
	private static function listSavedSetup(Parser $parser, PPFrame $frame, array $args)
	{
		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			ParserHelper::NA_DEBUG,
			self::NA_NAMESPACE,
			self::NA_ORDER,
			self::NA_SET
		);

		if (!ParserHelper::checkIfs($frame, $magicArgs)) {
			return '';
		}

		if (!isset($values[0])) { // Should be impossible, but better safe than crashy.
			return ParserHelper::error('metatemplate-listsaved-template-empty');
		}

		$template = trim($frame->expand($values[0]));
		if (!strlen($template)) {
			return ParserHelper::error('metatemplate-listsaved-template-empty');
		}

		unset($values[0]);

		/**
		 * @var array $named
		 * @var array $unnamed */
		list($named, $unnamed) = ParserHelper::splitNamedArgs($frame, $values);
		if (!count($named)) {
			return ParserHelper::error('metatemplate-listsaved-conditions-missing');
		}

		foreach ($named as $key => &$value) {
			$value = $frame->expand($value);
		}

		foreach ($unnamed as &$value) {
			$value = $frame->expand($value);
		}

		$templateTitle = Title::newFromText($template, NS_TEMPLATE);
		if (!$templateTitle) {
			return ParserHelper::error('metatemplate-listsaved-template-missing', $template);
		}

		$page = WikiPage::factory($templateTitle);
		if (!$page) {
			return ParserHelper::error('metatemplate-listsaved-template-missing', $template);
		}

		// Track the page here rather than letting the output do it, since the template should be tracked even if it
		// doesn't exist.
		$parser->getOutput()->addTemplate($templateTitle, $page->getId(), $page->getLatest());
		if (!$page->exists()) {
			return ParserHelper::error('metatemplate-listsaved-template-missing', $template);
		}

		$maxLen = MetaTemplate::getConfig()->get('ListsavedMaxTemplateSize');
		$size = $templateTitle->getLength();
		if ($maxLen > 0 && $size > $maxLen) {
			return ParserHelper::error('metatemplate-listsaved-template-toolong', $template, $maxLen);
		}

		return [$templateTitle, $magicArgs, $named, $unnamed];
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
	private static function listSavedSort(array $arr, array $sortOrder): array
	{
		$used = [];
		$newOrder = [];
		foreach ($sortOrder as $field) {
			if (!in_array($field, $used, true)) {
				$col = [];
				foreach ($arr as $key => $row) {
					$col[$key] = $row[$field];
				}

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
	 * @param string $setName The set to load.
	 * @param array $varNames The variables to load.
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

		// RHshow('Page Variables: ', $pageVars);
		$pageSet = $pageVars->sets[$set->setName];
		if (!$pageSet) {
			return false;
		}

		$retval = false;
		// RHshow('Page Set: ', $pageSet);
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
