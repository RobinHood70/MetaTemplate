<?php

/**
 * [Description MetaTemplateData]
 */
class MetaTemplateData
{
	const NA_SAVEMARKUP = 'metatemplate-savemarkup';
	const NA_SET = 'metatemplate-set';

	const PF_LISTSAVED = 'metatemplate-listsaved';
	const PF_LOAD = 'metatemplate-load';
	const PF_SAVE = 'metatemplate-save';

	private static $saveArgNameWidth = 50;
	private static $saveKey = '|#save';
	private static $setNameWidth = 50;

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

	public static function setPageVariables(ParserOutput $output, MetaTemplateSetCollection $value = null)
	{
		$output->setExtensionData(self::$saveKey, $value);
	}

	/**
	 * add
	 *
	 * @param WikiPage $page
	 * @param ParserOutput $output
	 * @param array $variables
	 * @param mixed $setName
	 *
	 * @return void
	 */
	private static function addVariables(WikiPage $page, ParserOutput $output, $setName, array $variables)
	{
		// $displayTitle = $page->getTitle()->getFullText();
		// logFunctionText(" ($displayTitle, ParserOutput, $setName, Variables)");
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

	// IMP: Respects case=any when determining what to load.
	// IMP: No longer auto-inherits and uses set. Functionality is now at user's discretion via traditional methods or inheritance.
	/**
	 * doLoad
	 *
	 * @param Parser $parser
	 * @param PPFrame_Hash $frame
	 * @param array $args
	 *
	 * @return void
	 */
	public static function doLoad(Parser $parser, PPFrame_Hash $frame, array $args)
	{
		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET
		);

		if (!ParserHelper::checkIfs($magicArgs) || count($values) < 2) {
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
		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$varNames = [];
		$varList = self::getVarNames($frame, $values, $anyCase);
		foreach ($varList as $varName => $value) {
			if (is_null($value)) {
				$varNames[] = $varName;
			}
		}

		// If there are no variables to get, abort.
		if (!count($varNames)) {
			return;
		}

		$setName = ParserHelper::arrayGet($magicArgs, self::NA_SET, '');
		if (strlen($setName) > self::$setNameWidth) {
			// We check first because substr can return false with '', converting the string to a boolean unexpectedly.
			$setName = substr($setName, 0, self::$setNameWidth);
		}

		$result = self::fetchVariables($page, $output, $parser->getRevisionId(), $setName, $varNames);
		if (is_null($result) && $loadTitle->isRedirect()) {
			// If no results were returned and the page is a redirect, see if there's variables there.
			$page = WikiPage::factory($page->getRedirectTarget());
			self::trackPage($output, $page);
		}

		if ($result) {
			foreach ($varNames as $varName) {
				if (isset($result[$varName])) {
					$var = $result[$varName];
					if ($var->getParsed()) {
						$value = $var->getValue();
					} else {
						$prepro = $parser->preprocessToDom($var->getValue());
						$value = $frame->expand($prepro);
					}

					MetaTemplate::setVar($frame, $varName, $value);
				}
			}
		}
	}

	// IMP: No longer auto-inherits set variable. Subset changed to set (subset still supported for bc).
	/**
	 * doSave
	 *
	 * @param Parser $parser
	 * @param PPFrame_Hash $frame
	 * @param array $args
	 *
	 * @return void
	 */
	public static function doSave(Parser $parser, PPFrame_Hash $frame, array $args)
	{
		$title = $parser->getTitle();
		if (!$title->canExist()) {
			return;
		}

		if ($title->getNamespace() === NS_TEMPLATE) {
			// Marker value that the template uses #save. This causes a data cleanup as part of the save.
			$pageId = $title->getArticleID();
			$sets = new MetaTemplateSetCollection($pageId, -1);
			self::setPageVariables($parser->getOutput(), $sets);
			return;
		}


		// process before deciding whether to truly proceed, so that nowiki tags are previewed properly
		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SET,
			self::NA_SAVEMARKUP
		);

		$page = WikiPage::factory($title);
		if (!ParserHelper::checkIfs($magicArgs) || count($values) == 0 || $page->getContentModel() !== CONTENT_MODEL_WIKITEXT) {
			return;
		}

		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$saveMarkup = ParserHelper::arrayGet($magicArgs, self::NA_SAVEMARKUP, false);
		$frameFlags = $saveMarkup ? PPFrame::NO_TEMPLATES : 0;
		$set = ParserHelper::arrayGet($magicArgs, self::NA_SET, '');
		$variables = [];
		foreach (self::getVarNames($frame, $values, $anyCase) as $varName => $value) {
			if (!is_null($value)) {
				$frame->namedArgs[self::$saveKey] = 'saving'; // This is a total hack to let the tag hook know that we're saving now.
				$value = $frame->expand($value, $frameFlags);
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

	private static function	fetchVariables(WikiPage $page, ParserOutput $output, $revId, $setName, array $varNames)
	{
		// logFunctionText(' ' . $page->getTitle()->getFullText());
		$pageId = $page->getId();
		if (!$revId) {
			$revId = $page->getLatest();
		}

		$result = self::loadFromOutput($output, $pageId, $setName);
		if (!$result) {
			$result = MetaTemplateSql::getInstance()->loadTableVariables($pageId, $revId, $setName, $varNames);
		}

		return $result;
	}

	private static function getVarNames(PPFrame $frame, $values, $anyCase)
	{
		$retval = [];
		foreach ($values as $varNameNodes) {
			$varName = $frame->expand($varNameNodes);
			$varName = substr($varName, 0, self::$saveArgNameWidth);
			$value = MetaTemplate::getVar($frame, $varName, $anyCase);
			$retval[$varName] = $value;
		}

		return $retval;
	}

	/**
	 * loadFromOutput
	 *
	 * @param mixed $pageId
	 * @param string $setName
	 *
	 * @return MetaTemplateVariable[]|false
	 */
	private static function loadFromOutput(ParserOutput $output, $pageId, $setName = '')
	{
		$vars = self::getPageVariables($output);
		if (!$vars) {
			$vars = new MetaTemplateSetCollection($pageId, 0);
		}

		$set = $vars->getSet($setName);
		return $set ? $set->getVariables() : false;
	}

	private static function trackPage(ParserOutput $output, WikiPage $page)
	{
		$output->addTemplate($page->getTitle(), $page->getId(), $page->getLatest());
	}
}
