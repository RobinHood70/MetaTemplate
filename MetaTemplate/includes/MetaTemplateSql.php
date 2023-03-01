<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Handles all SQL-related functions for MetaTemplate.
 */
class MetaTemplateSql
{
	#region Public Constants
	public const DATA_SET_ID = self::TABLE_DATA . '.' . self::FIELD_SET_ID;
	public const DATA_VAR_NAME = self::TABLE_DATA . '.' . self::FIELD_VAR_NAME;
	public const DATA_VAR_VALUE = self::TABLE_DATA . '.' . self::FIELD_VAR_VALUE;

	public const FIELD_PAGE_ID = 'pageId';
	public const FIELD_REV_ID = 'revId';
	public const FIELD_SET_ID = 'setId';
	public const FIELD_SET_NAME = 'setName';
	public const FIELD_VAR_NAME = 'varName';
	public const FIELD_VAR_VALUE = 'varValue';

	public const SET_PAGE_ID = self::TABLE_SET . '.' . self::FIELD_PAGE_ID;
	public const SET_REV_ID = self::TABLE_SET . '.' . self::FIELD_REV_ID;
	public const SET_SET_ID = self::TABLE_SET . '.' . self::FIELD_SET_ID;
	public const SET_SET_NAME = self::TABLE_SET . '.' . self::FIELD_SET_NAME;

	public const TABLE_DATA = 'mtSaveData';
	public const TABLE_SET = 'mtSaveSet';
	#endregion

	#region Private Constants
	private const OLDTABLE_SET = 'mt_save_set';
	private const OLDTABLE_DATA = 'mt_save_data';
	#endregion

	#region Private Static Variables
	/** @var MetaTemplateSql */
	private static $instance;

	/**
	 * A list of all the pages purged during this session to avoid looping.
	 *
	 * @var array
	 */
	private static $pagesPurged = [];
	#endregion

	#region Private Variables
	/** @var IDatabase */
	private $dbRead;

	/** @var IDatabase */
	private $dbWrite;
	#endregion

	#region Constructor (private)
	/**
	 * Creates an instance of the MetaTemplateSql class.
	 */
	private function __construct()
	{
		$dbWriteConst = defined('DB_PRIMARY') ? 'DB_PRIMARY' : 'DB_MASTER';
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$this->dbRead = $lb->getConnectionRef(constant($dbWriteConst));

		// We get dbWrite lazily since writing will often be unnecessary.
		$this->dbWrite = $lb->getLazyConnectionRef(constant($dbWriteConst));
	}
	#endregion

	#region Public Static Functions
	/**
	 * Gets the global singleton instance of the class.
	 *
	 * @return MetaTemplateSql
	 */
	public static function getInstance(): MetaTemplateSql
	{
		if (!isset(self::$instance)) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public static function getPageswWithMetaVarsQueryInfo(?string $nsNum, ?string $setName, ?string $varName): array
	{
		$tables = [
			self::TABLE_SET, 'page'
		];
		$fields = [
			'page.page_id',
			'page.page_namespace',
			'page.page_title',
			'page.page_len',
			'page.page_is_redirect',
			'page.page_latest',
			self::SET_SET_NAME,
		];

		$conds = [];
		$joinConds = [self::TABLE_SET => ['INNER JOIN', 'page.page_id = ' . self::SET_PAGE_ID]];
		$setName = $setName ?? '*';
		if ($setName === '*' || isset($varName)) {
			$tables[] = self::TABLE_DATA;
			$fields[] = self::DATA_VAR_NAME;
			$fields[] = self::DATA_VAR_VALUE;
			$conds[self::DATA_VAR_NAME] =  $varName;
			$joinConds[self::TABLE_DATA] = ['INNER JOIN', self::SET_SET_ID . ' = ' . self::DATA_SET_ID];
		}

		if ($setName !== '*') {
			$conds[self::SET_SET_NAME] = $setName;
		}

		if (
			$nsNum !== null && $nsNum !== 'all'
		) {
			$conds['page_namespace'] = $nsNum;
		}

		return [
			'tables' => $tables,
			'fields' => $fields,
			'conds' => $conds,
			'join_conds' => $joinConds
		];
	}

	// Initial table setup/modifications from v1.
	/**
	 * Migrates the old MetaTemplate tables to new ones. The basic functionality is the same, but names and indeces
	 * have been altered and the datestamp removed.
	 *
	 * @param DatabaseUpdater $updater
	 *
	 * @return void
	 */
	public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater): void
	{
		/** @var string $dir  */
		$dir = dirname(__DIR__);
		$db = $updater->getDB();
		if (!$db->tableExists(self::TABLE_SET)) {
			$updater->addExtensionTable(self::TABLE_SET, "$dir/sql/create-" . self::TABLE_SET . '.sql');
		}

		$instance = self::getInstance();
		$updater->addExtensionUpdate([[$instance, 'migrateSetTable'], $dir]);

		if (!$db->tableExists(self::TABLE_DATA)) {
			$updater->addExtensionTable(self::TABLE_DATA, "$dir/sql/create-" . self::TABLE_DATA . '.sql');
		}

		$updater->addExtensionUpdate([[$instance, 'migrateDataTable'], $dir]);
	}

	public static function pageIdLimiter(int $id): array
	{
		return [self::SET_PAGE_ID => $id];
	}
	#endregion

	#region Public Functions
	/**
	 * [Description for catQuery]
	 *
	 * @param MetaTemplatePage[] $pageIds
	 * @param string[] $varNames
	 *
	 * @return void
	 */
	public function catQuery(array &$pageIds, array $varNames): void
	{
		[$tables, $fields, $options, $joinConds] = self::baseQuery(self::SET_PAGE_ID, self::SET_SET_NAME);
		$conds = empty($pageIds)
			? []
			: [self::SET_PAGE_ID => array_keys($pageIds)];

		if (!empty($varNames)) {
			$conds[self::DATA_VAR_NAME] = $varNames;
		}

		#RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$rows = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options, $joinConds);
		for ($row = $rows->fetchRow(); $row; $row = $rows->fetchRow()) {
			$pageId = $row[self::FIELD_PAGE_ID];
			$setName = $row[self::FIELD_SET_NAME];
			if (isset($pageIds[$pageId])) {
				$page = $pageIds[$pageId];
			}

			if (isset($page->sets[$setName])) {
				$set = $page->sets[$setName];
			} else {
				$set = new MetaTemplateSet($setName);
				$page->sets[$setName] = $set;
			}

			#RHshow('CatQuery Set', $set);
			$varName = $row[self::FIELD_VAR_NAME];
			$varValue = $row[self::FIELD_VAR_VALUE];
			$set->variables[$varName] = $varValue;
		}
	}

	/**
	 * Handles data to be deleted.
	 *
	 * @param Title $title The title of the page to delete from.
	 *
	 * @return void
	 */
	public function deleteVariables(Title $title): void
	{
		// Assumes cascading is in effect to delete TABLE_DATA rows.
		$pageId = $title->getArticleID();
		$this->dbWrite->delete(self::TABLE_SET, [self::FIELD_PAGE_ID => $pageId]);
	}

	public function getNamespaces(): IResultWrapper
	{
		/** @todo Change to all namespaces. Leaving as is for now only to get things up and running before changing anything. */
		$tables = [self::TABLE_SET, 'page'];
		$fields = ['DISTINCT page_namespace'];
		$options = ['ORDER BY' => 'page_namespace'];
		$joinConds = [self::TABLE_SET => ['INNER JOIN', self::SET_PAGE_ID . '=' . 'page.page_id']];

		return $this->dbRead->select($tables, $fields, '', __METHOD__, $options, $joinConds);
	}

	/**
	 * Gets a list of the most-used Metatemplate variables.
	 *
	 * @param int $limit The number of variables to get.
	 *
	 * @return ?IResultWrapper
	 */
	public function getPopularVariables(int $limit): ?IResultWrapper
	{
		$table = self::TABLE_DATA;
		$fields = [self::DATA_VAR_NAME];
		$conds = self::DATA_VAR_NAME . ' NOT LIKE \'@%\'';
		$options = [
			'GROUP BY' => self::DATA_VAR_NAME,
			'ORDER BY' => 'COUNT(*) DESC',
			'LIMIT' => $limit
		];

		$retval = $this->dbRead->select(
			$table,
			$fields,
			$conds,
			__METHOD__,
			$options
		);

		return $retval ? $retval : null;
	}

	/**
	 * Checks the database to see if the page has any variables defined.
	 *
	 * @param Title $title The title to check.
	 *
	 * @return MetaTemplateSetCollection
	 */
	public function hasPageVariables(Title $title): bool
	{
		// Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
		// data is tracked with $deleteIds.

		// logFunctionText("($pageId)");
		$pageId = $title->getArticleID();
		$tables = [self::TABLE_SET];
		$fields = [self::SET_PAGE_ID];
		$options = ['LIMIT' => 1];
		$conds = [self::SET_PAGE_ID => $pageId];
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options);
		$row = $this->dbRead->fetchRow($result);
		return (bool)$row;
	}

	/**
	 * Handles data to be inserted.
	 *
	 * @param mixed $setId The set ID to insert.
	 * @param MetaTemplateSet $newSet The set to insert.
	 *
	 * @return void
	 */
	public function insertData($setId, MetaTemplateSet $newSet): void
	{
		$data = [];
		foreach ($newSet->variables as $varName => $varValue) {
			$data[] = [
				self::FIELD_SET_ID => $setId,
				self::FIELD_VAR_NAME => $varName,
				self::FIELD_VAR_VALUE => $varValue,
			];
		}

		$this->dbWrite->insert(self::TABLE_DATA, $data);
	}

	/**
	 * Preloads any variables specified by the template.
	 *
	 * @param ?int $namespace The integer namespace to restrict results to.
	 * @param ?string $setName The name of the set to be filtered to.
	 * @param MetaTemplateSet[] $conditions An array of key=>value strings to use for query conditions.
	 * @param MetaTemplateSet[] $preloadSets The data to be preloaded.
	 *
	 * @return array An array of page row data indexed by Page ID.
	 */
	public function loadListSavedData(?int $namespace, ?string $setName, ?string $sortOrder, array $conditions, array $preloadSets): array
	{
		$sortOrder = explode(',', $sortOrder);
		$extraFields = array_merge($sortOrder, array_keys($conditions));
		$preloadSets = $preloadSets ?? [];
		if (is_null($setName) && empty($preloadSets)) {
			$setLimit = [];
		} else {
			$setLimit = is_null($setName) ? [] : [$setName];
			foreach ($preloadSets as $preloadSet) {
				foreach ($preloadSet->variables as $key => $value) {
					$extraFields[] = $key;
				}

				$setLimit[] = $preloadSet->name;
			}
		}

		$extraFields = array_unique($extraFields);
		$setLimit = array_unique($setLimit);

		// Page fields other than title and namespace are here so Title doesn't have to reload them again later on.
		$tables = [
			'page',
			self::TABLE_SET,
			self::TABLE_DATA
		];

		$fields = [
			'page.page_title',
			'page.page_namespace',
			self::SET_PAGE_ID,
			self::SET_SET_ID,
			self::SET_REV_ID,
			self::SET_SET_NAME,
			self::DATA_VAR_NAME,
			self::DATA_VAR_VALUE
		];

		$options = ['ORDER BY' => [
			self::FIELD_PAGE_ID,
			self::FIELD_SET_NAME,
			self::FIELD_REV_ID
		]];

		$conds = [];
		if (!is_null($namespace)) {
			$conds['page.page_namespace'] = $namespace;
		}

		if (!empty($setLimit)) {
			$conds[self::SET_SET_NAME] = $setLimit;
		}

		$joinConds = [
			self::TABLE_SET => ['JOIN', ['page.page_id=' . self::SET_PAGE_ID]],
			self::TABLE_DATA => ['JOIN', [self::DATA_SET_ID . '=' . self::SET_SET_ID]]
		];

		$filter = 1;
		foreach ($conditions as $key => $value) {
			$filterName = 'filter' . $filter;
			$tables[$filterName] = self::TABLE_DATA;
			$joinConds[$filterName] = ['JOIN', [self::SET_SET_ID . '=' . $filterName . '.setId']];
			$conds[$filterName . '.' . self::FIELD_VAR_NAME] = $key;
			$conds[$filterName . '.' . self::FIELD_VAR_VALUE] = $value;
			++$filter;
		}

		#RHecho(__METHOD__ . " query:\n", $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$rows = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options, $joinConds);

		$retval = [];
		$prevPageId = 0;
		$prevSetId = 0;
		for ($row = $rows->fetchRow(); $row; $row = $rows->fetchRow()) {
			$rowSetName = $row[self::FIELD_SET_NAME];
			if (!is_null($setName) && $rowSetName !== $setName) {
				// Due to conditions/ordering/preloading, we may have more sets than we want, so filter the extras out.
				continue;
			}

			if ($row[self::FIELD_PAGE_ID] != $prevPageId || $row[self::FIELD_SET_ID] != $prevSetId) {
				if (isset($data)) {
					$retval[] = $data;
				}

				$prevPageId = $row[self::FIELD_PAGE_ID];
				$prevSetId = $row[self::FIELD_SET_ID];

				// newFromRow() is overkill here, since we're just parsing ns and title.
				$title = Title::makeTitle($row['page_namespace'], $row['page_title']);
				$data = [];
				$data[MetaTemplate::$mwFullPageName] = $title->getFullText();
				$data[MetaTemplate::$mwNamespace] = $title->getNsText();
				$data[MetaTemplate::$mwPageId] = $row[self::FIELD_PAGE_ID];
				$data[MetaTemplate::$mwPageName] = $title->getText();
				$data[MetaTemplate::$mwSet] = $rowSetName;
			}

			$varValue = $row[self::FIELD_VAR_VALUE];
			$data[$row[self::FIELD_VAR_NAME]] = $varValue;
		}

		if (isset($data)) {
			$retval[] = $data;
		}

		$sortOrder[] = MetaTemplate::$mwPageName;
		$sortOrder[] = MetaTemplate::$mwSet;
		$used = [];
		$args = [];
		foreach ($sortOrder as $field) {
			if (!isset($used[$field])) {
				// We can't use array_column here since rows are not guaranteed to have $field.
				$arg = [];
				foreach ($retval as $key => $data) {
					$arg[$key] = $data[$field] ?? false;
				}

				$args[] = $arg;
				$used[$field] = true;
			}
		}

		$args[] = $retval;
		call_user_func_array('array_multisort', $args);

		return $retval;
	}

	/**
	 * Loads variables from the database.
	 *
	 * @param int $pageId The page ID to load.
	 * @param MetaTemplateSet $set The set to load.
	 *
	 * @return void
	 */
	public function loadSetFromPage(int $pageId, MetaTemplateSet &$set, PPFrame $frame = null): void
	{
		[$tables, $fields, $options, $joinConds] = self::baseQuery();
		$conds = [
			self::SET_PAGE_ID => $pageId,
			self::SET_SET_NAME => $set->name ?? ''
		];

		if (count($set->variables)) {
			$conds[self::DATA_VAR_NAME] = array_keys($set->variables);
		}

		#RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
		if ($result) {
			for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
				// Because the results are sorted by revId, any duplicate variables caused by an update in mid-select
				// will overwrite the older values.
				$varValue = $row[self::FIELD_VAR_VALUE];
				$set->variables[$row[self::FIELD_VAR_NAME]] = $varValue;
			}
		}
	}

	/**
	 * Loads variables from the database.
	 *
	 * @param int $pageId The page ID to load.
	 * @param string $setName The set to load.
	 *
	 * @return MetaTemplateSet[] The sets found with the requested name.
	 */
	public function loadSetsFromPage(int $pageId, array $varNames = null, PPFrame $frame = null): array
	{
		[$tables, $fields, $options, $joinConds] = self::baseQuery();
		$fields[] = self::SET_SET_NAME;
		$conds = [self::SET_PAGE_ID => $pageId];
		if ($varNames && count($varNames)) {
			$conds[self::DATA_VAR_NAME] = $varNames;
		}

		// We don't have page info yet, so this doesn't make sense to be put into a MetaTemplatePage object here.
		// Instead, we stick to an array of MetaTemplateSet objects.
		$sets = [];
		#RHshow('Query', $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
		if ($result) {
			for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
				$setName = $row[self::FIELD_SET_NAME];
				if (isset($sets[$setName])) {
					$set = $sets[$setName];
				} else {
					$set = new MetaTemplateSet($setName);
					$sets[] = $set;
				}

				$varValue = $row[self::FIELD_VAR_VALUE];
				$set->variables[$row[self::FIELD_VAR_NAME]] = $varValue;
			}
		}

		return $sets;
	}

	/**
	 * Migrates the MetaTemplate 1.0 data table to the current version.
	 *
	 * @param DatabaseUpdater $updater
	 * @param string $dir
	 *
	 * @return void
	 */
	public function migrateDataTable(DatabaseUpdater $updater, string $dir): void
	{
		$db = $updater->getDB();
		if (!$db->tableExists(self::OLDTABLE_DATA)) {
			$updater->addExtensionTable(self::TABLE_DATA, "$dir/sql/create-" . self::TABLE_SET . '.sql');
			$updater->addExtensionUpdate([$this, 'migrateSet']);
		}
	}

	/**
	 * Migrates the MetaTemplate 1.0 set table to the current version.
	 *
	 * @param DatabaseUpdater $updater
	 * @param string $dir
	 *
	 * @return void
	 */
	public function migrateSetTable(DatabaseUpdater $updater, string $dir): void
	{
		$db = $this->dbWrite;
		if (!$db->tableExists(self::OLDTABLE_SET)) {
			$updater->addExtensionTable(self::TABLE_SET, "$dir/sql/create-" . self::TABLE_SET . '.sql');
			$updater->addExtensionUpdate([[$this, 'migrateSet']]);
		}
	}

	public function pagerQuery(int $pageId): array
	{
		[$tables, $fields, $options, $joinConds] = self::baseQuery(self::SET_SET_NAME);
		$conds = [self::SET_PAGE_ID => $pageId];

		// Transactions should make sure this never happens, but in the event that we got more than one rev_id back,
		// ensure that we start with the lowest first, so data is overridden by the most recent values once we get
		// there, but lower values will exist if the write is incomplete.

		#RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds))
		return [
			'tables' => $tables,
			'fields' => $fields,
			'conds' => $conds,
			'options' => $options,
			'join_conds' => $joinConds
		];
	}

	/**
	 * Saves all upserts and purges any dependent pages.
	 *
	 * @param Title $title The title where the data is being saved.
	 * @param MetaTemplateSetCollection $vars The sets to be saved.
	 *
	 * @return bool True if any updates were made.
	 */
	public function saveVars(MetaTemplateSetCollection $vars): bool
	{
		#RHecho('Vars', $vars);
		// Whether or not the data changed, the page has been evaluated, so add it to the list.
		$title = $vars->title;
		self::$pagesPurged[$title->getArticleID()] = true;
		$oldData = $this->loadPageVariables($title);
		$upserts = new MetaTemplateUpserts($oldData, $vars);
		if ($upserts->getTotal() > 0) {
			#RHwriteFile('Normal Save: ', $title->getFullText());
			$this->saveUpserts($upserts);
			return true;
		}

		return false;
	}

	/**
	 * Indicates whether the tables needed for MetaTemplate's data features exist.
	 *
	 * @return bool Whether both tables exist.
	 */
	public function tablesExist(): bool
	{
		return
			$this->dbRead->tableExists(self::TABLE_SET) &&
			$this->dbRead->tableExists(self::TABLE_DATA);
	}
	#endregion

	#region Private Static Functions
	/**
	 * Returns the basic query arrays for most MetaTemplate queries.
	 *
	 * @param string[] ...$addFields
	 *
	 * @return array An array containing the basic elements for building Metatemplate-related queries
	 */
	private static function baseQuery(...$addFields)
	{
		$tables = [
			self::TABLE_SET,
			self::TABLE_DATA
		];

		$fields = array_merge($addFields, [
			self::DATA_VAR_NAME,
			self::DATA_VAR_VALUE
		]);

		$options = ['ORDER BY' => [
			self::FIELD_PAGE_ID,
			self::FIELD_SET_NAME,
			self::FIELD_REV_ID
		]];

		$joinConds = [
			self::TABLE_DATA =>
			['JOIN', [self::SET_SET_ID . '=' . self::DATA_SET_ID]]
		];

		#RHecho("Tables:\n", $tables, "\n\nFields:\n", $fields, "\n\nOptions:\n", $options, "\n\nJoinConds:\n", $joinConds);
		return [$tables, $fields, $options, $joinConds];
	}
	#endregion

	#region Private Functions
	/**
	 * Loads variables for a specific page.
	 *
	 * @param Title $title The title to load.
	 *
	 * @return MetaTemplateSetCollection
	 */
	private function loadPageVariables(Title $title): ?MetaTemplateSetCollection
	{
		// Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
		// data is tracked with $deleteIds.

		// logFunctionText("($pageId)");
		$pageId = $title->getArticleID();
		[$tables, $fields, $options, $joinConds] = self::baseQuery(
			self::SET_SET_ID,
			self::SET_SET_NAME,
			self::SET_REV_ID
		);
		$conds = [self::SET_PAGE_ID => $pageId];
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
		$row = $this->dbRead->fetchRow($result);
		if (!$row) {
			return null;
		}

		$retval = new MetaTemplateSetCollection($title, $row[self::FIELD_REV_ID]);
		while ($row) {
			$set =  $retval->addToSet($row[self::FIELD_SET_ID], $row[self::FIELD_SET_NAME]);
			$varName = $row[self::FIELD_VAR_NAME];
			$varValue = $row[self::FIELD_VAR_VALUE]; // Don't need to parse this, as we're only looking at the raw data here.
			$set->variables[$varName] = $varValue;
			$row = $this->dbRead->fetchRow($result);
		}

		return $retval;
	}

	/**
	 * @todo See how much of this can be converted to bulk updates. Even though MW internally wraps most of these in a
	 * transaction (supposedly...unverified), speed could probably still be improved with bulk updates.
	 *
	 * Alters the database in whatever ways are necessary to update one revision's variables to the next.
	 *
	 * @param MetaTemplateUpserts $upserts
	 *
	 * @return void
	 */
	private function saveUpserts(MetaTemplateUpserts $upserts): void
	{
		$deletes = $upserts->deletes;
		// writeFile('  Deletes: ', count($deletes));
		if (count($deletes)) {
			// Assumes cascading is in effect, so doesn't delete TABLE_DATA entries.
			$this->dbWrite->delete(self::TABLE_SET, [self::FIELD_SET_ID => $deletes]);
		}

		$pageId = $upserts->pageId;
		$newRevId = $upserts->newRevId;
		// writeFile('  Inserts: ', count($inserts));
		foreach ($upserts->inserts as $newSet) {
			$record = [
				self::FIELD_PAGE_ID => $pageId,
				self::FIELD_SET_NAME => $newSet->name,
				self::FIELD_REV_ID => $newRevId
			];
			#RHshow('Insert', $record);
			$this->dbWrite->insert(self::TABLE_SET, $record);

			$setId = $this->dbWrite->insertId();
			$this->insertData($setId, $newSet);
		}

		// writeFile('  Updates: ', count($updates));
		if (count($upserts->updates)) {
			foreach ($upserts->updates as $setId => $setData) {
				/**
				 * @var MetaTemplateSet $oldSet
				 * @var MetaTemplateSet $newSet
				 */
				list($oldSet, $newSet) = $setData;
				$this->updateSetData($setId, $oldSet, $newSet);
			}

			if ($upserts->oldRevId < $newRevId) {
				$this->dbWrite->update(
					self::TABLE_SET,
					[self::FIELD_REV_ID => $newRevId],
					[self::FIELD_SET_ID => $setId]
				);
			}
		}
	}

	/**
	 * Alters the database in whatever ways are necessary to update one revision's sets to the next.
	 *
	 * @param mixed $setId The set ID # from the mtSaveSet table.
	 * @param MetaTemplateSet $oldSet The previous revision's set data.
	 * @param MetaTemplateSet $newSet The current revision's set data.
	 *
	 * @return void
	 */
	private function updateSetData($setId, MetaTemplateSet $oldSet, MetaTemplateSet $newSet): void
	{
		#RHecho('Update Set Data');
		$oldVars = &$oldSet->variables;
		$newVars = $newSet->variables;
		$deletes = [];
		foreach ($oldVars as $varName => $oldValue) {
			if (isset($newVars[$varName])) {
				$newValue = $newVars[$varName];
				#RHwriteFile($oldVars[$varName]);
				if ($oldValue != $newValue) {
					#RHwriteFile("Updating $varName from {$oldValue->value} to {$newValue->value}");
					// Makes the assumption that most of the time, only a few columns are being updated, so does not
					// attempt to batch the operation in any way.
					$this->dbWrite->update(
						self::TABLE_DATA,
						[
							self::FIELD_VAR_VALUE => $newValue
						],
						[
							self::FIELD_SET_ID => $setId,
							self::FIELD_VAR_NAME => $varName
						]
					);
				}

				unset($newVars[$varName]);
			} else {
				$deletes[] = $varName;
			}
		}

		if (count($newVars)) {
			$this->insertData($setId, new MetaTemplateSet($newSet->name, $newVars));
		}

		if (count($deletes)) {
			$this->dbWrite->delete(self::TABLE_DATA, [
				self::FIELD_SET_ID => $setId,
				self::FIELD_VAR_NAME => $deletes
			]);
		}
	}
	#endregion
}
