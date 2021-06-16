<?php

/**
 * [Description MetaTemplateData]
 */
class MetaTemplateData
{
	const NA_SAVEMARKUP = 'metatemplate-savemarkup';
	const NA_SUBSET = 'metatemplate-subset';

	const PF_LISTSAVED = 'metatemplate-listsaved';
	const PF_LOAD = 'metatemplate-load';
	const PF_SAVE = 'metatemplate-save';

	const SET_TABLE = 'mt_save_set';
	const SET_PREFIX = 'mt_set_';
	const DATA_TABLE = 'mt_save_data';
	const DATA_PREFIX = 'mt_save_';

	private static $saveArgNameWidth = 50;
	private static $saveKey = '|#save';
	private static $subsetNameWidth = 50;

	// IMP: Respects case=any when determining what to load.
	// IMP: No longer auto-inherits and uses subset. Functionality is now at user's discretion via traditional methods or inheritance.
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
			self::NA_SUBSET
		);

		if (!ParserHelper::checkIfs($magicArgs) || count($values) < 2) {
			return;
		}

		$loadTitle = Title::newFromText($frame->expand(array_shift($values)));
		if ($loadTitle && $loadTitle->getNamespace() >= NS_MAIN) {
			// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists, in
			// case it's created in the future.
			$page = WikiPage::factory($loadTitle);
			if ($page) {
				$output = $parser->getOutput();
			}
		}

		if (!$output) {
			return;
		}

		self::trackPage($output, $page);
		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$varNames = [];
		foreach (self::getVarNames($frame, $values, $anyCase) as $varName => $value) {
			if (is_null($value)) {
				$varNames[] = $varName;
			}
		}

		// If there are no variables to get, abort.
		if (!count($varNames)) {
			return;
		}

		$subsetName = ParserHelper::arrayGet($magicArgs, self::NA_SUBSET, '');
		if (strlen($subsetName) > self::$subsetNameWidth) {
			// We check first because substr can return false with '', converting the string to a boolean unexpectedly.
			$subsetName = substr($subsetName, 0, self::$subsetNameWidth);
		}

		$result = self::fetchData($page, $parser->getRevisionId(), $subsetName, $output, $varNames);
		if (is_null($result) && $loadTitle->isRedirect()) {
			// If no results were returned and the page is a redirect, see if there's data there.
			$page = WikiPage::factory($page->getRedirectTarget());
			$result = self::fetchData($page, $page->getLatest(), $subsetName, $output, $varNames);
			self::trackPage($output, $page);
		}

		if ($result) {
			foreach ($varNames as $varName) {
				if (isset($result[$varName])) {
					$var = $result[$varName];
					if (!$var->parsed) {
						$prepro = $parser->preprocessToDom($var->value);
						$value = $frame->expand($prepro);
					} else {
						$value = $var->value;
					}

					MetaTemplate::setVar($frame, $varName, $value);
				}
			}
		}
	}

	// IMP: No longer auto-inherits subset variable.
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
		// Do not save if this is a Media, Special (e.g., [[Special:ExpandTemplates]])), or Template page triggering
		// the save, or if we're in preview mode.
		if (in_array($parser->getTitle()->getNamespace(), [NS_SPECIAL, NS_MEDIA, NS_TEMPLATE])) {
			return;
		}

		// process before deciding whether to truly proceed, so that nowiki tags are previewed properly
		list($magicArgs, $values) = ParserHelper::getMagicArgs(
			$frame,
			$args,
			ParserHelper::NA_CASE,
			ParserHelper::NA_IF,
			ParserHelper::NA_IFNOT,
			self::NA_SUBSET,
			self::NA_SAVEMARKUP
		);

		if (!ParserHelper::checkIfs($magicArgs) || count($values) == 0) {
			return;
		}

		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$saveMarkup = ParserHelper::arrayGet($magicArgs, self::NA_SAVEMARKUP, false);
		$frameFlags = $saveMarkup ? PPFrame::NO_TEMPLATES : 0;
		$subset = ParserHelper::arrayGet($magicArgs, self::NA_SUBSET, '');
		$data = [];
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

				// show('Final Output (', $parsed ? 'parsed ' : 'unparsed ', '): ', $subset, '->', $varName, '=', htmlspecialchars($value));
				$data[$varName] = new MetaTemplateVariable($value, $parsed);
				unset($frame->namedArgs[self::$saveKey]);
			}
		}

		$page = WikiPage::factory($parser->getTitle());
		self::add($page, $parser->getOutput(), $data, $subset);
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
	 * tablesExist
	 *
	 * @return bool
	 */
	public static function tablesExist()
	{
		$dbRead = wfGetDB(DB_SLAVE);
		return
			$dbRead->tableExists(MetaTemplateData::SET_TABLE) &&
			$dbRead->tableExists(MetaTemplateData::DATA_TABLE);
	}

	/**
	 * add
	 *
	 * @param array $data
	 * @param mixed $subset
	 *
	 * @return void
	 */
	private static function add(WikiPage $page, ParserOutput $output, array $data, $subsetName)
	{
		$pageId = $page->getId();
		$rev = $page->getRevision();
		$revId = $rev ? $rev->getId() : 0;
		$pageData = self::getOrAddPageData($output, $pageId, $revId);
		$set = $pageData->getOrAddSet($subsetName, $revId);
		$set->addSubset($data);
	}

	private static function	fetchData($pageId, $revId, $subsetName, ParserOutput $output, array $varNames)
	{
		$result = self::tryFetchFromCache($pageId, $subsetName, $output);
		if (!$result) {
			$result = self::tryFetchFromDatabase($pageId, $revId, $varNames, $subsetName);
		}

		return $result;
	}

	private static function getOrAddPageData(ParserOutput $output, $pageId, $revId)
	{
		$retval =  $output->getExtensionData(self::PF_SAVE);
		if (!$retval) {
			$retval = [];
			$output->setExtensionData(self::PF_SAVE, $retval);
		}

		if (!isset($retval[$pageId])) {
			$retval[$pageId] = new MetaTemplateSetCollection($pageId, $revId);
		}

		return $retval[$pageId];
	}

	private static function getVarNames(PPFrame $frame, $values, $anyCase)
	{
		foreach ($values as $varNameNodes) {
			$varName = $frame->expand($varNameNodes);
			$varName = substr($varName, 0, self::$saveArgNameWidth);
			$value = MetaTemplate::getVar($frame, $varName, $anyCase);
			yield $varName => $value;
		}
	}

	private static function trackPage(ParserOutput $output, WikiPage $page)
	{
		$output->addTemplate($page->getTitle(), $page->getId(), $page->getLatest());
	}

	/**
	 * tryFetchFromCache
	 *
	 * @param mixed $pageId
	 * @param array $varNames
	 * @param mixed $subset
	 *
	 * @return MetaTemplateVariablep[]|false;
	 */
	private static function tryFetchFromCache($pageId, $subsetName = '', ParserOutput $output)
	{
		$vars = $output->getExtensionData(self::PF_SAVE);
		if (isset($vars[$pageId]->sets[$subsetName])) {
			return $vars[$pageId]->sets[$subsetName]->variables;
		}

		return false;
	}

	/**
	 * processData
	 *
	 * @param ResultWrapper $result
	 * @param PPFrame $frame
	 *
	 * @return MetaTemplateVariable[]|bool
	 */
	private static function tryFetchFromDatabase($pageId, $subsetName, $revId, $varNames)
	{
		$dbRead = wfGetDB(DB_SLAVE);
		$tables = [self::SET_TABLE, self::DATA_TABLE];
		$conds = [
			self::SET_PREFIX . 'page_id' => $pageId,
			self::SET_PREFIX . "rev_id >= $revId",
			self::SET_PREFIX . 'subset' => $subsetName,
			self::DATA_PREFIX . 'varname' => $varNames
		];
		$fields = [
			self::DATA_PREFIX . 'varname',
			self::DATA_PREFIX . 'value',
			self::DATA_PREFIX . 'parsed',
		];

		// Transactions should make sure this never happens, but in the event that we got more than one rev_id back,
		// ensure that we start with the lowest first, so data is overridden by the most recent values once we get
		// there, but lower values will exist if the write is incomplete.
		$options = ['ORDER BY' => self::SET_PREFIX . 'rev_id ASC'];
		$joinConds = [self::SET_TABLE => ['JOIN', self::SET_PREFIX . 'id = ' . self::DATA_PREFIX . 'id']];
		$result = $dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

		$retval = [];
		if ($result && $result->numRows()) {
			$row = $result->fetchRow();
			while ($row) {
				$retval[] = new MetaTemplateVariable($row[self::DATA_PREFIX . 'value'], $row[self::DATA_PREFIX . 'parsed']);
				$row = $result->fetchRow();
			}

			return $retval;
		}

		return false;
	}
}
