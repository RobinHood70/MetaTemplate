<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Handles all SQL-related functions for MetaTemplate.
 */
class MetaTemplateSql
{
    public const DATA_TABLE = 'mtSaveData';
    public const SET_TABLE = 'mtSaveSet';

    public const FIELD_PAGE_ID = 'pageId';
    public const FIELD_PARSE_ON_LOAD = 'parseOnLoad';
    public const FIELD_REV_ID = 'revId';
    public const FIELD_SET_ID = 'setId';
    public const FIELD_SET_NAME = 'setName';
    public const FIELD_VAR_NAME = 'varName';
    public const FIELD_VAR_VALUE = 'varValue';

    public const DATA_PARSE_ON_LOAD = self::DATA_TABLE . '.' . self::FIELD_PARSE_ON_LOAD;
    public const DATA_SET_ID = self::DATA_TABLE . '.' . self::FIELD_SET_ID;
    public const DATA_VAR_NAME = self::DATA_TABLE . '.' . self::FIELD_VAR_NAME;
    public const DATA_VAR_VALUE = self::DATA_TABLE . '.' . self::FIELD_VAR_VALUE;

    public const SET_PAGE_ID = self::SET_TABLE . '.' . self::FIELD_PAGE_ID;
    public const SET_REV_ID = self::SET_TABLE . '.' . self::FIELD_REV_ID;
    public const SET_SET_ID = self::SET_TABLE . '.' . self::FIELD_SET_ID;
    public const SET_SET_NAME = self::SET_TABLE . '.' . self::FIELD_SET_NAME;

    private const OLDSET_TABLE = 'mt_save_set';
    private const OLDDATA_TABLE = 'mt_save_data';

    /** @var MetaTemplateSql */
    private static $instance;

    /** @var IDatabase */
    private $dbRead;

    /** @var IDatabase */
    private $dbWrite;

    /**
     * A list of all the pages purged during this session to avoid looping.
     *
     * @var array
     *
     */
    private static $pagesPurged = [];

    /**
     * Creates an instance of the MetaTemplateSql class.
     *
     */
    private function __construct()
    {
        $dbWriteConst = defined('DB_PRIMARY') ? 'DB_PRIMARY' : 'DB_MASTER';
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $this->dbRead = $lb->getConnectionRef(constant($dbWriteConst));

        // We get dbWrite lazily since writing will often be unnecessary.
        $this->dbWrite = $lb->getLazyConnectionRef(constant($dbWriteConst));
    }

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

    /**
     * [Description for catQuery]
     *
     * @param MetaTemplatePage[] $pageIds
     * @param string[] $varNames
     *
     * @return void
     *
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
            $set->variables[$row[self::FIELD_VAR_NAME]] = new MetaTemplateVariable(
                $row[self::FIELD_VAR_VALUE],
                $row[self::FIELD_PARSE_ON_LOAD]
            );
        }
    }

    /**
     * Handles data to be deleted.
     *
     * @param Title $title The title of the page to delete from.
     *
     * @return void
     *
     */
    public function deleteVariables(Title $title): void
    {
        $pageId = $title->getArticleID();

        // Assumes cascading is in effect to delete DATA_TABLE rows.
        $this->dbWrite->delete(self::SET_TABLE, [self::FIELD_PAGE_ID => $pageId]);
        self::$pagesPurged[$pageId] = true;
        $this->recursiveInvalidateCache($title);
    }

    public function getNamespaces(): IResultWrapper
    {
        /** @todo Change to all namespaces. Leaving as is for now only to get things up and running before changing anything. */
        $tables = [self::SET_TABLE, 'page'];
        $fields = ['DISTINCT page_namespace'];
        $options = ['ORDER BY' => 'page_namespace'];
        $joinConds = [self::SET_TABLE => ['INNER JOIN', self::SET_PAGE_ID . '=' . 'page.page_id']];

        return $this->dbRead->select($tables, $fields, '', __METHOD__, $options, $joinConds);
    }

    /**
     * Gets a list of the most-used Metatemplate variables.
     *
     * @param int $limit The number of variables to get.
     *
     * @return ?IResultWrapper
     *
     */
    public function getPopularVariables(int $limit): ?IResultWrapper
    {
        $table = MetaTemplateSql::DATA_TABLE;
        $fields = [MetaTemplateSql::DATA_VAR_NAME];
        $conds = MetaTemplateSql::DATA_VAR_NAME . ' NOT LIKE \'@%\'';
        $options = [
            'GROUP BY' => MetaTemplateSql::DATA_VAR_NAME,
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

    public function getPageswWithMetaVarsQueryInfo(?string $nsNum, ?string $setName, ?string $varName): array
    {
        $tables = [MetaTemplateSql::SET_TABLE, 'page'];
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
        $joinConds = [self::SET_TABLE => ['INNER JOIN', 'page.page_id = ' . self::SET_PAGE_ID]];
        $setName = $setName ?? '*';
        if ($setName === '*' || isset($varName)) {
            $tables[] = MetaTemplateSql::DATA_TABLE;
            $fields[] = MetaTemplateSql::DATA_VAR_NAME;
            $fields[] = MetaTemplateSql::DATA_VAR_VALUE;
            $conds[self::DATA_VAR_NAME] =  $varName;
            $joinConds[MetaTemplateSql::DATA_TABLE] = ['INNER JOIN', self::SET_SET_ID . ' = ' . self::DATA_SET_ID];
        }

        if ($setName !== '*') {
            $conds[self::SET_SET_NAME] = $setName;
        }

        if ($nsNum !== null && $nsNum !== 'all') {
            $conds['page_namespace'] = $nsNum;
        }

        return [
            'tables' => $tables,
            'fields' => $fields,
            'conds' => $conds,
            'join_conds' => $joinConds
        ];
    }

    /**
     * Creates the query to load variables from the database.
     *
     * @param int $pageId The page ID to load.
     * @param MetaTemplateSet $setName The set name to load. If null, loads all sets.
     * @param array $varNames A filter of which variable names should be returned.
     *
     * @return array Array of tables, fields, conditions, options, and join conditions for a query, mirroring the
     *               parameters to IDatabase->select.
     */ public function loadQuery(int $pageId, MetaTemplateSet $set): ?array
    {
        [$tables, $fields, $options, $joinConds] = self::baseQuery();
        $conds[self::SET_PAGE_ID] = $pageId;
        if (isset($set->setName)) {
            $conds[self::SET_SET_NAME] = $set->setName;
        } else {
            $fields[] = self::SET_SET_NAME;
        }

        if (count($set->variables)) {
            $conds[self::DATA_VAR_NAME] = array_keys($set->variables);
        }

        #RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
        return [
            $tables,
            $fields,
            $conds,
            $options,
            $joinConds
        ];
    }

    /**
     * Handles data to be inserted.
     *
     * @param mixed $setId The set ID to insert.
     * @param MetaTemplateSet $newSet The set to insert.
     *
     * @return void
     *
     */
    public function insertData($setId, MetaTemplateSet $newSet): void
    {
        $data = [];
        foreach ($newSet->variables as $key => $var) {
            $data[] = [
                self::FIELD_SET_ID => $setId,
                self::FIELD_VAR_NAME => $key,
                self::FIELD_VAR_VALUE => $var->value,
                self::FIELD_PARSE_ON_LOAD => $var->parseOnLoad
            ];
        }

        $this->dbWrite->insert(self::DATA_TABLE, $data);
    }

    /**
     * [Description for loadListSavedData]
     *
     * @param int $namespace The integer namespace.
     * @param array $conditions An array of key=>value strings to use for query conditions.
     * @param MetaTemplateSet[] $sets The array sets and names to be loaded.
     *
     * @return MetaTemplatePage[] An array of pages indexed by Page ID.
     *
     */
    public function loadListSavedData(int $namespace, array $conditions, array $sets): array
    {
        if (!count($sets)) {
            return [];
        }

        [$tables, $fields, $options, $joinConds] = self::baseQuery(
            'page.page_title',
            'page.page_namespace',
            self::SET_PAGE_ID,
            self::SET_SET_ID,
            self::SET_REV_ID,
            self::SET_SET_NAME
        );

        $tables[] = 'page';
        $baseJoinConds[self::SET_TABLE] = ['JOIN', ['page.page_id=' . self::SET_PAGE_ID]];
        if ($namespace >= 0) {
            $conds['page.page_namespace'] = $namespace;
        }

        $data = 1;
        foreach ($conditions as $key => $value) {
            $dataName = 'data' . $data;
            $tables[$dataName] = self::DATA_TABLE;
            $baseJoinConds[$dataName] = ['JOIN', [self::SET_SET_ID . '=' . $dataName . '.setId']];
            $conds[$dataName . '.' . self::FIELD_VAR_NAME] = $key;
            $conds[$dataName . '.' . self::FIELD_VAR_VALUE] = $value;
            ++$data;
        }

        /** @var MetaTemplatePage[] $retval */
        $queries = [];
        foreach ($sets as $set) {
            if (!count($set->variables)) {
                continue;
            }

            $varNames = array_keys($set->variables);
            $conds[self::SET_SET_NAME] = $set->setName;
            $joinConds = $baseJoinConds + [self::DATA_TABLE => ['LEFT JOIN', [self::SET_SET_ID . '=' . self::DATA_SET_ID, self::DATA_VAR_NAME => $varNames]]];
            #RHshow('Tables', $tables, "\n\nFields: ", $fields, "\n\nConds: ", $conds, "\n\nOptions: ", $options, "\n\nJoin Conds: ", $joinConds);
            $queries[] = $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, [], $joinConds);
        }

        // Remove the last query and replace it with the same query plus the ORDER BY clause, which sorts the entire union at once.
        array_pop($queries);
        $queries[] = $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds);

        // unionQueries() only supports parenthesized unions, while this should use unparenthesized unions, so we do it manually.
        $union = implode(" UNION ", $queries);
        #RHecho($union);
        $rows = $this->dbRead->query($union);
        #RHecho($union, "\n{$rows->numRows()} rows found.");

        $retval = [];
        for ($row = $rows->fetchRow(); $row; $row = $rows->fetchRow()) {
            $pageId = $row['pageId'];
            if (isset($retval[$pageId])) {
                $page = $retval[$pageId];
            } else {
                $page = new MetaTemplatePage($row['page_namespace'], $row['page_title']);
                $retval[$pageId] = $page;
            }

            if (isset($row[self::FIELD_VAR_VALUE])) {
                $page->addToSet($row[self::FIELD_SET_NAME], [$row[self::FIELD_VAR_NAME] => new MetaTemplateVariable($row[self::FIELD_VAR_VALUE], $row[self::FIELD_PARSE_ON_LOAD])]);
            }
        }

        #RHshow('#listsaved Results', $retval);
        return $retval;
    }

    /**
     * Loads variables for a specific page.
     *
     * @param mixed $pageId The page ID to load.
     *
     * @return MetaTemplateSetCollection
     */
    public function loadPageVariables($pageId): ?MetaTemplateSetCollection
    {
        // Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
        // data is tracked with $deleteIds.

        // logFunctionText("($pageId)");
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

        $retval = new MetaTemplateSetCollection($pageId, $row[self::FIELD_REV_ID]);
        while ($row) {
            $set =  $retval->addToSet($row[self::FIELD_SET_ID], $row[self::FIELD_SET_NAME]);
            $set->variables[$row[self::FIELD_VAR_NAME]] = new MetaTemplateVariable(
                $row[self::FIELD_VAR_VALUE],
                $row[self::FIELD_PARSE_ON_LOAD]
            );
            $row = $this->dbRead->fetchRow($result);
        }

        return $retval;
    }

    /**
     * Loads variables from the database.
     *
     * @param mixed $pageId The page ID to load.
     * @param MetaTemplateSet $set The set to load.
     *
     * @return bool True if data was loaded.
     */
    public function loadSetFromDb($pageId, MetaTemplateSet &$set): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        [$tables, $fields, $conds, $options, $joinConds] = $this->loadQuery($pageId, $set);
        #RHshow('Method', $method, "\nTables: ", $tables, "\nFields: ", $fields, "\nConditions: ", $conds, "\nOptions: ", $options, "\nJoin Conds: ", $joinConds);
        #RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds));
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
        if (!$result || !$result->numRows()) {
            return false;
        }

        $retval = false;
        for ($row = $result->fetchRow(); $row; $result->fetchRow()) {
            // Because the results are sorted by revId, any duplicate variables caused by an update in mid-select
            // will overwrite the older values.
            $var = new MetaTemplateVariable($row[self::FIELD_VAR_VALUE], $row[self::FIELD_PARSE_ON_LOAD]);
            $set->variables[$row[self::FIELD_VAR_NAME]] = $var;
            $row = $result->fetchRow();
            $retval = true;
        }

        return $retval;
    }

    /**
     * Loads variables from the database.
     *
     * @param mixed $pageId The page ID to get the preload data from.
     *
     * @return MetaTemplateSet[] An array containing any proload variables that were found.
     */
    public function getPreloadInfo(array &$sets, $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        $pseudoSet = new MetaTemplateSet(null, [MetaTemplateData::KEY_PRELOAD_DATA => false], false);
        [$tables, $fields, $conds, $options, $joinConds] = $this->loadQuery($pageId, $pseudoSet);
        #RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds));
        $result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
        if (!$result || !$result->numRows()) {
            return;
        }

        for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
            $setName = $row[self::FIELD_SET_NAME];
            if (isset($sets[$setName])) {
                $set = &$sets[$setName];
            } else {
                $set = new MetaTemplateSet($setName, [], false);
                $sets[$setName] = $set;
            }

            $varNames = explode(MetaTemplateData::PRELOAD_SEP, $row[self::FIELD_VAR_VALUE]);
            foreach ($varNames as $varName) {
                $set->variables[$varName] = false;
            }
        }
    }

    /**
     * Moves variables from one page ID to another during a page move.
     *
     * @param int $oldid The original page ID.
     * @param int $newid The new page ID.
     *
     * @return void
     *
     */
    public function moveVariables(int $oldid, int $newid): void
    {
        $this->dbRead->update(
            self::SET_TABLE,
            [self::FIELD_PAGE_ID => $newid],
            [self::FIELD_PAGE_ID => $oldid]
        );
    }

    /**
     * Migrates the MetaTemplate 1.0 data table to the current version.
     *
     * @param DatabaseUpdater $updater
     * @param string $dir
     *
     * @return void
     *
     */
    public function migrateDataTable(DatabaseUpdater $updater, string $dir): void
    {
        $db = $updater->getDB();
        if (!$db->tableExists(self::OLDDATA_TABLE)) {
            $updater->addExtensionTable(MetaTemplateSql::DATA_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
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
     *
     */
    public function migrateSetTable(DatabaseUpdater $updater, string $dir): void
    {
        $db = $this->dbWrite;
        if (!$db->tableExists(self::OLDSET_TABLE)) {
            $updater->addExtensionTable(MetaTemplateSql::SET_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
            $updater->addExtensionUpdate([[$this, 'migrateSet']]);
        }
    }

    // Initial table setup/modifications from v1.
    /**
     * Migrates the old MetaTemplate tables to new ones. The basic functionality is the same, but names and indeces
     * have been altered and the datestamp removed.
     *
     * @param DatabaseUpdater $updater
     *
     * @return void
     *
     */
    public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater): void
    {
        /** @var string $dir  */
        $dir = dirname(__DIR__);
        if (MetaTemplate::can(MetaTemplate::STTNG_ENABLEDATA)) {
            $db = $updater->getDB();
            if (!$db->tableExists(MetaTemplateSql::SET_TABLE)) {
                $updater->addExtensionTable(MetaTemplateSql::SET_TABLE, "$dir/sql/create-" . MetaTemplateSql::SET_TABLE . '.sql');
            }

            $instance = self::getInstance();
            $updater->addExtensionUpdate([[$instance, 'migrateSetTable'], $dir]);

            if (!$db->tableExists(MetaTemplateSql::DATA_TABLE)) {
                $updater->addExtensionTable(MetaTemplateSql::DATA_TABLE, "$dir/sql/create-" . MetaTemplateSql::DATA_TABLE . '.sql');
            }

            $updater->addExtensionUpdate([[$instance, 'migrateDataTable'], $dir]);
        }
    }

    public function pageIdLimiter(int $id): array
    {
        return [MetaTemplateSql::SET_PAGE_ID => $id];
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
     * Does a simple purge on all direct backlinks from a page. Needs tested to see if this should be used in the long run. Might be too server intensive, but
     *
     * @param Title $title
     *
     * @return void
     *
     */
    public function recursiveInvalidateCache(Title $title): void
    {
        // Note: this is recursive only in the sense that it will cause page re-evaluation, which will, in turn, cause
        // their dependents to be re-evaluated. This should not be left in-place in the final product, as it's very
        // server-intensive. (Is it, though? Test on large job on dev.) Instead, call the cache's enqueue jobs method
        // to put things on the queue or possibly just send this page to be purged with forcerecursivelinksupdate.

        #RHwriteFile('Recursive Invalidate');
        $templateLinks = 'templatelinks';
        $linkIds = [];
        foreach ($title->getBacklinkCache()->getLinks($templateLinks) as $link) {
            $linkIds[] = $link->getArticleID();
        }

        if (!count($linkIds)) {
            return;
        }

        $result = $this->dbRead->select(
            self::SET_TABLE,
            [self::FIELD_PAGE_ID],
            [self::FIELD_PAGE_ID => $linkIds],
            __METHOD__
        );

        $recursiveIds = [];
        for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
            $recursiveIds[] = $row[self::FIELD_PAGE_ID];
        }

        foreach ($linkIds as $linkId) {
            if (!isset(self::$pagesPurged[$linkId])) {
                self::$pagesPurged[$linkId] = true;
                $title = Title::newFromID($linkId);
                if (isset($recursiveIds[$linkId])) {
                    $prefText = $title->getPrefixedText();
                    $job = new RefreshLinksJob(
                        $title,
                        [
                            'table' => $templateLinks,
                            'recursive' => true,
                        ] + Job::newRootJobParams("refreshlinks:$templateLinks:$prefText")
                    );

                    JobQueueGroup::singleton()->push($job);
                } else {
                    $page = WikiPage::factory($title);
                    $page->doPurge();
                }
            }
        }

        #RHwriteFile('End Recursive Update');
    }

    /**
     * Saves variables to the database.
     *
     * @param Parser $parser The parser in use.
     *
     * @return void
     *
     */
    public function saveVariables(Parser $parser): void
    {
        // This algorithm is based on the assumption that data is rarely changed, therefore:
        // * It's best to read the existing DB data before making any DB updates/inserts.
        // * Chances are that we're going to need to read all the data for this save set, so best to read it all at
        //   once instead of individually or by set.
        // * It's best to use the read-only DB until we know we need to write.

        $title = $parser->getTitle();
        $output = $parser->getOutput();
        /** @var MetaTemplateSetCollection $vars */
        $vars = $output->getExtensionData(MetaTemplateData::KEY_SAVE);
        if (!$parser->getRevisionId()) {
            return;
        }

        #RHwriteFile("Saving:\n", $vars);
        // MetaTemplateData::setPageVariables($output, null);
        if (!$vars || empty($vars->sets)) {
            #RHwriteFile('Empty Vars: ', $title->getFullText());
            // If there are no variables on the page at all, check if there were to begin with. If so, delete them.
            if ($this->loadPageVariables($title->getArticleID())) {
                #RHwriteFile('Delete Vars: ', $title->getFullText());
                $this->deleteVariables($title);
            }
        } else if ($vars->revId === -1) {
            // The above check will only be satisfied on Template-space pages that use #save.
            #RHwriteFile('Save Template: ', $title->getFullText());
            $this->recursiveInvalidateCache($title);
        } else {
            $pageId = $title->getArticleID();

            // Whether or not the data changed, the page has been evaluated, so add it to the list.
            self::$pagesPurged[$pageId] = true;
            $oldData = $this->loadPageVariables($title->getArticleID());
            $upserts = new MetaTemplateUpserts($oldData, $vars);
            if ($upserts->getTotal() > 0) {
                #RHwriteFile('Normal Save: ', $title->getFullText());
                $this->saveUpserts($upserts);
                $this->recursiveInvalidateCache($title);
            }
        }

        $output->setExtensionData(MetaTemplateData::KEY_SAVE, null);
    }

    /**
     * Indicates whether the tables needed for MetaTemplate's data features exist.
     *
     * @return bool Whether both tables exist.
     */
    public function tablesExist(): bool
    {
        return
            $this->dbRead->tableExists(self::SET_TABLE) &&
            $this->dbRead->tableExists(self::DATA_TABLE);
    }

    /**
     * Returns the basic query arrays for most MetaTemplate queries.
     *
     * @param string[] ...$addFields
     *
     * @return array An array containing the basic elements for building Metatemplate-related queries
     *
     */
    private static function baseQuery(...$addFields)
    {
        $tables = [
            self::SET_TABLE,
            self::DATA_TABLE
        ];

        $fields = array_merge($addFields, [
            self::DATA_VAR_NAME,
            self::DATA_VAR_VALUE,
            self::DATA_PARSE_ON_LOAD
        ]);

        $options = ['ORDER BY' => [
            self::FIELD_PAGE_ID,
            self::FIELD_SET_NAME,
            self::FIELD_REV_ID
        ]];

        $joinConds = [
            self::DATA_TABLE =>
            ['JOIN', [self::SET_SET_ID . '=' . self::DATA_SET_ID]]
        ];

        #RHecho("Tables:\n", $tables, "\n\nFields:\n", $fields, "\n\nOptions:\n", $options, "\n\nJoinConds:\n", $joinConds);
        return [$tables, $fields, $options, $joinConds];
    }

    /**
     * Alters the database in whatever ways are necessary to update one revision's variables to the next.
     *
     * @param MetaTemplateUpserts $upserts
     *
     * @return void
     *
     */
    private function saveUpserts(MetaTemplateUpserts $upserts): void
    {
        $deletes = $upserts->deletes;
        // writeFile('  Deletes: ', count($deletes));
        if (count($deletes)) {
            // Assumes cascading is in effect, so doesn't delete DATA_TABLE entries.
            $this->dbWrite->delete(self::SET_TABLE, [self::FIELD_SET_ID => $deletes]);
        }

        $pageId = $upserts->pageId;
        $newRevId = $upserts->newRevId;
        // writeFile('  Inserts: ', count($inserts));
        foreach ($upserts->inserts as $newSet) {
            $this->dbWrite->insert(self::SET_TABLE, [
                self::FIELD_PAGE_ID => $pageId,
                self::FIELD_SET_NAME => $newSet->setName,
                self::FIELD_REV_ID => $newRevId
            ]);

            $setId = $this->dbWrite->insertId();
            $this->insertData($setId, $newSet);
        }

        $updates = $upserts->updates;
        // writeFile('  Updates: ', count($updates));
        if (count($updates)) {
            foreach ($updates as $setId => $setData) {
                /**
                 * @var MetaTemplateSet $oldSet
                 * @var MetaTemplateSet $newSet
                 */
                list($oldSet, $newSet) = $setData;
                $this->updateSetData($setId, $oldSet, $newSet);
            }

            if ($upserts->oldRevId < $newRevId) {
                $this->dbWrite->update(
                    self::SET_TABLE,
                    [self::FIELD_REV_ID => $newRevId],
                    [
                        // setId uniquely identifies the set, but setName and pageId are part of the primary key, so we
                        // add them here for better indexing.
                        self::FIELD_PAGE_ID => $upserts->pageId,
                        self::FIELD_SET_NAME => $oldSet->setName,
                        self::FIELD_SET_ID => $setId
                    ]
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
     *
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
                        self::DATA_TABLE,
                        [
                            self::FIELD_VAR_VALUE => $newValue->value,
                            self::FIELD_PARSE_ON_LOAD => $newValue->parseOnLoad
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
            $this->insertData($setId, new MetaTemplateSet($newSet->setName, $newVars));
        }

        if (count($deletes)) {
            $this->dbWrite->delete(self::DATA_TABLE, [
                self::FIELD_SET_ID => $setId,
                self::FIELD_VAR_NAME => $deletes
            ]);
        }
    }
}
