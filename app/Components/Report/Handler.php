<?php

namespace App\Components\Report;

use App\Components\BaseHandler;
use Carbon\Carbon;

/**
 * Class Handler
 *
 * @package App\Components\Report
 */
class Handler extends BaseHandler
{
    /**
     * @var array
     */
    protected $carrierIds;

	/**
	 * @var int
	 */
	protected $teamId;

	/**
	 * @var array|null
	 */
	protected $benchmarkItems;

    /**
     * @var  Carbon
     */
    protected $startingDate;

    /**
     * @var  Carbon
     */
    protected $endingDate;

    /**
     * @var  string
     */
    protected $order;

    /**
     * @var  int
     */
    protected $page;

    /**
     * @var  string
     */
    protected $search;

    /**
     * @var  string
     */
    protected $dataSet;
    
    /**
     * Question report identifier ( question_id for single question and `multiple` for all questions report)
     *
     * @var  mixed
     */
    protected $reportIdentifier;

	/**
	 * The item type when looking for answers report
	 *
	 * @var  int
	 */
	protected $itemId;

	/**
	 * The item type when looking for answers report
	 *
	 * @var  string
	 */
	protected $itemType;

	/**
	 * The type of export requested
	 *
	 * @var  string
	 */
	protected $exportType;

    /**
     * The component type
     *
     * @var string
     */
    public $component = 'Report';

    /**
     * Primary FQL query that always executes
     *
     * @var string
     */
    protected $fql_1;

    /**
     * Check logged in user is restricted user
     *
     * @var boolean|null
     */
    protected $isRestrictedUser;
    
    /**
     * Handler constructor.
     *
     * @param int    $team_id
     * @param        $time_start
     * @param        $time_end
     * @param string $order
     * @param int    $page
     * @param string $search
     * @param string $data_set
     * @param int    $item_id
     * @param string $item_type
     * @param string $export_type - listing|computed
     * @param string $fql_1
     * @param boolean|null $is_restricted_user
     */
    public function __construct(
        $team_id,
        $carrier_ids,
        $order = 'desc',
        $page = 1,
        $search = '',
        $data_set = 'received',
	    $item_id = null,
	    $item_type = null,
        $export_type = null,
        $fql_1 = null,
        $is_restricted_user = null
    ) {
        $this->carrierIds         = array_map('intval', explode(',', $carrier_ids));
        $this->order              = $order;
        $this->page               = $page;
        $this->teamId             = $team_id;
        $this->search             = $search;
        $this->dataSet            = $data_set;
        $this->itemId             = $item_id;
        $this->itemType           = $item_type;
        $this->exportType         = $export_type;
        $this->fql_1              = $fql_1;
        $this->isRestrictedUser = $is_restricted_user;
    }
    
    /**
     * Build a report to return it from the API
     *
     * @param string $type
     *
     * @return array
     */
    public function getReport($type)
    {
        $report = $this->getSingle($type);
        $args   = $this->buildArguments();

        // We get the attribute through a getter function to apply filtering (translation)
        foreach ($report as $attribute => $value) {
        	if ($attribute === 'data') {
        		continue;
	        }

            $getter = 'get' . $attribute;
            $report->$attribute = $report->$getter();
        }

		$report_data = [
			'type'        => $report->name,
			'label'       => $report->label,
			'description' => $report->description,
			'exportable'  => $report->exportable,
			'clickable'   => $report->clickable,
			'benchmark'   => $report->benchmark,
			'percentage'  => $report->percentage,
			'data'        => json_encode($report->getDatasets($args)),
		];
        
        return $report_data;
    }
    
    /**
     * Build a report ready for export
     *
     * @param string $type
     *
     * @return array
     */
    public function exportReport($type)
    {
        $report = $this->getSingle($type);
        $args   = $this->buildArguments();

        return $report->getDatasets($args, true);
    }
    
    /**
     * Create the array of arguments
     *
     * @return array
     */
    private function buildArguments()
    {
        return [
            'team_id'               => $this->teamId,
            'carrier_ids'           => $this->carrierIds,
            'order'                 => $this->order,
            'page'                  => $this->page,
            'search'                => $this->search,
            'data_set'              => $this->dataSet,
            'item_id'               => $this->itemId,
            'item_type'             => $this->itemType,
            'export_type'           => $this->exportType,
            'fql'                   => $this->fql_1,
            'is_restricted_user'    => $this->isRestrictedUser,
        ];
    }
}
