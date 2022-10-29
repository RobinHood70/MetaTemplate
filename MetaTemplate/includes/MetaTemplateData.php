<?php

/**
 * Data functions of MetaTemplate (#listsaved, #load, #save).
 */
class MetaTemplateData
{
	const NA_ORDER = 'metatemplate-order';
	const NA_SAVEMARKUP = 'metatemplate-savemarkup';
	const NA_SET = 'metatemplate-set';

	const PF_LISTSAVED = 'metatemplate-listsaved';
	const PF_LOAD = 'metatemplate-load';
	const PF_SAVE = 'metatemplate-save';

	private static $saveArgNameWidth = 50;
	private static $saveKey = 'mt#Save';
	private static $saveParseOnLoad = 'mt#ParseOnLoad';
	private static $saveMarkupFlags = PPFrame::NO_TEMPLATES | PPFrame::NO_IGNORE | PPFrame::NO_TAGS;
	private static $setNameWidth = 50;

	/**
	 * Queries the database based on the conditions provided and creates a list of templates, one for each row in the
	 * results.
	 *
	 * @param Parser $parser The parser in use.
	 * @param PPFrame $frame The frame in use.
	 * @param array $args Function arguments:
	 *
	 * @return [type]
	 *
	 */
	public static function doListSaved(Parser $parser, PPFrame $frame, array $args)
	{
		$helper = ParserHelper::getInstance();
		list($magicArgs, $values) = $helper->getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_ORDER
		);

		if (!$helper->checkIfs($frame, $magicArgs) || count($values) < 2) {
			return;
		}

		// TODO: Incomplete! Left off here.
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
		// TODO: Rewrite to be more modular. Incorporate '=>' code from doInherit.

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

		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists, in
		// case it's created in the future.
		$page = WikiPage::factory($loadTitle);
		self::trackPage($output, $page);
		$anyCase = $helper->checkAnyCase($magicArgs);
		$varNames = self::getVars($frame, $values, $anyCase, false);

		// If all variables to load are already defined, skip loading altogether.
		if (!count($varNames)) {
			return;
		}

		$set = $magicArgs[self::NA_SET] ?? '';
		if (strlen($set) > self::$setNameWidth) {
			// We check first because substr can return false with '', converting the string to a boolean unexpectedly.
			$set = substr($set, 0, self::$setNameWidth);
		}

		$articleId = $loadTitle->getArticleID();
		if ($parser->getTitle()->getFullText() == $loadTitle->getFullText()) {
			$result = self::loadFromOutput($output, $articleId, $set);
		} else {
			$result = self::fetchVariables($articleId, $set, $varNames);
		}

		if (!$result && $loadTitle->isRedirect()) {
			// If no results were returned and the page is a redirect, see if there's variables there.
			$page = WikiPage::factory($page->getRedirectTarget());
			self::trackPage($output, $page);
			if ($parser->getTitle()->getFullText() == $page->getTitle()->getFullText()) {
				$result = self::loadFromOutput($output, $articleId, $set);
			} else {
				$result = self::fetchVariables($page->getId(), $set, $varNames);
			}
		}

		if ($result) {
			$anyCase = $helper->checkAnyCase($magicArgs);
			foreach ($result as $varName => $var) {
				$varValue = MetaTemplate::getVar($frame, $varName, $anyCase, false);
				if ($varValue === false) {
					$varValue = $var->getValue();
					// RHshow('Parse on load: ', $value);
					if (!$var->getParseOnLoad()) {
						$prepro = $parser->preprocessToDom($varValue);
						$varValue = $frame->expand($prepro);
						// RHshow('Parse on load: ', $value);
					}

					if ($varValue !== false) {
						MetaTemplate::setVar($frame, $varName, $varValue);
					}
				}
			}
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
	public static function doSave(Parser $parser, PPFrame $frame, array $args): void
	{
		$title = $parser->getTitle();
		if (
			!$title->canExist() ||
			$parser->getOptions()->getIsPreview() ||
			$title->getContentModel() !== CONTENT_MODEL_WIKITEXT
		) {
			return;
		}

		$output = $parser->getOutput();
		if ($title->getNamespace() === NS_TEMPLATE) {
			// Marker value that the template uses #save.
			$pageId = $title->getArticleID();
			$sets = new MetaTemplateSetCollection($pageId, -1);
			self::setPageVariables($output, $sets);
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

		$page = WikiPage::factory($title);
		if (!$helper->checkIfs($frame, $magicArgs) || count($values) == 0) {
			return;
		}

		$anyCase = $helper->checkAnyCase($magicArgs);
		$saveMarkup = $magicArgs[self::NA_SAVEMARKUP] ?? false;
		$variables = [];
		$vars = self::getVars($frame, $values, $anyCase, true);
		// RHshow('Vars to Save: ', array_keys($vars), "\nSave All Markup: ", $saveMarkup ? 'Enabled' : 'Disabled');

		/** @var PP_Node $value */
		foreach ($vars as $varName => $value) {
			$output->setExtensionData(self::$saveParseOnLoad, null);
			$value = $frame->expand($value, $saveMarkup ? self::$saveMarkupFlags : 0); // Was templates only; changed to standard flags
			if ($output->getExtensionData(self::$saveParseOnLoad)) {
				// The value of saveParseOnLoad changed, meaning that there are <savemarkup> tags present.
				$parseOnLoad = true;
				$value = $helper->getStripState($parser)->unstripGeneral($value);
			} else {
				$parseOnLoad = $saveMarkup;
			}

			// Double-check whether the value actually needs to be parsed. If the value is a single text node with no
			// siblings (i.e., plain text), it needs no further parsing. For anything else, parse it at the #load end.
			if ($parseOnLoad) {
				$parseCheck = $parser->preprocessToDom($value);
				$first = $parseCheck->getFirstChild();
				$parseOnLoad = $first instanceof PPNode_Hash_Text
					? $first->getNextSibling() !== false
					: false;
			}

			// RHshow('Final Output (', $parseOnLoad ? 'parse on load' : 'don't parse', '): ', $set, '->', $varName, '=', $value);
			$variables[$varName] = new MetaTemplateVariable($value, $parseOnLoad);
			$output->setExtensionData(self::$saveParseOnLoad, null);
		}

		self::addVariables($page, $output, $magicArgs[self::NA_SET] ?? '', $variables);
	}

	public static function doSaveMarkupTag($value, array $attributes, Parser $parser, PPFrame $frame)
	{
		if (!$parser->getOutput()->getExtensionData(self::$saveParseOnLoad)) {
			$parser->getOutput()->setExtensionData(self::$saveParseOnLoad, true);
			$value = $parser->preprocessToDom($value, Parser::PTD_FOR_INCLUSION);
			$value = $frame->expand($value, self::$saveMarkupFlags);
			return $value;
		}

		return $parser->recursiveTagParse($value, $frame);
	}

	/**
	 * getPageVariables
	 *
	 * @param ParserOutput $output
	 *
	 * @return MetaTemplateSetCollection|null
	 */
	public static function getPageVariables(ParserOutput $output)
	{
		return $output->getExtensionData(self::$saveKey);
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
			self::NA_SAVEMARKUP,
			self::NA_SET,
		]);
	}

	public static function setPageVariables(ParserOutput $output, ?MetaTemplateSetCollection $value = null)
	{
		$output->setExtensionData(self::$saveKey, $value);
	}

	/**
	 * add
	 *
	 * @param WikiPage $page
	 * @param ParserOutput $output
	 * @param array $variables
	 * @param string $set
	 *
	 * @return void
	 */
	private static function addVariables(WikiPage $page, ParserOutput $output, string $setName, array $variables): void
	{
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

	private static function	fetchVariables(int $pageId, string $setName, array $varNames): ?array
	{
		if ($pageId > 0) {
			return MetaTemplateSql::getInstance()->loadTableVariables($pageId, $setName, $varNames);
		}
	}

	/**
	 * Gets the variables
	 *
	 * @param PPFrame $frame The frame in use.
	 * @param array $values The list of variables to retrieve.
	 * @param bool $anyCase Whether the key match should be case insensitive.
	 * @param bool $exists Whether to extract only variables that do exit (#save) or only those that don't (#load).
	 *
	 * @return array The variable list.
	 *
	 */
	private static function getVars(PPFrame $frame, array $values, bool $anyCase, bool $exists): array
	{
		$retval = [];
		foreach ($values as $varNameNodes) {
			$varName = trim($frame->expand($varNameNodes));
			$varName = substr($varName, 0, self::$saveArgNameWidth);
			$value = MetaTemplate::getVar($frame, $varName, $anyCase, false);
			if (!$exists && $value === false) {
				// ), since $value can never be true.
				$retval[] = $varName;
			} elseif ($exists && $value !== false) {
				$retval[$varName] = $value;
			}
		}

		return $retval;
	}

	/**
	 * Retrieves the current set of variables already on the page as though they had been loaded from the database.
	 *
	 * @param mixed $pageId The current page ID. This should never be anything but.
	 * @param string $set The set to load.
	 *
	 * @return ?MetaTemplateVariable[]
	 */
	private static function loadFromOutput(ParserOutput $output, $pageId, $set = ''): ?array
	{
		$vars = self::getPageVariables($output);
		if (!$vars) {
			$vars = new MetaTemplateSetCollection($pageId, 0);
		}

		$set = $vars->getSet($set);
		return $set ? $set->getVariables() : null;
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
