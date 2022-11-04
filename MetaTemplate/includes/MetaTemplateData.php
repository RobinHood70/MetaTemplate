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

	private const SAVE_MARKUP_FLAGS = PPFrame::NO_TEMPLATES | PPFrame::NO_IGNORE;

	private static $saveArgNameWidth = 50;
	private static $saveKey = 'mt#Save';
	private static $saveParseOnLoad = 'mt#ParseOnLoad';
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
			if (MetaTemplate::getVar($frame, $destName, $anyCase, false) === false) {
				$varsToLoad[] = $srcName;
			}
		}

		// If all variables to load are already defined, skip loading altogether.
		if (!$varsToLoad) {
			return;
		}

		// RHshow('Vars to load: ', $varsToLoad);
		$set = substr($magicArgs[self::NA_SET] ?? '', 0, self::$setNameWidth);
		$articleId = $loadTitle->getArticleID();
		// Compare on title since an empty page won't have an ID.
		$result = $parser->getTitle()->getFullText() === $loadTitle->getFullText()
			? self::loadFromOutput($output, $set, $varsToLoad)
			: self::loadFromDatabase($articleId, $set, $varsToLoad);
		// RHshow('Result Main: ', $result);

		// If no results were returned and the page is a redirect, see if there are variables there.
		if (!$result && $loadTitle->isRedirect()) {
			$page = WikiPage::factory($page->getRedirectTarget());
			self::trackPage($output, $page);
			$result = $parser->getTitle()->getFullText() === $loadTitle->getFullText()
				? self::loadFromOutput($output, $set, $varsToLoad)
				: self::loadFromDatabase($articleId, $set, $varsToLoad);
			// RHshow('Result Redirect: ', $result);
		}

		if ($result) {
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
			!$title->canExist() ||
			$title->getContentModel() !== CONTENT_MODEL_WIKITEXT
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
		$translations = MetaTemplate::getVariableTranslations($frame, $values, self::$saveArgNameWidth);

		$varsToSave = [];
		$output = $parser->getOutput();
		foreach ($translations as $srcName => $destName) {
			$output->setExtensionData(self::$saveParseOnLoad, true);
			$varValue = MetaTemplate::getVar($frame, $srcName, $anyCase, false);
			if ($varValue === false) {
				continue;
			}

			$varValue = $frame->expand($varValue, $saveMarkup ? self::SAVE_MARKUP_FLAGS : 0);
			if (!$output->getExtensionData(self::$saveParseOnLoad)) {
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
				$first = $parseCheck->getFirstChild();
				$parseOnLoad = $first instanceof PPNode_Hash_Text
					? $first->getNextSibling() !== false
					: false;
			}

			$varsToSave[$destName] = new MetaTemplateVariable($varValue, $parseOnLoad);
		}

		// RHshow('Vars to Save: ', $varsToSave, "\nSave All Markup: ", $saveMarkup ? 'Enabled' : 'Disabled');
		$output->setExtensionData(self::$saveParseOnLoad, false); // Probably not necessary, but just in case...
		self::addPageVariables(WikiPage::factory($title), $output, $magicArgs[self::NA_SET] ?? '', $varsToSave);
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
	public static function doSaveMarkupTag($value, array $attributes, Parser $parser, PPFrame $frame): string
	{
		if ($parser->getOutput()->getExtensionData(self::$saveParseOnLoad)) {
			$parser->getOutput()->setExtensionData(self::$saveParseOnLoad, false);
			$value = $parser->preprocessToDom($value, Parser::PTD_FOR_INCLUSION);
			$value = $frame->expand($value, self::SAVE_MARKUP_FLAGS);

			return $value;
		}

		return $parser->recursiveTagParse($value, $frame);
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
		$output->setExtensionData(self::$saveKey, $value);
	}

	/**
	 * Adds the provided list of variables to the set provided and to the parser output.
	 *
	 * @param WikiPage $page The page the variables should be added to.
	 * @param ParserOutput $output The current parser's output.
	 * @param array $variables
	 * @param string $set
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
	 * Retrieves the requested set of variables from the database.
	 *
	 * @param int $pageId The current page ID.
	 * @param string $setName The set to load.
	 * @param array $varNames
	 *
	 * @return array|null
	 *
	 */
	private static function	loadFromDatabase(int $pageId, string $setName, array $varNames): ?array
	{
		if ($pageId > 0) {
			return MetaTemplateSql::getInstance()->loadTableVariables($pageId, $setName, $varNames);
		}
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

		// RHshow('Output Variables: ', $vars);
		return $vars;
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
