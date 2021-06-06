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


	// These give us a centralized location for the databases and ensure that we have the same values for them across
	// all queries. Both are initialized at once in dbInit().
	/**
	 * $dbRead
	 *
	 * @var DatabaseBase
	 */
	private static $dbRead;
	/**
	 * $dbWrite
	 *
	 * @var DatabaseBase
	 */
	private static $dbWrite;
	/**
	 * $pageData
	 *
	 * May be split off into indexed array of objects rather than a double-indexed one.
	 * While this will probably only ever contain a single page, we index by both Page ID and subset name in case
	 * something like Export or an extension triggers multiple page updates within the same request.
	 *
	 * @var MetaTemplateSetCollection[]
	 */
	private static $pageCache = [];
	private static $saveArgNameWidth = 50;
	private static $saveKey = '|#save';
	private static $subsetNameWidth = 50;

	private static function dbInit()
	{
		if (!isset(self::$dbRead)) {
			self::$dbRead = wfGetDB(DB_SLAVE);
			self::$dbWrite =
				wfGetDB(DB_MASTER);
		}
	}

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
		if (!$loadTitle) {
			return;
		}

		// If $loadTitle is valid, add it to list of this article's transclusions, whether or not it exists, in
		// case it's created in the future.
		$page = WikiPage::factory($loadTitle);
		$output = $parser->getOutput();
		self::trackPage($output, $page);
		if (!$loadTitle->exists()) {
			return;
		}

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

		$result = self::fetchData($page, $varNames, $subsetName);
		if (is_null($result) && $loadTitle->isRedirect()) {
			// If no results were returned and the page is a redirect, see if there's data there.
			$page = WikiPage::factory($page->getRedirectTarget());
			self::trackPage($output, $page);
			$result = self::fetchData($page, $varNames, $subsetName);
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
		self::add($page, $data, $subset);
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
		self::dbInit();
		return
			self::$dbRead->tableExists(MetaTemplateData::SET_TABLE) &&
			self::$dbRead->tableExists(MetaTemplateData::DATA_TABLE);
	}

	public static function savePage(WikiPage $page)
	{
		if (wfReadOnly() || !count(self::$pageCache)) {
			return true;
		}

		// Updating algorithm is based on assumption that its unlikely any data is actually been changed, therefore:
		// * it's best to read the existing DB data before making any DB updates/inserts
		// * the chances are that we're going to need to read all the data for this save_set,
		//   so best to read it all at once instead of one entry at a time
		// * best to use read-only DB object until/unless it's clear that we'll need to write

		// For now, data updates are fully customized, but could also be using upsert at some point. That has the
		// slight disadvantage of trying to update first, then seeing what failed, where this routines makes all the
		// decisions in advance. The downside is it's much longer.
		self::dbInit();
		$oldData = self::loadExisting($page);
		// show('Sets: ', $oldData->sets);
		$pageData = self::$pageCache[$page->getId()];
		$deleteIds = [];
		foreach ($oldData->sets as $setName => $oldSet) {
			if (!isset($pageData->sets[$setName])) {
				$deleteIds[] = $oldSet->setId;
			}
		}

		$atom = __METHOD__;
		self::$dbWrite->startAtomic($atom);
		if (count($deleteIds)) {
			self::$dbWrite->delete(MetaTemplateData::DATA_TABLE, [
				MetaTemplateData::DATA_PREFIX . 'set_id' => $deleteIds
			]);
			self::$dbWrite->delete(MetaTemplateData::SET_TABLE, [
				MetaTemplateData::DATA_PREFIX . 'set_id' => $deleteIds
			]);
		}

		// try {
		$inserts = [];
		foreach ($pageData->sets as $subsetName => $subdata) {
			if (isset($oldData->sets[$subsetName])) {
				if ($oldData->revId < $pageData->revId) {
					// Set exists but RevisionID has changed (page has been edited).
					$oldSet = $oldData->sets[$subsetName];
					self::$dbWrite->update(
						MetaTemplateData::SET_TABLE,
						[MetaTemplateData::SET_PREFIX . 'rev_id' => $pageData->revId],
						[MetaTemplateData::SET_PREFIX . 'id' => $oldSet->setid]
					);
				}
			} else {
				// New set.
				self::$dbWrite->insert(MetaTemplateData::SET_TABLE, [
					MetaTemplateData::SET_PREFIX . 'page_id' => $pageData->pageId,
					MetaTemplateData::SET_PREFIX . 'rev_id' => $pageData->revId,
					MetaTemplateData::SET_PREFIX . 'subset' => $subsetName
				]);
				$subdata->setId = self::$dbWrite->insertId();

				foreach ($subdata->variables as $key => $var) {
					$inserts[] = [
						MetaTemplateData::DATA_PREFIX . 'id' => $subdata->setId,
						MetaTemplateData::DATA_PREFIX . 'varname' => $key,
						MetaTemplateData::DATA_PREFIX . 'value' => $var->value,
						MetaTemplateData::DATA_PREFIX . 'parsed' => $var->parsed
					];
				}
			}

			self::updateData($subdata);
		}
		/*} catch ($e) {
			self::$dbWrite->rollback($atom);
			throw $e;
		}*/

		if (count($inserts)) {
			// use replace instead of insert just in case there's simultaneous processing going on
			// second param isn't used by mysql, but provide it just in case another DB is used
			self::$dbWrite->replace(
				MetaTemplateData::DATA_TABLE,
				[MetaTemplateData::DATA_PREFIX . 'id', MetaTemplateData::DATA_PREFIX . 'varname'],
				$inserts
			);
		}

		self::$dbWrite->endAtomic($atom);

		/* TODO: Create an actual job class to do this and run it on occasion. (See if there are relevant hooks, otherwise use a similar low-frequency method as below.)
		//MetaTemplateData::cleardata( $this->title );
		if (count($oldData) || count($deleteIds)) {
			foreach ($oldData as $subset => $subdata)
				$deleteIds[] = $subdata[MetaTemplateData::SET_PREFIX . 'id'];
			MetaTemplateData::clearsets($deleteIds);
		}

		global $wgJobRunRate;
		// same frequency algorithm used by Wiki.php to determine whether or not to do a job
		if ($wgJobRunRate > 0) {
			if ($wgJobRunRate < 1) {
				$max = mt_getrandmax();
				if (mt_rand(0, $max) > $max * $wgJobRunRate)
					$n = 0;
				else
					$n = 1;
			} else {
				$n = intval($wgJobRunRate);
			}
			if ($n) {
				MetaTemplateData::clearoldsets($n);
			}
		}
		*/

		return true;
	}

	/**
	 * add
	 *
	 * @param array $data
	 * @param mixed $subset
	 *
	 * @return void
	 */
	private static function add(WikiPage $page, array $data, $subsetName)
	{
		$pageId = $page->getId();
		$revId = $page->getRevision()->getId();
		if (!isset(self::$pageCache[$pageId])) {
			self::$pageCache[$pageId] = new MetaTemplateSetCollection($pageId, $revId);
		}

		$pageData = self::$pageCache[$pageId];
		$set = $pageData->getOrAddSet($subsetName, $revId);
		$set->addSubset($data);
	}

	private static function	fetchData(WikiPage $page, array $varNames, $subsetName)
	{
		$pageId = $page->getId();
		$result = self::tryFetchFromCache($pageId, $varNames, $subsetName);
		if (!$result) {
			$result = self::tryFetchFromDatabase($pageId, $page->getRevision()->getId(), $varNames, $subsetName);
		}

		return $result;
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

	/**
	 * loadExisting
	 *
	 * @param mixed $pageId
	 *
	 * @return MetaTemplateSetCollection;
	 */
	public static function loadExisting(WikiPage $page)
	{
		// Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
		// data is tracked with $deleteIds. While the database should never be in this state with the current design,
		// this should allow for correct behaviour with simultaneous database updates in the event that of some future
		// non-transactional approach.
		self::dbInit();
		$pageId = $page->getId();
		$tables = [MetaTemplateData::SET_TABLE, MetaTemplateData::DATA_TABLE];
		$conds = [MetaTemplateData::SET_PREFIX . 'page_id' => $pageId];
		$fields = [
			MetaTemplateData::SET_PREFIX . 'rev_id',
			MetaTemplateData::SET_PREFIX . 'subset',
			MetaTemplateData::DATA_PREFIX . 'varname',
			MetaTemplateData::DATA_PREFIX . 'value',
			MetaTemplateData::DATA_PREFIX . 'parsed',
		];
		$options = ['ORDER BY' => MetaTemplateData::SET_PREFIX . 'rev_id DESC'];
		$joinConds = [MetaTemplateData::SET_TABLE => ['JOIN', MetaTemplateData::SET_PREFIX . 'id = ' . MetaTemplateData::DATA_PREFIX . 'id']];
		$result = self::$dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

		/** @var MetaTemplateSet[] */
		$row = self::$dbRead->fetchRow($result);
		$retval = new MetaTemplateSetCollection($pageId, $row[MetaTemplateData::SET_PREFIX . 'rev_id']);
		while ($row) {
			$subsetName = $row[MetaTemplateData::SET_PREFIX . 'subset'];
			$set =  $retval->getOrAddSet($subsetName, $row[MetaTemplateData::SET_PREFIX . 'rev_id']);
			$set->addVar($row[MetaTemplateData::DATA_PREFIX . 'varname'], $row[MetaTemplateData::DATA_PREFIX . 'value'], $row[MetaTemplateData::DATA_PREFIX . 'parsed']);
			$row = self::$dbRead->fetchRow($result);
		}

		return $retval;
	}

	private static function trackPage(ParserOutput $output, WikiPage $page)
	{
		$output->addTemplate($page->getTitle(), $page->getId(), $page->getRevision()->getId());
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
	private static function tryFetchFromCache($pageId, array $varNames, $subsetName = '')
	{
		if (isset(self::$pageCache[$pageId]->sets[$subsetName])) {
			return self::$pageCache[$pageId]->sets[$subsetName]->variables;
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
	private static function tryFetchFromDatabase($pageId, $revId, $varNames, $subset)
	{
		self::dbInit();
		$tables = [self::SET_TABLE, self::DATA_TABLE];
		$conds = [
			self::SET_PREFIX . 'page_id' => $pageId,
			self::SET_PREFIX . "rev_id >= $revId",
			self::SET_PREFIX . 'subset' => $subset,
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
		$result = self::$dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

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

	public static function updateData(MetaTemplateSet $oldSet)
	{
		self::dbInit();
		foreach ($oldSet->variables as $key => $var) {
			if (
				!is_null($oldSet) &&
				$oldSet->setId != 0 &&
				$var == $oldSet->variables[$key]
			) {
				// updates can't be done in a batch... unless I delete then insert them all
				// but I'm assuming that it's most likely only value needs to be updated, in which case
				// it's most efficient to simply make updates one value at a time
				self::$dbWrite->update(
					MetaTemplateData::DATA_TABLE,
					[
						MetaTemplateData::DATA_PREFIX . 'value' => $var->value,
						MetaTemplateData::DATA_PREFIX . 'parsed' => $var->parsed
					],
					[
						MetaTemplateData::DATA_PREFIX . 'id' => $oldSet->setId,
						MetaTemplateData::DATA_PREFIX . 'varname' => $key
					]
				);

				unset($oldSet->variables[$key]);
			}
		}

		// show('Deletable: ', $oldSet->variables);
		if (count($oldSet->variables)) {
			self::$dbWrite->delete(MetaTemplateData::DATA_TABLE, [
				MetaTemplateData::DATA_PREFIX . 'id' => $oldSet->setId,
				MetaTemplateData::DATA_PREFIX . 'varname' => array_keys($oldSet->variables)
			]);
		}
	}
}
