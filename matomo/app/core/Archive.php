<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik;

use Piwik\Archive\ArchiveQuery;
use Piwik\Archive\ArchiveQueryFactory;
use Piwik\Archive\ArchiveState;
use Piwik\Archive\DataCollection;
use Piwik\Archive\Parameters;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Container\StaticContainer;
use Piwik\DataAccess\ArchiveSelector;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\Plugins\CoreAdminHome\API;
/**
 * The **Archive** class is used to query cached analytics statistics
 * (termed "archive data").
 *
 * You can use **Archive** instances to get data that was archived for one or more sites,
 * for one or more periods and one optional segment.
 *
 * If archive data is not found, this class will initiate the archiving process. [1](#footnote-1)
 *
 * **Archive** instances must be created using the {@link build()} factory method;
 * they cannot be constructed.
 *
 * You can search for metrics (such as `nb_visits`) using the {@link getNumeric()} and
 * {@link getDataTableFromNumeric()} methods. You can search for
 * reports using the {@link getBlob()}, {@link getDataTable()} and {@link getDataTableExpanded()} methods.
 *
 * If you're creating an API that returns report data, you may want to use the
 * {@link createDataTableFromArchive()} helper function.
 *
 * ### Learn more
 *
 * Learn more about _archiving_ [here](/guides/all-about-analytics-data).
 *
 * ### Limitations
 *
 * - You cannot get data for multiple range periods in a single query.
 * - You cannot get data for periods of different types in a single query.
 *
 * ### Examples
 *
 * **_Querying metrics for an API method_**
 *
 *     // one site and one period
 *     $archive = Archive::build($idSite = 1, $period = 'week', $date = '2013-03-08');
 *     return $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 *
 *     // all sites and multiple dates
 *     $archive = Archive::build($idSite = 'all', $period = 'month', $date = '2013-01-02,2013-03-08');
 *     return $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 *
 * **_Querying and using metrics immediately_**
 *
 *     // one site and one period
 *     $archive = Archive::build($idSite = 1, $period = 'week', $date = '2013-03-08');
 *     $data = $archive->getNumeric(array('nb_visits', 'nb_actions'));
 *
 *     $visits = $data['nb_visits'];
 *     $actions = $data['nb_actions'];
 *
 *     // ... do something w/ metric data ...
 *
 *     // multiple sites and multiple dates
 *     $archive = Archive::build($idSite = '1,2,3', $period = 'month', $date = '2013-01-02,2013-03-08');
 *     $data = $archive->getNumeric('nb_visits');
 *
 *     $janSite1Visits = $data['1']['2013-01-01,2013-01-31']['nb_visits'];
 *     $febSite1Visits = $data['1']['2013-02-01,2013-02-28']['nb_visits'];
 *     // ... etc.
 *
 * **_Querying for reports_**
 *
 *     $archive = Archive::build($idSite = 1, $period = 'week', $date = '2013-03-08');
 *     $dataTable = $archive->getDataTable('MyPlugin_MyReport');
 *     // ... manipulate $dataTable ...
 *     return $dataTable;
 *
 * **_Querying a report for an API method_**
 *
 *     public function getMyReport($idSite, $period, $date, $segment = false, $expanded = false)
 *     {
 *         $dataTable = Archive::createDataTableFromArchive('MyPlugin_MyReport', $idSite, $period, $date, $segment, $expanded);
 *         return $dataTable;
 *     }
 *
 * **_Querying data for multiple range periods_**
 *
 *     // get data for first range
 *     $archive = Archive::build($idSite = 1, $period = 'range', $date = '2013-03-08,2013-03-12');
 *     $dataTable = $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 *
 *     // get data for second range
 *     $archive = Archive::build($idSite = 1, $period = 'range', $date = '2013-03-15,2013-03-20');
 *     $dataTable = $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 *
 * <a name="footnote-1"></a>
 * [1]: The archiving process will not be launched if browser archiving is disabled
 *      and the current request came from a browser.
 *
 *
 * @api
 */
class Archive implements ArchiveQuery
{
    public const REQUEST_ALL_WEBSITES_FLAG = 'all';
    public const ARCHIVE_ALL_PLUGINS_FLAG = 'all';
    public const ID_SUBTABLE_LOAD_ALL_SUBTABLES = 'all';
    /**
     * List of archive IDs for the site, periods and segment we are querying with.
     * Archive IDs are indexed by done flag and period, ie:
     *
     * array(
     *     'done.Referrers' => array(
     *         '2010-01-01' => 1,
     *         '2010-01-02' => 2,
     *     ),
     *     'done.VisitsSummary' => array(
     *         '2010-01-01' => 3,
     *         '2010-01-02' => 4,
     *     ),
     * )
     *
     * or,
     *
     * array(
     *     'done.all' => array(
     *         '2010-01-01' => 1,
     *         '2010-01-02' => 2
     *     )
     * )
     *
     * @var array
     */
    private $idarchives = [];
    /**
     * List of doneFlag values for archive IDs for the site, periods and segment
     * we are querying with. Archive IDs are indexed by site, done flag and period, ie:
     *
     * array(
     *     100 => array(
     *         'done.all' => array(
     *             '2010-01-01' => array(
     *                 100 => 1,
     *                 101 => 4
     *             )
     *         )
     *     )
     *  )
     *
     * @var array
     */
    private $idarchiveStates = [];
    /**
     * If set to true, the result of all get functions (ie, getNumeric, getBlob, etc.)
     * will be indexed by the site ID, even if we're only querying data for one site.
     *
     * @var bool
     */
    private $forceIndexedBySite;
    /**
     * If set to true, the result of all get functions (ie, getNumeric, getBlob, etc.)
     * will be indexed by the period, even if we're only querying data for one period.
     *
     * @var bool
     */
    private $forceIndexedByDate;
    /**
     * @var Parameters
     */
    private $params;
    /**
     * @var \Matomo\Cache\Cache
     */
    private static $cache;
    /**
     * If true, this Archive instance will not launch the archiving process, even if the current request
     * is authorized to.
     *
     * @var bool
     */
    private $forceFetchingWithoutLaunchingArchiving;
    /**
     * @param Parameters $params
     * @param bool $forceIndexedBySite Whether to force index the result of a query by site ID.
     * @param bool $forceIndexedByDate Whether to force index the result of a query by period.
     */
    public function __construct(Parameters $params, $forceIndexedBySite = \false, $forceIndexedByDate = \false)
    {
        $this->params = $params;
        $this->forceIndexedBySite = $forceIndexedBySite;
        $this->forceIndexedByDate = $forceIndexedByDate;
    }
    /**
     * Returns a new Archive instance that will query archive data for the given set of
     * sites and periods, using an optional Segment.
     *
     * This method uses data that is found in query parameters, so the parameters to this
     * function can be string values.
     *
     * If you want to create an Archive instance with an array of Period instances, use
     * {@link Archive::factory()}.
     *
     * @param string|int|array $idSites A single ID (eg, `'1'`), multiple IDs (eg, `'1,2,3'` or `array(1, 2, 3)`),
     *                                  or `'all'`.
     * @param string $period 'day', `'week'`, `'month'`, `'year'` or `'range'`
     * @param Date|string $strDate 'YYYY-MM-DD', magic keywords (ie, 'today'; {@link Date::factory()}
     *                             or date range (ie, 'YYYY-MM-DD,YYYY-MM-DD').
     * @param bool|false|string $segment Segment definition or false if no segment should be used. {@link Piwik\Segment}
     * @param bool|false|string $_restrictSitesToLogin Used only when running as a scheduled task.
     * @return ArchiveQuery
     */
    public static function build($idSites, $period, $strDate, $segment = \false, $_restrictSitesToLogin = \false)
    {
        return StaticContainer::get(ArchiveQueryFactory::class)->build($idSites, $period, $strDate, $segment, $_restrictSitesToLogin);
    }
    /**
     * Returns a new Archive instance that will query archive data for the given set of
     * sites and periods, using an optional segment.
     *
     * This method uses an array of Period instances and a Segment instance, instead of strings
     * like {@link build()}.
     *
     * If you want to create an Archive instance using data found in query parameters,
     * use {@link build()}.
     *
     * @param Segment $segment The segment to use. For no segment, use `new Segment('', $idSites)`.
     * @param array $periods An array of Period instances.
     * @param array $idSites An array of site IDs (eg, `array(1, 2, 3)`).
     * @param bool $idSiteIsAll Whether `'all'` sites are being queried or not. If true, then
     *                          the result of querying functions will be indexed by site, regardless
     *                          of whether `count($idSites) == 1`.
     * @param bool $isMultipleDate Whether multiple dates are being queried or not. If true, then
     *                             the result of querying functions will be indexed by period,
     *                             regardless of whether `count($periods) == 1`.
     *
     * @return ArchiveQuery
     */
    public static function factory(\Piwik\Segment $segment, array $periods, array $idSites, $idSiteIsAll = \false, $isMultipleDate = \false)
    {
        return StaticContainer::get(ArchiveQueryFactory::class)->factory($segment, $periods, $idSites, $idSiteIsAll, $isMultipleDate);
    }
    public static function shouldSkipArchiveIfSkippingSegmentArchiveForToday(\Piwik\Site $site, \Piwik\Period $period, \Piwik\Segment $segment)
    {
        $now = \Piwik\Date::factory('now', $site->getTimezone());
        return !$segment->isEmpty() && $period->getDateStart()->toString() === $now->toString();
    }
    /**
     * Queries and returns metric data in an array.
     *
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be indexed by site ID.
     *
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be indexed by period.
     *
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be indexed by site ID first, then period.
     *
     * @param string|array $names One or more archive names, eg, `'nb_visits'`, `'Referrers_distinctKeywords'`,
     *                            etc.
     * @return false|integer|array `false` if there is no data to return, a single numeric value if we're not querying
     *                             for multiple sites/periods, or an array if multiple sites, periods or names are
     *                             queried for.
     */
    public function getNumeric($names)
    {
        $data = $this->get($names, 'numeric');
        $resultIndices = $this->getResultIndices();
        $result = $data->getIndexedArray($resultIndices);
        // if only one metric is returned, just return it as a numeric value
        if (empty($resultIndices) && count($result) <= 1 && (!is_array($names) || count($names) === 1)) {
            $result = (float) reset($result);
            // convert to float in case $result is empty
        }
        return $result;
    }
    /**
     * Queries and returns blob records without turning them into DataTables.
     *
     * Unlike other methods, this returns a DataCollection instance directly. Use it to directly access
     * and process blob data.
     *
     * @param string|string[] $names One or more archive names, eg, `'nb_visits'`, `'Referrers_distinctKeywords'`,
     *                            etc.
     * @return DataCollection the queried data.
     */
    public function getBlob($names, $idSubtable = null)
    {
        return $this->get($names, 'blob', $idSubtable);
    }
    /**
     * Queries blob data for a single record. Uses a cursor instead of fetching all the data at once,
     * and makes sure the result set's order allows aggregating the data one row at a time.
     *
     * @param string $name The record name to fetch.
     * @return \Generator
     * @internal
     */
    public function querySingleBlob($name)
    {
        [$archiveIds] = $this->getArchiveIdsAndStates([$name]);
        return ArchiveSelector::querySingleBlob($archiveIds, $name);
    }
    /**
     * Queries and returns metric data in a DataTable instance.
     *
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by site ID.
     *
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be a {@link DataTable\Map} that is indexed by period.
     *
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be a {@link DataTable\Map} indexed by site ID which contains {@link DataTable\Map} instances that are
     * indexed by period.
     *
     * _Note: Every DataTable instance returned will have at most one row in it. The contents of each
     *        row will be the requested metrics for the appropriate site and period._
     *
     * @param string|array $names One or more archive names, eg, 'nb_visits', 'Referrers_distinctKeywords',
     *                            etc.
     * @return DataTable|DataTable\Map A DataTable if multiple sites and periods were not requested.
     *                                 An appropriately indexed DataTable\Map if otherwise.
     */
    public function getDataTableFromNumeric($names)
    {
        $data = $this->get($names, 'numeric');
        return $data->getDataTable($this->getResultIndices());
    }
    /**
     * Similar to {@link getDataTableFromNumeric()} but merges all children on the created DataTable.
     *
     * This is the same as doing `$this->getDataTableFromNumeric()->mergeChildren()` but this way it is much faster.
     *
     * @return DataTable|DataTable\Map
     *
     * @internal Currently only used by MultiSites.getAll plugin. Feel free to remove internal tag if needed somewhere
     *           else. If no longer needed by MultiSites.getAll please remove this method. If you need this to work in
     *           a bit different way feel free to refactor as always.
     */
    public function getDataTableFromNumericAndMergeChildren($names)
    {
        $data = $this->get($names, 'numeric');
        $resultIndexes = $this->getResultIndices();
        return $data->getMergedDataTable($resultIndexes);
    }
    /**
     * Queries and returns one or more reports as DataTable instances.
     *
     * This method will query blob data that is a serialized array of of {@link DataTable\Row}'s and
     * unserialize it.
     *
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be a {@link DataTable\Map} that is indexed by site ID.
     *
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by period.
     *
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be a {@link DataTable\Map} indexed by site ID which contains {@link DataTable\Map} instances that are
     * indexed by period.
     *
     * @param string $name The name of the record to get. This method can only query one record at a time.
     * @param int|string|null $idSubtable The ID of the subtable to get (if any).
     * @return DataTable|DataTable\Map A DataTable if multiple sites and periods were not requested.
     *                                 An appropriately indexed {@link DataTable\Map} if otherwise.
     */
    public function getDataTable($name, $idSubtable = null)
    {
        $data = $this->get($name, 'blob', $idSubtable);
        return $data->getDataTable($this->getResultIndices());
    }
    /**
     * Queries and returns one report with all of its subtables loaded.
     *
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by site ID.
     *
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by period.
     *
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be a {@link DataTable\Map indexed} by site ID which contains {@link DataTable\Map} instances that are
     * indexed by period.
     *
     * @param string $name The name of the record to get.
     * @param int|string|null $idSubtable The ID of the subtable to get (if any). The subtable will be expanded.
     * @param int|null $depth The maximum number of subtable levels to load. If null, all levels are loaded.
     *                        For example, if `1` is supplied, then the DataTable returned will have its subtables
     *                        loaded. Those subtables, however, will NOT have their subtables loaded.
     * @param bool $addMetadataSubtableId Whether to add the database subtable ID as metadata to each datatable,
     *                                    or not.
     * @return DataTable|DataTable\Map
     */
    public function getDataTableExpanded($name, $idSubtable = null, $depth = null, $addMetadataSubtableId = \true)
    {
        $data = $this->get($name, 'blob', self::ID_SUBTABLE_LOAD_ALL_SUBTABLES);
        return $data->getExpandedDataTable($this->getResultIndices(), $idSubtable, $depth, $addMetadataSubtableId);
    }
    /**
     * Returns the given reports grouped by the plugin name that archives them.
     *
     * @param array $archiveNames
     * @return array `['MyPlugin' => ['MyPlugin_metric1', 'MyPlugin_report1'], ...]`
     */
    private function getRequestedPlugins($archiveNames)
    {
        $result = [];
        foreach ($archiveNames as $name) {
            $plugin = self::getPluginForReport($name);
            $result[$plugin][] = $name;
        }
        return array_map('array_unique', $result);
    }
    /**
     * Returns an object describing the set of sites, the set of periods and the segment
     * this Archive will query data for.
     *
     * @return Parameters
     */
    public function getParams()
    {
        return $this->params;
    }
    /**
     * Helper function that creates an Archive instance and queries for report data using
     * query parameter data. API methods can use this method to reduce code redundancy.
     *
     * @param string $recordName The name of the report to return.
     * @param int|string|array $idSite @see {@link build()}
     * @param string $period @see {@link build()}
     * @param string $date @see {@link build()}
     * @param string $segment @see {@link build()}
     * @param bool $expanded If true, loads all subtables. See {@link getDataTableExpanded()}
     * @param bool $flat If true, loads all subtables and disabled all recursive filters.
     * @param int|null|string $idSubtable See {@link getDataTableExpanded()}
     * @param int|null $depth See {@link getDataTableExpanded()}
     * @return DataTable|DataTable\Map
     */
    public static function createDataTableFromArchive($recordName, $idSite, $period, $date, $segment, $expanded = \false, $flat = \false, $idSubtable = null, $depth = null)
    {
        \Piwik\Piwik::checkUserHasViewAccess($idSite);
        if ($idSubtable === \false || $idSubtable === '') {
            $idSubtable = null;
        }
        if (!empty($idSubtable) && (strtolower($idSubtable) !== self::ID_SUBTABLE_LOAD_ALL_SUBTABLES && !is_numeric($idSubtable))) {
            throw new \Exception("idSubtable needs to be a number or '" . self::ID_SUBTABLE_LOAD_ALL_SUBTABLES . "', '{$idSubtable}' given.");
        }
        if ($flat && !$idSubtable) {
            $expanded = \true;
        }
        $archive = \Piwik\Archive::build($idSite, $period, $date, $segment, $_restrictSitesToLogin = \false);
        if ($expanded) {
            $dataTable = $archive->getDataTableExpanded($recordName, $idSubtable, $depth);
        } else {
            $dataTable = $archive->getDataTable($recordName, $idSubtable);
        }
        $dataTable->queueFilter('ReplaceSummaryRowLabel');
        $dataTable->queueFilter('ReplaceColumnNames');
        if ($expanded) {
            $dataTable->queueFilterSubtables('ReplaceColumnNames');
        }
        if ($flat) {
            $dataTable->disableRecursiveFilters();
        }
        return $dataTable;
    }
    /**
     * Queries archive tables for data and returns the result.
     * @param array|string $archiveNames
     * @param $archiveDataType
     * @param null|int $idSubtable
     * @return Archive\DataCollection
     */
    protected function get($archiveNames, $archiveDataType, $idSubtable = null)
    {
        if (!is_array($archiveNames)) {
            $archiveNames = [$archiveNames];
        }
        $archiveNames = array_filter($archiveNames);
        $idSites = $this->params->getIdSites();
        $periods = $this->params->getPeriods();
        // apply idSubtable
        if ($idSubtable !== null && $idSubtable !== self::ID_SUBTABLE_LOAD_ALL_SUBTABLES) {
            // this is also done in ArchiveSelector. It should be actually only done in ArchiveSelector but DataCollection
            // does require to have the subtableId appended. Needs to be changed in refactoring to have it only in one
            // place.
            $dataNames = [];
            foreach ($archiveNames as $name) {
                $dataNames[] = ArchiveSelector::appendIdsubtable($name, $idSubtable);
            }
        } else {
            $dataNames = $archiveNames;
        }
        $result = new \Piwik\Archive\DataCollection($dataNames, $archiveDataType, $idSites, $periods, $this->params->getSegment(), $defaultRow = null);
        if ([] === $dataNames) {
            // NOTE: not posting Archive.noArchivedData here,
            // because there might be archive data,
            // someone just requested nothing
            return $result;
        }
        [$archiveIds, $archiveStates] = $this->getArchiveIdsAndStates($archiveNames);
        if (empty($archiveIds)) {
            /**
             * Triggered when no archive data is found in an API request.
             * @ignore
             */
            \Piwik\Piwik::postEvent('Archive.noArchivedData');
            return $result;
        }
        $archiveData = ArchiveSelector::getArchiveData($archiveIds, $archiveNames, $archiveDataType, $idSubtable);
        $archiveState = new ArchiveState();
        $this->addDataToResultCollection($result, $archiveData, $archiveDataType);
        $archiveState->addMetadataToResultCollection($result, $archiveData, $archiveIds, $archiveStates);
        return $result;
    }
    private function addDataToResultCollection(DataCollection $result, array $archiveData, string $archiveDataType) : void
    {
        foreach ($archiveData as $row) {
            // values are grouped by idsite (site ID), date1-date2 (date range), then name (field name)
            $idSite = $row['idsite'];
            $period = $row['date1'] . ',' . $row['date2'];
            if ('numeric' === $archiveDataType) {
                $row['value'] = $this->formatNumericValue($row['value']);
            }
            $result->set($idSite, $period, $row['name'], $row['value'], [\Piwik\DataTable::ARCHIVED_DATE_METADATA_NAME => $row['ts_archived']]);
        }
    }
    /**
     * Returns archive IDs and the found doneFlag values for the sites, periods
     * and archive names that are being queried. This function will use the
     * idarchive cache if it has the right data, query archive tables for IDs
     * w/o launching archiving, or launch archiving and get the idarchive from
     * ArchiveProcessor instances.
     *
     * @param string[] $archiveNames
     *
     * @return array An array with two arrays:
     *               - archive ids
     *               - archive doneFlag values
     */
    private function getArchiveIdsAndStates(array $archiveNames) : array
    {
        $archiveNamesByPlugin = $this->getRequestedPlugins($archiveNames);
        $plugins = array_keys($archiveNamesByPlugin);
        // figure out which archives haven't been processed (if an archive has been processed,
        // then we have the archive IDs in $this->idarchives)
        $doneFlags = [];
        $archiveGroups = [];
        foreach (array_merge($plugins, ['all']) as $plugin) {
            $doneFlag = $this->getDoneStringForPlugin($plugin, $this->params->getIdSites());
            $doneFlags[$doneFlag] = \true;
            if (!isset($this->idarchives[$doneFlag])) {
                $archiveGroup = $this->getArchiveGroupOfPlugin($plugin);
                if ($archiveGroup === self::ARCHIVE_ALL_PLUGINS_FLAG) {
                    $archiveGroup = reset($plugins);
                }
                $archiveGroups[] = $archiveGroup;
            }
            $doneFlag = Rules::getDoneFlagArchiveContainsOnePlugin($this->params->getSegment(), $plugin);
            $doneFlags[$doneFlag] = \true;
        }
        $globalDoneFlag = Rules::getDoneFlagArchiveContainsAllPlugins($this->params->getSegment());
        $doneFlags[$globalDoneFlag] = \true;
        // cache id archives for plugins we haven't processed yet
        if (!empty($archiveGroups)) {
            if (Rules::isArchivingEnabledFor($this->params->getIdSites(), $this->params->getSegment(), $this->getPeriodLabel()) && !$this->forceFetchingWithoutLaunchingArchiving) {
                $this->cacheArchiveIdsAfterLaunching($archiveNamesByPlugin);
            } else {
                $this->cacheArchiveIdsWithoutLaunching($plugins);
            }
        }
        return $this->getArchiveIdsAndStatesByMonth($doneFlags);
    }
    /**
     * Gets the IDs of the archives we're querying for and stores them in $this->archives.
     * This function will launch the archiving process for each period/site/plugin if
     * metrics/reports have not been calculated/archived already.
     *
     * @param array $archiveNamesByPlugin @see getRequestedPlugins
     */
    private function cacheArchiveIdsAfterLaunching($archiveNamesByPlugin)
    {
        foreach ($this->params->getPeriods() as $period) {
            $twoDaysAfterPeriod = $period->getDateEnd()->addDay(2);
            foreach ($this->params->getIdSites() as $idSite) {
                $site = new \Piwik\Site($idSite);
                if (\Piwik\Common::getRequestVar('skipArchiveSegmentToday', 0, 'int') && self::shouldSkipArchiveIfSkippingSegmentArchiveForToday($site, $period, $this->params->getSegment())) {
                    \Piwik\Log::debug("Skipping archive %s for %s as segment today is disabled", $period->getLabel(), $period->getPrettyString());
                    continue;
                }
                // if the END of the period is BEFORE the website creation date
                // we already know there are no stats for this period
                // we add one day to make sure we don't miss the day of the website creation
                if ($twoDaysAfterPeriod->isEarlier($site->getCreationDate())) {
                    \Piwik\Log::debug("Archive site %s, %s (%s) skipped, archive is before the website was created.", $idSite, $period->getLabel(), $period->getPrettyString());
                    continue;
                }
                // Allow for site timezone, local time may have started a new day ahead of UTC
                $today = \Piwik\Date::factory('now', $site->getTimezone());
                // if the starting date is in the future we know there are no visits
                if ($period->getDateStart()->isLater($today)) {
                    \Piwik\Log::debug("Archive site %s, %s (%s) skipped, archive is after today.", $idSite, $period->getLabel(), $period->getPrettyString());
                    continue;
                }
                $this->prepareArchive($archiveNamesByPlugin, $site, $period);
            }
        }
    }
    /**
     * Gets the IDs of the archives we're querying for and stores them in $this->archives.
     * This function will not launch the archiving process (and is thus much, much faster
     * than cacheArchiveIdsAfterLaunching).
     *
     * @param array $plugins List of plugin names from which data is being requested.
     */
    private function cacheArchiveIdsWithoutLaunching($plugins)
    {
        [$idarchivesByReport, $idarchiveStatesByReport] = ArchiveSelector::getArchiveIdsAndStates($this->params->getIdSites(), $this->params->getPeriods(), $this->params->getSegment(), $plugins);
        // initialize archive ID cache for each report
        foreach ($plugins as $plugin) {
            $doneFlag = $this->getDoneStringForPlugin($plugin, $this->params->getIdSites());
            $this->initializeArchiveIdCache($doneFlag);
            $globalDoneFlag = Rules::getDoneFlagArchiveContainsAllPlugins($this->params->getSegment());
            $this->initializeArchiveIdCache($globalDoneFlag);
        }
        foreach ($idarchivesByReport as $doneFlag => $idarchivesByDate) {
            foreach ($idarchivesByDate as $dateRange => $idarchives) {
                foreach ($idarchives as $idarchive) {
                    // idarchives selected can include all plugin archives, plugin specific archives and partial report
                    // archives. only the latest data in all of these archives will be selected.
                    $this->idarchives[$doneFlag][$dateRange][] = $idarchive;
                }
            }
        }
        foreach ($idarchiveStatesByReport as $idSite => $idarchiveStatesBySite) {
            foreach ($idarchiveStatesBySite as $doneFlag => $idarchiveStatesByDate) {
                foreach ($idarchiveStatesByDate as $dateRange => $idarchiveStates) {
                    foreach ($idarchiveStates as $idarchive => $state) {
                        $this->idarchiveStates[$idSite][$doneFlag][$dateRange][$idarchive] = $state;
                    }
                }
            }
        }
    }
    /**
     * Returns the done string flag for a plugin using this instance's segment & periods.
     * @param string $plugin
     * @return string
     */
    private function getDoneStringForPlugin($plugin, $idSites)
    {
        $requestedReport = $this->getRequestedReport();
        $shouldOnlyProcessRequestedArchives = empty($requestedReport) && Rules::shouldProcessOnlyReportsRequestedInArchiveQuery($this->getPeriodLabel());
        if ($shouldOnlyProcessRequestedArchives) {
            return Rules::getDoneFlagArchiveContainsOnePlugin($this->params->getSegment(), $plugin);
        }
        return Rules::getDoneStringFlagFor($idSites, $this->params->getSegment(), $this->getPeriodLabel(), $plugin);
    }
    private function getPeriodLabel()
    {
        $periods = $this->params->getPeriods();
        return reset($periods)->getLabel();
    }
    /**
     * Returns an array describing what metadata to use when indexing a query result.
     * For use with DataCollection.
     *
     * @return array
     */
    private function getResultIndices()
    {
        $indices = [];
        if (count($this->params->getIdSites()) > 1 || $this->forceIndexedBySite) {
            $indices['site'] = 'idSite';
        }
        if (count($this->params->getPeriods()) > 1 || $this->forceIndexedByDate) {
            $indices['period'] = 'date';
        }
        return $indices;
    }
    private function formatNumericValue($value)
    {
        // If there is no dot, we return as is
        // Note: this could be an integer bigger than 32 bits
        if (strpos($value, '.') === \false) {
            if ($value === \false) {
                return 0;
            }
            return (float) $value;
        }
        // Round up the value with 2 decimals
        // we cast the result as float because returns false when no visitors
        return round((float) $value, 2);
    }
    /**
     * Initializes the archive ID cache ($this->idarchives) for a particular 'done' flag.
     *
     * It is necessary that each archive ID caching function call this method for each
     * unique 'done' flag it encounters, since the getArchiveIds function determines
     * whether archiving should be launched based on whether $this->idarchives has a
     * an entry for a specific 'done' flag.
     *
     * If this  function is not called, then periods with no visits will not add
     * entries to the cache. If the archive is used again, SQL will be executed to
     * try and find the archive IDs even though we know there are none.
     *
     * @param string $doneFlag
     */
    private function initializeArchiveIdCache(string $doneFlag)
    {
        if (!isset($this->idarchives[$doneFlag])) {
            $this->idarchives[$doneFlag] = [];
        }
    }
    /**
     * Returns the archiving group identifier given a plugin.
     *
     * More than one plugin can be called at once when archiving. In such a case
     * we don't want to launch archiving three times for three plugins if doing
     * it once is enough, so getArchiveIds makes sure to get the archive group of
     * all reports.
     *
     * If the period isn't a range, then all plugins' archiving code is executed.
     * If the period is a range, then archiving code is executed individually for
     * each plugin.
     */
    private function getArchiveGroupOfPlugin($plugin)
    {
        $periods = $this->params->getPeriods();
        $periodLabel = reset($periods)->getLabel();
        if (Rules::shouldProcessReportsAllPlugins($this->params->getIdSites(), $this->params->getSegment(), $periodLabel)) {
            return self::ARCHIVE_ALL_PLUGINS_FLAG;
        }
        return $plugin;
    }
    /**
     * Returns the name of the plugin that archives a given report.
     *
     * @param string $report Archive data name, eg, `'nb_visits'`, `'DevicesDetection_...'`, etc.
     * @return string Plugin name.
     * @throws \Exception If a plugin cannot be found or if the plugin for the report isn't
     *                    activated.
     */
    public static function getPluginForReport($report)
    {
        if (in_array($report, \Piwik\Metrics::getVisitsMetricNames())) {
            // Core metrics are always processed in Core, for the requested date/period/segment
            $report = 'VisitsSummary_CoreMetrics';
        } elseif (strpos($report, 'Goal_') === 0) {
            // Goal_* metrics are processed by the Goals plugin (HACK)
            $report = 'Goals_Metrics';
        } elseif (strrpos($report, '_returning') === strlen($report) - strlen('_returning') || strrpos($report, '_new') === strlen($report) - strlen('_new')) {
            // HACK
            $report = 'VisitFrequency_Metrics';
        }
        $plugin = substr($report, 0, strpos($report, '_'));
        if (empty($plugin) || !\Piwik\Plugin\Manager::getInstance()->isPluginActivated($plugin)) {
            throw new \Exception("Error: The report '{$report}' was requested but it is not available at this stage." . " (Plugin '{$plugin}' is not activated.)");
        }
        return $plugin;
    }
    private function prepareArchive(array $archiveNamesByPlugin, \Piwik\Site $site, \Piwik\Period $period)
    {
        $coreAdminHomeApi = API::getInstance();
        $requestedReport = $this->getRequestedReport();
        $shouldOnlyProcessRequestedArchives = empty($requestedReport) && Rules::shouldProcessOnlyReportsRequestedInArchiveQuery($period->getLabel());
        $periodString = $period->getRangeString();
        $periodDateStr = $period->getLabel() == 'range' ? $periodString : $period->getDateStart()->toString();
        $idSite = $site->getId();
        // process for each plugin as well
        foreach ($archiveNamesByPlugin as $plugin => $archiveNames) {
            $doneFlag = $this->getDoneStringForPlugin($plugin, [$idSite]);
            $this->initializeArchiveIdCache($doneFlag);
            $reportsToArchiveForThisPlugin = empty($requestedReport) && $shouldOnlyProcessRequestedArchives ? $archiveNames : $requestedReport;
            $prepareResult = $coreAdminHomeApi->archiveReports($idSite, $period->getLabel(), $periodDateStr, $this->params->getSegment()->getOriginalString(), $plugin, $reportsToArchiveForThisPlugin);
            if (empty($prepareResult) || empty($prepareResult['idarchives'])) {
                continue;
            }
            foreach ($prepareResult['idarchives'] as $idArchive) {
                if (is_array($this->idarchives[$doneFlag][$periodString] ?? null) && in_array($idArchive, $this->idarchives[$doneFlag][$periodString])) {
                    continue;
                }
                $this->idarchives[$doneFlag][$periodString][] = $idArchive;
                $this->idarchiveStates[$idSite][$doneFlag][$periodString][$idArchive] = ArchiveWriter::DONE_OK;
            }
        }
    }
    private function getArchiveIdsAndStatesByMonth($doneFlags)
    {
        // order idarchives by the table month they belong to
        $archiveIdsByMonth = [];
        $archiveStatesByMonth = [];
        foreach (array_keys($doneFlags) as $doneFlag) {
            if (empty($this->idarchives[$doneFlag])) {
                continue;
            }
            foreach ($this->idarchives[$doneFlag] as $dateRange => $idarchives) {
                foreach ($idarchives as $id) {
                    $archiveIdsByMonth[$dateRange][] = $id;
                }
            }
            foreach ($this->idarchiveStates as $idSite => $siteArchiveStates) {
                if (!isset($siteArchiveStates[$doneFlag])) {
                    continue;
                }
                foreach ($siteArchiveStates[$doneFlag] as $dateRange => $archiveStates) {
                    $archiveStatesByMonth[$idSite][$dateRange] = $archiveStates + ($archiveStatesByMonth[$idSite][$dateRange] ?? []);
                }
            }
        }
        return [$archiveIdsByMonth, $archiveStatesByMonth];
    }
    /**
     * @internal
     */
    public static function clearStaticCache()
    {
        self::$cache = null;
    }
    public function forceFetchingWithoutLaunchingArchiving()
    {
        $this->forceFetchingWithoutLaunchingArchiving = \true;
    }
    private function getRequestedReport() : ?string
    {
        $requestedReport = null;
        if (\Piwik\SettingsServer::isArchivePhpTriggered()) {
            $requestedReport = \Piwik\Request::fromRequest()->getStringParameter('requestedReport', '');
        }
        return $requestedReport;
    }
}
