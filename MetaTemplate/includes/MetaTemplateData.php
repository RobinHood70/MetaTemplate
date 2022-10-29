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
	private static $saveKey = '|#save';
	private static $setNameWidth = 50;

	public static function doListSaved(Parser $parser, PPFrame $frame, array $args)
	{
		list($magicArgs, $values) = ParserHelper::getInstance()->getInstance()->getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_ORDER
		);

		if (!ParserHelper::getInstance()->checkIfs($frame, $magicArgs) || count($values) < 2) {
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
		if (!($loadTitle && $loadTitle->canExist())) {
			return;
		}

		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists, in
		// case it's created in the future.
		$page = WikiPage::factory($loadTitle);
		self::trackPage($output, $page);
		$anyCase = $helper->checkAnyCase($magicArgs);
		$varNames = [];
		$varList = self::getVars($frame, $values, $anyCase);
		if (count($varList)) {
			foreach ($varList as $varName => $value) {
				if (is_null($value)) {
					$varNames[] = $varName;
				}
			}
		}

		$set = $magicArgs[self::NA_SET] ?? '';
		if (strlen($set) > self::$setNameWidth) {
			// We check first because substr can return false with '', converting the string to a boolean unexpectedly.
			$set = substr($set, 0, self::$setNameWidth);
		}

		$result = self::fetchVariables($page, $output, $set, $varNames);
		if (!$result && $loadTitle->isRedirect()) {
			// If no results were returned and the page is a redirect, see if there's variables there.
			$page = WikiPage::factory($page->getRedirectTarget());
			self::trackPage($output, $page);
			$result = self::fetchVariables($page, $output, $set, $varNames);
		}

		if ($result) {
			$anyCase = $helper->checkAnyCase($magicArgs);
			foreach ($result as $varName => $var) {
				$varValue = MetaTemplate::getVar($frame->parent, $varName, $anyCase, false);
				if ($varValue === false) {
					if ($var->getParsed()) {
						$varValue = $var->getValue();
						// RHshow('Parsed: ', $value);
					} else {
						$prepro = $parser->preprocessToDom($var->getValue());
						$varValue = $frame->expand($prepro);
						// RHshow('Unparsed: ', $value);
					}

					if ($varValue !== false) {
						MetaTemplate::setVar($frame, $varName, $varValue);
					}
				}
			}
		}
	}

	/**
	 * doSave
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 *
	 * @return void
	 */
	public static function doSave(Parser $parser, PPFrame $frame, array $args)
	{
		$title = $parser->getTitle();
		if (!$title->canExist()) {
			return;
		}

		if ($title->getNamespace() === NS_TEMPLATE) {
			// Marker value that the template uses for #save. This causes a data cleanup as part of the save.
			$pageId = $title->getArticleID();
			$sets = new MetaTemplateSetCollection($pageId, -1);
			self::setPageVariables($parser->getOutput(), $sets);
			return;
		}


		list($magicArgs, $values) = ParserHelper::getInstance()->getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET,
			self::NA_SAVEMARKUP
		);

		$page = WikiPage::factory($title);
		if (!ParserHelper::getInstance()->checkIfs($frame, $magicArgs) || count($values) == 0 || $page->getContentModel() !== CONTENT_MODEL_WIKITEXT) {
			return;
		}

		$anyCase = ParserHelper::getInstance()->checkAnyCase($magicArgs);
		$saveMarkup = $magicArgs[self::NA_SAVEMARKUP] ?? false;
		$set = $magicArgs[self::NA_SET] ?? '';
		$variables = [];
		$getVars = self::getVars($frame, $values, $anyCase);
		foreach ($getVars as $varName => $value) {
			if (!is_null($value) && $value !== false) {
				$frame->namedArgs[self::$saveKey] = 'saving'; // This is a total hack to let the tag hook know that we're saving now.
				$value = $frame->expand($value, $saveMarkup ? PPFrame::NO_TEMPLATES : 0);
				// show(htmlspecialchars($value));
				if ($frame->namedArgs[self::$saveKey] != 'saving') {
					$value = $parser->mStripState->unstripGeneral($value);
				}

				$value = $parser->preprocessToDom($value, Parser::PTD_FOR_INCLUSION);
				$value = $frame->expand($value, PPFrame::NO_TEMPLATES | PPFrame::NO_TAGS);
				// show(htmlspecialchars($value));
				$parsed = $saveMarkup ? false : $frame->namedArgs[self::$saveKey] === 'saving';

				// show('Final Output (', $parsed ? 'parsed ' : 'unparsed ', '): ', $set, '->', $varName, '=', htmlspecialchars($value));
				$variables[$varName] = new MetaTemplateVariable($value, $parsed);
				unset($frame->namedArgs[self::$saveKey]);
			}
		}

		self::addVariables($page, $parser->getOutput(), $set, $variables);
	}

	public static function doSaveMarkupTag($value, array $attributes, Parser $parser, PPFrame $frame)
	{
		// We don't care what the value of the argument is here, only that it exists. It could be 'saving', or it oculd be 'unparsed' if multiple tags are used.
		if ($frame->getArgument(self::$saveKey)) {
			$frame->namedArgs[self::$saveKey] = 'unparsed';
			$value = $parser->preprocessToDom($value, Parser::PTD_FOR_INCLUSION);
			$value = $frame->expand($value, PPFrame::NO_TEMPLATES | PPFrame::NO_IGNORE | PPFrame::NO_TAGS);
			return $value;
		}

		// This tag is a marker for the doSave function, so we don't need to do anything beyond normal frame expansion.
		$value = $parser->recursiveTagParse($value, $frame);
		return $value;
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
	 * @param mixed $set
	 *
	 * @return void
	 */
	private static function addVariables(WikiPage $page, ParserOutput $output, string $setName, array $variables)
	{
		// $displayTitle = $page->getTitle()->getFullText();
		// logFunctionText(" ($displayTitle, ParserOutput, $set, Variables)");
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

	private static function	fetchVariables(WikiPage $page, ParserOutput $output, string $setName, array $varNames)
	{
		$pageId = $page->getId();
		$result = self::loadFromOutput($output, $pageId, $setName);
		return $result ? $result : MetaTemplateSql::getInstance()->loadTableVariables($pageId, $setName, $varNames);
	}

	/**
	 * Gets variables from the datab
	 *
	 * @param PPFrame $frame
	 * @param mixed $values
	 * @param mixed $anyCase
	 *
	 * @return array The variable list.
	 *
	 */
	private static function getVars(PPFrame $frame, array $values, bool $anyCase): array
	{
		$retval = [];
		foreach ($values as $varNameNodes) {
			$varName = trim($frame->expand($varNameNodes));
			$varName = substr($varName, 0, self::$saveArgNameWidth);
			$value = MetaTemplate::getVar($frame, $varName, $anyCase, false);
			$retval[$varName] = $value;
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
