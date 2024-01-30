<?php

namespace App\Components\Report;

use App\Components\BaseComponent;
use App\Components\Report\Traits\Commons;
use App\Fql\Fql;
use App\Models\Carrier;
use App\Models\CarrierMeta;
use App\Models\Feedback;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Class Report
 *
 * @package App\Components\Report
 */
abstract class Report extends BaseComponent implements ReportInterface
{
    use Commons;

    /**
     * The default interval that applies
     * if no FQL comes from frontend
     */
    const DEFAULT_DAY_INTERVAL = 7;
    
    /**
     * Default entries per page
     * Useful for the table reports
     *
     * @var int
     */
    public $per_page = 10;

	/**
	 * The args from the query
	 *
	 * @var array
	 */
	public $args = [];

    /**
     * Whether the report can be exported or not
     *
     * @var bool
     */
    public $exportable = false;

    /**
     * Whether the data represents a % or not
     * Specific style would be applied on the frontend
     *
     * @var bool
     */
    public $percentage = false;

	/**
	 * Whether it supports benchmark or not
	 * So several datasets
	 *
	 * @var bool
	 */
	public $benchmark = false;

    /**
     * The report name ENUM
     *
     * @var string
     */
    public $name = '';

    /**
     * The report label
     *
     * @var string
     */
    public $label = '';

    /**
     * The report description
     *
     * @var string
     */
    public $description = '';

    /**
     * If the item in the report are clickable (eg. question answers)
     *
     * @var boolean
     */
    public $clickable = false;

    /**
     * Feedback query applied with FQL
     *
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $feedbackQuery;

    /**
     * Get collection of feedback satisfaction ratio for graph data in dashboard
     *
     * @return Builder
     */
	public function getSatisfactionRatio()
    {
         return DB::table('feedbacks')
                ->select('feedbacks.id', 'satisfaction_ratio', 'feedbacks.created_at', 'completed')
                ->distinct()
                ->joinFql('feedbacks.id')
                ->restrictedUser('feedbacks.id')
                ->whereNull('feedbacks.deleted_at');
    }

    /**
     * Get array of carrier ids which anonymise responses is true
     *
     * @param array $carrier_ids
     *
     * @return array
     */
    public function getAnonymiseResponseSupportedCarrierIds($carrier_ids)
    {
        return CarrierMeta::where('name', 'anonymise_responses')
                            ->where('value', 'true')
                            ->whereIn('carrier_id', $carrier_ids)
                            ->pluck('carrier_id')
                            ->toArray();
    }

    /**
     * Get collection of NPS score data in dashboard
     *
     * @return Collection
     */
    public function getNpsScoreData()
    {
         return DB::table('question_answers')->select('question_answers.value')
                ->join('questions', 'questions.id', '=', 'question_answers.question_id')
                ->join('feedbacks', 'feedbacks.id', '=', 'question_answers.feedback_id')
                ->joinFql('question_answers.feedback_id')
                ->restrictedUser('question_answers.feedback_id')
                ->where('questions.type', 'nps')
                ->whereNull('question_answers.deleted_at')
                ->whereNull('feedbacks.deleted_at')
                ->groupBy('question_answers.id')
                ->get();
    }

	/**
	 * Get an array of Datasets for a given report
	 * The size is 1 if no Benchmark, 2 otherwise
	 *
	 * @param array $args
	 * @param boolean $has_export - whether we're in export mode or not
	 *
	 * @return array
	 */
    public function getDatasets($args, $has_export = false)
    {
        $this->args = $args;
        $datasets = [];

        $fql_query_id = Fql::query($args['fql'], $args['team_id']);

        // This is used to get time period from FQL, for graph data
        $this->feedbackQuery = Feedback::fql($args['fql'], $args['team_id']);

        // Reusable macro to join fql
        Builder::macro('joinFql', function($feedback_id_column) use($fql_query_id) {
            $this->join('fql_query_feedbacks', 'fql_query_feedbacks.feedback_id', '=', $feedback_id_column);
            $this->where('fql_query_feedbacks.fql_query_id', $fql_query_id);

            return $this;
        });

        // Reusable macro to join Restricted User with different tables
        Builder::macro('restrictedUser', function($feedback_id_column) use($args) {

            if (!isset($args['is_restricted_user']) || !$args['is_restricted_user']) {
                return $this;
            }

            $carrier_ids = Carrier::where('creator_id', auth()->id())->whereIn('id', $args['carrier_ids'])->pluck('id')->toArray();
            $joined_tables = collect($this->joins)->pluck('table')->toArray();

            // Join the feedbacks table which will be used to filter the carrier_id
            if (!in_array('feedbacks', $joined_tables) && ($this->from !== 'feedbacks')) {
                $this->join('feedbacks', 'feedbacks.id', $feedback_id_column);
            }

            $this->join('feedback_owners', 'feedback_owners.feedback_id', '=', $feedback_id_column);

            $this->where(function ($query) use ($carrier_ids) {
                $query->where('feedback_owners.user_id', auth()->id())
                    ->orWhereIn('feedbacks.carrier_id', $carrier_ids);
            });

            return $this;
        });

	    $datasets[] = ($has_export) ? $this->getExportData($this->args) : $this->getData($this->args);

	    return ($has_export) ? $datasets[0] : $datasets;
    }

	/**
	 * Get the Carrier Ids attached to the report query
	 *
	 * @return array
	 */
    public function getCarrierIds()
    {
        return $this->args['carrier_ids'];
    }
}
