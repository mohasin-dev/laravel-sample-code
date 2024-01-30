<?php

namespace App\Components\Report;

use App\Models\User;
use Illuminate\Support\Str;
use App\Components\Report\Handler as reportHandler;

/**
 * This class benchmark all the FQL queries present in the arguments
 * Dynamic fql benchmarking enabled, so we can benchmark as much fql we want
 */
class Benchmark
{
    /**
     * List of fql queries to benchmark
     *
     * @var array
     */
    protected $fqls = [];

    /**
     * Get report/benchmark related to the user
     *
     * @var User
     */
    protected $user;

    /**
     * Necessary argument for getting the reports
     *
     * @var array
     */
    protected $args;

    public function __construct(array $args, User $user)
    {
        $this->user = $user;
        $this->args = $args;

        $this->fqls = $this->parseFql($args);
        $this->setDefaultFql();
    }

    /**
     * Set default fql for benchamrk if in-between fql was not given from frontend
     * For example: if `fql_1` and `fql_3` is given then
     * this will set `fql_2` default to []
     *
     * @return void
     */
    protected function setDefaultFql()
    {
        // Sort the fql by keys in asc order, final result: fql_1 > fql_2 > fql_3 > fql_4
        ksort($this->fqls);

        // take the last fql_index
        $last = array_key_last($this->fqls);

        // Get the integer (3) from fql_3
        $highest_fql_number = intval(filter_var($last, FILTER_SANITIZE_NUMBER_INT));

        // Set the default fql to [] if no in-between fql is specified
        for ($i=1; $i <= $highest_fql_number; $i++) {
            if (!isset($this->fqls["fql_{$i}"])) {
                $this->fqls["fql_{$i}"] = '[]';
            }
        }

        // Sort by key again for newer entries
        ksort($this->fqls);
    }

    /**
     * Get the reports for the benchmark
     *
     * @return array
     */
    public function get(): array
    {
        $reports = [];

        foreach ($this->fqls as $key => $fql) {
            $args = $this->args;

            // Only Fql_1 is allowed to be empty, will result empty result if empty fql `[]`
            if ($key !== 'fql_1' && $fql === '[]') {
                $reports[] = [];
                continue;
            }

            $reports[] = $this->getReport($fql, $args);
        }

        return $reports;
    }

    /**
     * Parse all the fql in the arguments for the benchmark
     *
     * @param array $args
     *
     * @return array
     */
    protected function parseFql(array $args): array
    {
        $fqls = [];

        foreach ($args as $key => $value) {
            if (Str::contains($key, 'fql')) {
                $fqls[$key] = $value;
            }
        }

        return $fqls;
    }

    /**
     * Get the report for single FQL query
     *
     * @param string $fql
     *
     * @return array
     */
    protected function getReport(string $fql, array $args): array
    {
        $handler = new ReportHandler(
            $this->user->team_id,
            $args['carrier_ids'],
            $args['order'],
            $args['page'],
            $args['search'],
            $args['data_set'],
            $args['item_id'],
            $args['item_type'],
            $export_type = null,
            $fql
        );

        return $handler->getReport($args['type']);
    }
}
