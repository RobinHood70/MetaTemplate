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

	const TABLE_SET = 'mt_save_set';
	const TABLE_DATA = 'mt_save_data';

	const SAVE_KEY = '|#save';

	/**
	 * $cachedTitles
	 *
	 * @var MetaTemplateData[]
	 */
	public static $cachedTitles = [];

	protected $data = [];
	protected $preview;
	protected $saved = false;

	private static $setPrefix = self::TABLE_SET . '.mt_set_';
	private static $dataPrefix = self::TABLE_DATA . '.mt_save_';

	private function __construct($isPreview)
	{
		$this->preview = $isPreview;
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
		list($magicArgs, $values) = ParserHelper::getMagicArgs($frame, $args, ParserHelper::NA_CASE, ParserHelper::NA_IF, ParserHelper::NA_IFNOT, self::NA_SUBSET);
		if (!ParserHelper::checkIfs($magicArgs) || count($values) < 2) {
			return;
		}

		$loadTitle = Title::newFromText($frame->expand(array_shift($values)));
		$subset = ParserHelper::arrayGet($magicArgs, self::NA_SUBSET, '');

		// Add $loadTitle to list of this article's transclusions, whether or not file exists and whether or not data
		// is found for loadtitle, as this page needs to refresh if the target page is changed. Only skipped if
		// parameters are invalid or ifs aren't satisfied.
		if (!$loadTitle || !$loadTitle->exists()) {
			return;
		}

		$varNames = [];
		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		foreach ($values as $name) {
			$varName = $frame->expand($name);
			$value = MetaTemplate::getVar($frame, $varName, $anyCase);
			if (is_null($value)) {
				$varNames[] = $varName;
			}
		}

		// If there are no variables to get, abort.
		if (!count($varNames)) {
			return;
		}

		$page = WikiPage::factory($loadTitle);
		$output = $parser->getOutput();
		$result = self::fetchData($output, $frame, $page, $varNames, $subset);
		if (is_null($result) && $page->isRedirect()) {
			// If no results were returned and the page is a redirect, see if there's data there.
			$page = WikiPage::factory($page->getRedirectTarget());
			$result = self::fetchData($output, $frame, $page, $varNames, $subset);
		}

		if ($result) {
			foreach ($varNames as $varName) {
				if (isset($result[$varName])) {
					$var = $result[$varName];
					if (!$var->parsed) {
						$prepro = $parser->preprocessToDom($var->data);
						$value = $frame->expand($prepro);
					} else {
						$value = $var->data;
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
		$ns = $parser->getTitle()->getNamespace();
		if (in_array($ns, [NS_SPECIAL, NS_MEDIA, NS_TEMPLATE])) {
			return;
		}

		// process before deciding whether to truly proceed, so that nowiki tags are previewed properly
		list($magicArgs, $values) = ParserHelper::getMagicArgs($frame, $args, ParserHelper::NA_CASE, ParserHelper::NA_IF, ParserHelper::NA_IFNOT, self::NA_SUBSET, self::NA_SAVEMARKUP);
		if (!ParserHelper::checkIfs($magicArgs) || count($values) == 0) {
			return;
		}

		$anyCase = ParserHelper::checkAnyCase($magicArgs);
		$saveMarkup = ParserHelper::arrayGet($magicArgs, self::NA_SAVEMARKUP, false);
		$subset = ParserHelper::arrayGet($magicArgs, self::NA_SUBSET, '');
		$data = [];
		foreach ($values as $varNameNodes) {
			$varName = $frame->expand($varNameNodes);
			$value = MetaTemplate::getVar($frame, $varName, $anyCase);
			if (!is_null($value)) {
				$frame->namedArgs[self::SAVE_KEY] = 'saving'; // This is a total hack to let the tag hook know that we're saving now.
				if ($saveMarkup) {
					$value = $frame->expand($value, PPFrame::RECOVER_ORIG);
					$value = self::doSaveMarkupTag($value, [], $parser, $frame);
				} else {
					$value = $frame->expand($value, PPFrame::NO_TEMPLATES | PPFrame::STRIP_COMMENTS | PPFrame::NO_IGNORE);
					$value = $parser->preprocessToDom($value, Parser::PTD_FOR_INCLUSION);
					$value = $frame->expand($value, PPFrame::NO_TEMPLATES | PPFrame::STRIP_COMMENTS);
					$value = $parser->mStripState->unstripBoth($value);
				}

				$parsed = $frame->namedArgs[self::SAVE_KEY] !== 'unparsed';
				show($parsed, ' ', $varName, '=', $value);
				$data[$varName] = new MetaTemplateVariable($value, $parsed);
				unset($frame->namedArgs[self::SAVE_KEY]);
			}
		}

		self::cacheData($parser, $data, $subset);
	}

	public static function doSaveMarkupTag($content, array $attributes, Parser $parser, PPFrame $frame)
	{
		// We don't care what the value of the argument is here, only that it exists. It could be 'saving', or it oculd be 'unparsed' if multiple tags are used.
		if ($frame->getArgument(self::SAVE_KEY)) {
			$frame->namedArgs[self::SAVE_KEY] = 'unparsed';
			$dom = $parser->preprocessToDom($content, Parser::PTD_FOR_INCLUSION);
			$value = $frame->expand($dom, PPFrame::NO_TEMPLATES | PPFrame::STRIP_COMMENTS);
			return $value;
		}

		// This tag is a marker for the doSave function, so we don't need to do anything beyond normal frame expansion.
		$value = $parser->recursiveTagParse($content, $frame);
		return $value;
	}

	/**
	 * saveCache
	 *
	 * @return void
	 */
	public static function saveCache()
	{
		if (wfReadOnly()) {
			return true;
		}

		$dbw = wfGetDB(DB_MASTER);
		$dbr = wfGetDB(DB_SLAVE);
		foreach (self::$cachedTitles as $data) {
			$data->save($dbw, $dbr);
		}
	}

	/**
	 * tablesExist
	 *
	 * @return bool
	 */
	public static function tablesExist()
	{
		// SoR: move to MetaTemplateData class when ready.
		$db = wfGetDB(DB_SLAVE);
		return
			$db->tableExists(MetaTemplateData::TABLE_SET) &&
			$db->tableExists(MetaTemplateData::TABLE_DATA);
	}

	private static function	fetchData(ParserOutput $output, PPFrame_Hash $frame, WikiPage $page, array $varNames, $subset)
	{
		$title = $page->getTitle();
		$pageId = $page->getId();
		$revId = $page->getRevision()->getId();
		$output->addTemplate($title, $pageId, $revId);
		$result = self::tryFetchFromCache($frame, $pageId, $varNames, $subset);
		if (!$result) {
			$result = self::tryFetchFromDatabase($frame, $pageId, $revId, $varNames, $subset);
		}

		return $result;
	}

	/**
	 * getVariables
	 *
	 * @param Database $db
	 * @param int $pageId
	 * @param int $revId
	 * @param array $varNames
	 * @param string $subset
	 *
	 * @return ResultWrapper|bool
	 */
	private static function getVariables(Database $db, $pageId, $revId, array $varNames, $subset)
	{
		$tables = [self::TABLE_SET, self::TABLE_DATA];
		$conds = [
			self::$setPrefix . 'page_id' => $pageId,
			self::$setPrefix . "rev_id >= $revId",
			self::$setPrefix . 'subset' => $subset,
			self::$dataPrefix . 'varname' => $varNames
		];
		$fields = [
			self::$dataPrefix . 'varname',
			self::$dataPrefix . 'value',
			self::$dataPrefix . 'parsed',
		];

		// Transactions should make sure this never happens, but in the event that we got more than one rev_id back,
		// ensure that we start with the lowest first, so data is overridden by the most recent values once we get
		// there, but lower values will exist if the write is incomplete.
		$options = ['ORDER BY' => 'mt_set_rev_id ASC'];
		$joinConds = [self::TABLE_SET => ['JOIN', self::$setPrefix . 'id = ' . self::$dataPrefix . 'id']];
		$result = $db->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);

		return $result;
	}

	/**
	 * addData
	 *
	 * @param Parser $parser
	 * @param array $data
	 * @param mixed $subset
	 *
	 * @return void
	 */
	private static function cacheData(Parser $parser, array $data, $subset)
	{
		$id = $parser->getTitle()->getArticleID();
		if (!array_key_exists($id, self::$cachedTitles)) {
			self::$cachedTitles[$id] = new self($parser->getOptions()->getIsPreview());
		}

		self::$cachedTitles[$id]->add($data, $subset);
	}

	private static function tryFetchFromCache(PPFrame_Hash $frame, $pageId, array $varNames, $subset)
	{
		if (array_key_exists($pageId, self::$cachedTitles)) {
			$cache = self::$cachedTitles[$pageId];
			return $cache->load($frame, $varNames, $subset);
		}

		return null;
	}

	/**
	 * processData
	 *
	 * @param ResultWrapper $result
	 * @param PPFrame $frame
	 *
	 * @return void
	 */
	private static function tryFetchFromDatabase(PPFrame_Hash $frame, $pageId, $revId, $varNames, $subset)
	{
		$db = wfGetDB(DB_SLAVE);
		$result = self::getVariables($db, $pageId, $revId, $varNames, $subset);
		$retval = [];
		if ($result && $result->numRows()) {
			$row = $result->fetchRow();
			while ($row) {
				$value = $row['mt_save_value'];
				if (!$row['mt_save_parsed'])
					$value = $frame->expand($value); // $parser->recursiveTagParse($value, $frame);
				MetaTemplate::setVar($frame, $row['mt_save_varname'], $value);
				$row = $result->fetchRow();
			}

			return true;
		}

		return false;
	}

	/**
	 * addData
	 *
	 * @param Parser $parser
	 * @param array $data
	 * @param string $subset
	 *
	 * @return [type]
	 */
	private function add(array $data, $subset)
	{
		// Should be the same across all instances, but helps future-proof it, since MW is doing massive changes.
		$this->data[$subset] = $data;

		/* foreach ($data as $key => $value) {
			$this->data[$subset][$key] = $parser->killMarkers($data);
		} */
	}

	private function load($frame, $varNames, $subset)
	{
		$retval = isset($this->data[$subset]);
		if ($retval) {
			/** @var MetaTemplateVariable[] */
			return $this->data[$subset];
		}
	}

	/**
	 * save
	 *
	 * @param IDatabase $dbw
	 * @param IDatabase $db
	 *
	 * @return bool
	 */
	private function save(IDatabase $dbw, IDatabase $db)
	{
		if ($this->preview || !count($this->data)) {
			return true;
		}

		return true;
		$revisionId = $this->title->getLatestRevID();
		$pageId = $this->title->getArticleID();


		// updating algorithm is based on assumption that its unlikely any data is actually been changed, therefore
		// * it's best to read the existing DB data before making any DB updates/inserts
		// * the chances are that we're going to need to read all the data for this save_set,
		//   so best to read it all at once instead of one entry at a time
		// * best to use read-only DB object until/unless it's clear that we'll need to write

		// 'order by' is to ensure that if there are duplicates, I'll always delete the out-of-date revision, or else
		// the lowest numbered one
		$res = $db->select('mt_save_set', array('mt_set_id', 'mt_set_rev_id', 'mt_set_subset'), array('mt_set_page_id' => $pageId), 'MetaTemplateSaveData-savedata', array('ORDER BY' => 'mt_set_rev_id DESC, mt_set_id DESC'));
		$oldsets = [];
		$delids = [];
		while ($row = $db->fetchRow($res)) {
			if (isset($oldsets[$row['mt_set_subset']]))
				$delids[] = $row['mt_set_id'];
			else
				$oldsets[$row['mt_set_subset']] = array('mt_set_id' => $row['mt_set_id'], 'mt_set_rev_id' => $row['mt_set_rev_id']);
		}

		// what about simultaneous processing of same page??  since I know it happens (although hopefully generally on null-type edits)
		// should only produce errors if attemt to insert records twice
		// ... should order of DB writes be tweaked
		foreach ($this->data as $subset => $subdata) {
			$olddata = NULL;
			$inserts = [];
			if (isset($oldsets[$subset])) {
				if ($oldsets[$subset]['mt_set_rev_id'] > $revisionId) {
					// set exists, and is more up-to-date than this call (unlikely, but theoretically possible with simultaneous processing)
					unset($oldsets[$subset]);
					continue;
				} elseif ($oldsets[$subset]['mt_set_rev_id'] == $revisionId) {
					// rev_id hasn't changed, suggesting I could just skip the subset at this point
					// but there could be changes inherited from templates (new variables to save, etc.)
					$setid = $oldsets[$subset]['mt_set_id'];
				} else {
					// set exists, but rev_id has changed (page has been edited)
					if (!isset($dbw))
						$dbw = wfGetDB(DB_MASTER);
					$setid = $oldsets[$subset]['mt_set_id'];
					$dbw->update('mt_save_set', array('mt_set_rev_id' => $revisionId), array('mt_set_id' => $setid));
				}
				unset($oldsets[$subset]);
			} else {
				// newly created set
				if (!isset($dbw))
					$dbw = wfGetDB(DB_MASTER);
				// could theoretically get two inserts happening simultaneously here
				// can't be prevented using replace, because the values I'm inserting aren't indices
				// instead, I need to be sure that duplicates won't mess up any routines reading data
				// and handle cleaning up the duplicate next time this routine is called
				$dbw->insert('mt_save_set', array(
					'mt_set_page_id' => $pageId,
					'mt_set_rev_id' => $revisionId,
					'mt_set_subset' => $subset
				));
				$setid = $dbw->insertId();
				$olddata = [];
			}
			if (is_null($olddata)) {
				$res = $db->select('mt_save_data', array('mt_save_varname', 'mt_save_value', 'mt_save_parsed'), array('mt_save_id' => $setid));
				while ($row = $db->fetchRow($res)) {
					$olddata[$row['mt_save_varname']] = array('mt_save_value' => $row['mt_save_value'], 'mt_save_parsed' => $row['mt_save_parsed']);
				}
			}

			// addslashes is not needed here: default wiki processing is already taking care of add/strip somewhere in
			// the process (tested with quotes and slash)
			foreach ($subdata as $key => $vdata) {
				$value = $vdata['value'];

				if (array_key_exists('parsed', $vdata))
					$parsed = $vdata['parsed'];
				else
					$parsed = true;
				if (!isset($olddata[$key])) {
					$inserts[] = array(
						'mt_save_id' => $setid,
						'mt_save_varname' => $key,
						'mt_save_value' => $value,
						'mt_save_parsed' => $parsed
					);
				} else {
					if ($olddata[$key]['mt_save_value'] != $value || $olddata[$key]['mt_save_parsed'] != $parsed) {
						if (!isset($dbw))
							$dbw = wfGetDB(DB_MASTER);
						// updates can't be done in a batch... unless I delete then insert them all
						// but I'm assuming that it's most likely only value needs to be updated, in which case
						// it's most efficient to simply make updates one value at a time
						$dbw->update(
							'mt_save_data',
							array(
								'mt_save_value' => $value,
								'mt_save_parsed' => $parsed
							),
							array(
								'mt_save_id' => $setid,
								'mt_save_varname' => $key
							)
						);
					}
					unset($olddata[$key]);
				}
			}
			if (count($olddata)) {
				if (!isset($dbw))
					$dbw = wfGetDB(DB_MASTER);
				$dbw->delete('mt_save_data', array(
					'mt_save_id' => $setid,
					'mt_save_varname' => array_keys($olddata)
				));
			}
			if (count($inserts)) {
				if (!isset($dbw))
					$dbw = wfGetDB(DB_MASTER);
				// use replace instead of insert just in case there's simultaneous processing going on
				// second param isn't used by mysql, but provide it just in case another DB is used
				$dbw->replace('mt_save_data', array('mt_save_id', 'mt_save_varname'), $inserts);
			}
		}

		/* TODO: Create an actual job class to do this and run it on occasion. (See if there are relevant hooks, otherwise use a similar low-frequency method as below.)
		//self::cleardata( $this->title );
		if (count($oldsets) || count($delids)) {
			foreach ($oldsets as $subset => $subdata)
				$delids[] = $subdata['mt_set_id'];
			self::clearsets($delids);
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
				self::clearoldsets($n);
			}
		}
		*/

		return true;
	}
}
