<?php

/**
 * Data functions of MetaTemplate (#listsaved, #load, #save).
 */
class MetaTemplateData
{
	const NA_NAMESPACE = 'metatemplate-namespace';
	const NA_ORDER = 'metatemplate-order';
	const NA_PAGELENGTH = 'metatemplate-pagelength';
	const NA_PAGENAME = 'metatemplate-pagename';
	const NA_SAVEMARKUP = 'metatemplate-savemarkupattr';
	const NA_SET = 'metatemplate-set';

	const PF_LISTSAVED = 'metatemplate-listsaved';
	const PF_LOAD = 'metatemplate-load';
	const PF_PRELOAD = 'metatemplate-preload';
	const PF_SAVE = 'metatemplate-save';

	const TG_SAVEMARKUP = 'metatemplate-savemarkuptag';

	private const KEY_PARSEONLOAD = MetaTemplate::KEY_METATEMPLATE . '#parseOnLoad';
	private const KEY_PRELOAD = '#preload'; // Used under @MetaTemplate set so no need to add that here.
	private const KEY_SAVE = MetaTemplate::KEY_METATEMPLATE . '#save';

	private const SAVE_MARKUP_FLAGS = PPFrame::NO_TEMPLATES | PPFrame::NO_IGNORE;

	private static $saveArgNameWidth = 50;
	private static $setNameWidth = 50;

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
	public static function doListSaved(Parser $parser, PPFrame $frame, array $args): array
	{
		$helper = ParserHelper::getInstance();
		$setup = self::listSavedSetup($parser, $frame, $args);
		if (is_string($setup)) {
			return ['text' => $setup, 'noparse' => true];
		}

		/**
		 * @var Title $templateTitle
		 * @var array $magicArgs
		 * @var array $named
		 * @var array $unnamed
		 */
		list($templateTitle, $magicArgs, $named, $unnamed) = $setup;
		$articleId = $templateTitle->getArticleID();
		$set = MetaTemplate::KEY_METATEMPLATE;
		$preload = self::loadFromDatabase($articleId, $set, [self::KEY_PRELOAD]);
		if ($preload && count($preload) === 1) {
			$var = $preload[self::KEY_PRELOAD] ?? false;
			if ($var) {
				// $unnamed goes last in array_merge so specified parameters override #preload defaults.
				$unnamed = array_merge(explode("\n", $var->getValue()), $unnamed);
			}
		}

		$language = $parser->getConverterLanguage();
		$namespace = $magicArgs[self::NA_NAMESPACE] ?? null;
		$namespace = is_null($namespace) ? -1 : $language->getNsIndex($namespace);

		$translations = MetaTemplate::getVariableTranslations($frame, $unnamed, self::$saveArgNameWidth);
		$items = MetaTemplateSql::getInstance()->loadListSavedData($namespace, $named, $translations);

		$orderNames = $magicArgs[self::NA_ORDER] ?? null;
		$orderNames = $orderNames ? explode(',', $orderNames) : [];
		$orderNames[] = 'pagename';
		$orderNames[] = 'set';

		$data = self::listSavedSort($items, $orderNames);
		$templateName = $templateTitle->getNamespace() === NS_TEMPLATE ? $templateTitle->getText() : $templateTitle->getFullText();
		$templates = self::createTemplates($language, $templateName, $data);
		$retval = $helper->formatPFForDebug($templates, $magicArgs[ParserHelper::NA_DEBUG] ?? false);

		return ['text' => $retval, 'noparse' => false];
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
		// TODO: Rewrite to be more modular.

		// RHshow('#load:', $parser->getTitle()->getFullText());
		$helper = ParserHelper::getInstance();
		list($magicArgs, $values) = $helper->getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET
		);

		if (!$helper->checkIfs($frame, $magicArgs)) {
			return;
		}

		$output = $parser->getOutput();
		$loadTitle = Title::newFromText($frame->expand(array_shift($values)));
		if (!$loadTitle || !$loadTitle->canExist()) {
			return;
		}

		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists.
		$page = WikiPage::factory($loadTitle);
		self::trackPage($output, $page);

		$anyCase = $helper->checkAnyCase($magicArgs);
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::$saveArgNameWidth);
		$varsToLoad = [];
		foreach ($translations as $srcName => $destName) {
			if (MetaTemplate::getVar($frame, $destName, $anyCase) === false) {
				$varsToLoad[] = $srcName;
			}
		}

		// If all variables to load are already defined, skip loading altogether.
		if (!$varsToLoad) {
			return;
		}

		// RHshow('Vars to load: ', $varsToLoad);
		// Use existing set if specified; if not, check if using <catpagetemplate> or empty set. It's not ideal to have
		// catpagetemplate code baked in here, but any other way I could think of, like hooks, generated a lot of
		// overhead for what is likely to be a fairly rare scenario.
		$set = $magicArgs[self::NA_SET]
			?? $output->getExtensionData(MetaTemplate::KEY_WILDCARD_SET)
			? '*'
			: '';
		$set = substr($set, 0, self::$setNameWidth);
		$articleId = $loadTitle->getArticleID();
		// Compare on title since an empty page won't have an ID.
		$result = $parser->getTitle()->getFullText() === $loadTitle->getFullText()
			? self::loadFromOutput($output, $set, $varsToLoad)
			: self::loadFromDatabase($articleId, $set, $varsToLoad);

		// If no results were returned and the page is a redirect, see if there are variables there.
		if (!$result && $loadTitle->isRedirect()) {
			$page = WikiPage::factory($page->getRedirectTarget());
			self::trackPage($output, $page);
			$result = $parser->getTitle()->getFullText() === $loadTitle->getFullText()
				? self::loadFromOutput($output, $set, $varsToLoad)
				: self::loadFromDatabase($articleId, $set, $varsToLoad);
		}

		if ($result) {
			if ($set === '*') {
				$output->setExtensionData(MetaTemplate::KEY_WILDCARD_SET, $result);
				return;
			}

			$output->setExtensionData(MetaTemplate::KEY_WILDCARD_SET, null);
			foreach ($result as $varName => $var) {
				$varValue = $var->getValue();
				if ($var->getParseOnLoad()) {
					$varValue = $parser->preprocessToDom($varValue);
					$varValue = $frame->expand($varValue);
					// RHshow('Parse on load: ', $value);
				}

				if ($varValue !== false) {
					MetaTemplate::setVar($frame, $translations[$varName], $varValue, $anyCase);
				}
			}
		}
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

		$helper = ParserHelper::getInstance();
		$values = [];
		foreach ($args as $arg) {
			$value = $helper->getKeyValue($frame, $arg)[1];
			if (self::nodeIsTextOnly($value)) {
				$values[] = $frame->expand($value);
			}
		}

		sort($values);
		$value = implode("\n", $values);
		$var = new MetaTemplateVariable($value, false);
		self::addPageVariables(
			WikiPage::factory($parser->getTitle()),
			$parser->getOutput(),
			MetaTemplate::KEY_METATEMPLATE,
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

		$helper = ParserHelper::getInstance();
		list($magicArgs, $values) = $helper->getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET,
			self::NA_SAVEMARKUP
		);

		if (!$helper->checkIfs($frame, $magicArgs) || count($values) == 0) {
			return;
		}

		$anyCase = $helper->checkAnyCase($magicArgs);
		$saveMarkup = $magicArgs[self::NA_SAVEMARKUP] ?? false;

		$varsToSave = [];
		$output = $parser->getOutput();
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::$saveArgNameWidth);
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
				$varValue = $helper->getStripState($parser)->unstripGeneral($varValue);
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

		// RHshow('Vars to Save: ', $varsToSave, "\nSave All Markup: ", $saveMarkup ? 'Enabled' : 'Disabled');
		$output->setExtensionData(self::KEY_PARSEONLOAD, false); // Probably not necessary, but just in case...
		$set = substr($magicArgs[self::NA_SET] ?? '', 0, self::$setNameWidth);
		self::addPageVariables(WikiPage::factory($title), $output, $set, $varsToSave);
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
	 * Gets the accumulated variables that have been #saved throughout the entire page.
	 *
	 * @param ParserOutput $output The current parser's output object.
	 *
	 * @return ?MetaTemplateSetCollection
	 *
	 */
	public static function getPageVariables(ParserOutput $output): ?MetaTemplateSetCollection
	{
		return $output->getExtensionData(self::KEY_SAVE);
	}

	/**
	 * Initializes magic words.
	 *
	 * @return void
	 *
	 */
	public static function init(): void
	{
		ParserHelper::getInstance()->cacheMagicWords([
			self::NA_NAMESPACE,
			self::NA_ORDER,
			self::NA_PAGENAME,
			self::NA_SAVEMARKUP,
			self::NA_SET,
		]);
	}

	/**
	 * Sets the variables in the collection for the current page.
	 *
	 * @param ParserOutput $output The current parser's output object.
	 * @param ?MetaTemplateSetCollection $value
	 *
	 * @return void
	 *
	 */
	public static function setPageVariables(ParserOutput $output, ?MetaTemplateSetCollection $value = null): void
	{
		$output->setExtensionData(self::KEY_SAVE, $value);
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
	private static function addPageVariables(WikiPage $page, ParserOutput $output, string $setName, array $variables): void
	{
		// RHshow('addVars: ', $variables);
		if (!count($variables)) {
			return;
		}

		$pageId = $page->getId();
		$revId = $page->getLatest();
		$pageVars = self::getPageVariables($output);
		if (!$pageVars) {
			$pageVars = new MetaTemplateSetCollection($pageId, $revId);
			self::setPageVariables($output, $pageVars);
		}

		$set = $pageVars->getOrCreateSet(0, $setName);
		$set->addVariables($variables);
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
		$helper = ParserHelper::getInstance();
		list($magicArgs, $values) = ParserHelper::getInstance()->getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			ParserHelper::NA_DEBUG,
			self::NA_NAMESPACE,
			self::NA_ORDER
		);

		if (!$helper->checkIfs($frame, $magicArgs)) {
			return '';
		}

		$template = array_shift($values);
		if (is_null($template)) { // Should be impossible, but better safe than crashy.
			return $helper->error('metatemplate-listsaved-template-empty');
		}

		$template = trim($frame->expand($template));
		if (!strlen($template)) {
			return $helper->error('metatemplate-listsaved-template-empty');
		}

		/**
		 * @var array $named
		 * @var array $unnamed */
		list($named, $unnamed) = $helper->splitNamedArgs($frame, $values);
		if (!count($named)) {
			return $helper->error('metatemplate-listsaved-conditions-missing');
		}

		foreach ($named as $key => &$value) {
			$value = $frame->expand($value);
		}

		foreach ($unnamed as &$value) {
			$value = $frame->expand($value);
		}

		$templateTitle = Title::newFromText($template, NS_TEMPLATE);
		if (!$templateTitle) {
			return $helper->error('metatemplate-listsaved-template-missing', $template);
		}

		$page = WikiPage::factory($templateTitle);
		if (!$page) {
			return $helper->error('metatemplate-listsaved-template-missing', $template);
		}

		// Track the page here rather than letting the output do it, since the template should be tracked even if it
		// doesn't exist.
		self::trackPage($parser->getOutput(), $page);
		if (!$page->exists()) {
			return $helper->error('metatemplate-listsaved-template-missing', $template);
		}

		$maxLen = MetaTemplate::getConfig()->get('ListsavedMaxTemplateSize');
		$text = $page->getContent()->getNativeData();
		if ($maxLen > 0) {
			if (!strlen($text)) {
				return '';
			} elseif (strlen($text) > $maxLen) {
				return $helper->error('metatemplate-listsaved-template-toolong', $template, $maxLen);
			}
		}

		$disallowed = explode("\n", wfMessage('metatemplate-listsaved-template-disallowed')->text());
		foreach ($disallowed as $badWord) {
			if (strlen($badWord) && strpos($text, $badWord))
				return $helper->error('metatemplate-listsaved-template-disallowedmessage', $template, $badWord);
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
	 * Retrieves the requested set of variables from the database.
	 *
	 * @param int $pageId The current page ID.
	 * @param string $setName The set to load.
	 * @param array $varNames The variables to load.
	 *
	 * @return ?MetaTemplateVariable[]
	 *
	 */
	private static function	loadFromDatabase(int $pageId, string $setName, array $varNames): ?array
	{
		return $pageId > 0
			? MetaTemplateSql::getInstance()->loadTableVariables($pageId, $setName, $varNames)
			: null;
	}

	/**
	 * Retrieves the requested set of variables already on the page as though they had been loaded from the database.
	 *
	 * @param ParserOutput $output The current ParserOutput object.
	 * @param string $setName The set to load.
	 * @param array $varNames The variables to load.
	 *
	 * @return ?MetaTemplateVariable[]
	 *
	 */
	private static function loadFromOutput(ParserOutput $output, string $setName, array $varNames): ?array
	{
		$pageVars = self::getPageVariables($output);
		if (!$pageVars) {
			return null; // $pageVars = new MetaTemplateSetCollection($pageId, 0);
		}

		// RHshow('Page Variables: ', $pageVars);
		$set = $pageVars->getSet($setName);
		if (!$set) {
			return null;
		}

		// RHshow('Set: ', $set);
		$vars = $set->getVariables();
		$retval = [];
		if ($vars) {
			foreach ($vars as $var) {
				$retval[] = $var->getValue();
			}
		}

		return $vars;
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

	/**
	 * Adds the page to What Links Here as a transclusion.
	 *
	 * @param ParserOutput $output The current parer's output object.
	 * @param WikiPage $page The page to track.
	 *
	 * @return void
	 *
	 */
	private static function trackPage(ParserOutput $output, WikiPage $page): void
	{
		$output->addTemplate($page->getTitle(), $page->getId(), $page->getLatest());
	}
}
