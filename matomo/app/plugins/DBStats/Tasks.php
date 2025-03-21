<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Plugins\DBStats;

use Piwik\Date;
use Piwik\Option;
class Tasks extends \Piwik\Plugin\Tasks
{
    public function schedule()
    {
        $this->weekly('cacheDataByArchiveNameReports', null, self::LOWEST_PRIORITY);
    }
    /**
     * Caches the intermediate DataTables used in the getIndividualReportsSummary and
     * getIndividualMetricsSummary reports in the option table.
     */
    public function cacheDataByArchiveNameReports()
    {
        $api = \Piwik\Plugins\DBStats\API::getInstance();
        $api->getIndividualReportsSummary(\true);
        $api->getIndividualMetricsSummary(\true);
        $now = Date::now()->getLocalized(Date::DATE_FORMAT_SHORT);
        Option::set(\Piwik\Plugins\DBStats\DBStats::TIME_OF_LAST_TASK_RUN_OPTION, $now);
    }
}
