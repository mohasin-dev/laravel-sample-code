<?php

namespace App\Components\Report\Single;

use App\Components\Report\Report;
use App\Models\CarrierView;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Components\Report\Nps as NpsHelper;
use App\Models\Push;

/**
 * Class Received
 *
 * Description and arguments can be found in the parent class
 *
 * @package App\Components\Report\Single
 */
class Activity extends Report
{
    use NpsHelper;

    public $name = 'activity';
    public $exportable = false;
    public $benchmark = true;
    public $label = '__reportReceivedLabel';
    public $description = '__reportReceivedDescription';

    /**
     * Labels of the dates
     *
     * @var array
     */
    public $labels = [];
    
    /**
     * Feedback entries
     *
     * @var array
     */
    public $feedbackEntries = [];
    
    /**
     * Satisfaction ratios
     *
     * @var array
     */
    public $satisfactionRatios = [];
    
    /**
     * Graph data
     *
     * @var array
     */
    public $graphData = [];
    
    /**
     * Label for the graph
     *
     * @var string
     */
    public $xLabel = '';
    
    /**
     * The date format in the graph
     *
     * @var string
     */
    public $format = 'm/d';

    /**
     * The count of answers
     *
     * @var integer
     */
    public $count;

    /**
     * The data coming from our DB
     *
     * @var Illuminate\Database\Eloquent\Collection
     */
    public $data;

    /**
     * Start date of push activity charts
     *
     * @var \Carbon\Carbon
     */
    protected $pushStartDate;

    /**
     * End date of the pushes for activity charts
     *
     * @var \Carbon\Carbon
     */
    protected $pushEndDate;

    /**
     * Get data for report & graph
     *
     * @param array $args
     *
     * @return array|mixed
     */
    public function getData($args)
    {

	    // Change graph date data based on locale
	    if (auth() && $user = auth()->user()) {
		    $existing_locale = $user->metas->where('name', 'locale')->first();

		    if ($existing_locale && $existing_locale->value === 'fr') {
			    $this->format = 'd/m';
		    }
	    }

        if (sizeof($this->labels) === 0 && !in_array($this->args['data_set'], ['pushes_sent', 'pushes_clicked'])) {
            $this->initializeSetup();
        }

        // Set data_set default to received
        $args['data_set'] = $args['data_set'] ?? 'received';

        switch ($args['data_set']) {
            case 'nps':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportNPSLabel'),
                    $this->getNPSData($this->labels),
                    'rgba(73,80,87,1)'
                );
                break;
            case 'ratio':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportRatioLabel'),
                    $this->satisfactionRatios,
                    'rgba(62,86,119,1)'
                );
                break;
            case 'pushes_clicked':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportPushesClickedLabel'),
                    $this->getPushData($this->labels, $args, false),
                    'rgba(75, 186, 227, 1)'
                );
                break;
            case 'pushes_sent':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportPushesSentLabel'),
                    $this->getPushData($this->labels, $args, true),
                    'rgba(75, 186, 227, 1)'
                );
                break;
            case 'completion_rate':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportCompletionRateLabel'),
                    $this->getFeedbackCompletionRate(),
                    'rgba(75, 186, 227, 1)'
                );
                break;
            case 'rewards_sent':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportSentRewardLabel'),
                    $this->getRewardSentData(),
                    'rgba(75, 186, 227, 1)'
                );
                break;
            case 'engagement_clicks':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportEngagementClickLabel'),
                    $this->getEngagements(),
                    'rgba(75, 186, 227, 1)'
                );
                break;
                
            case 'popular_countries':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportPopularCountriesLabel'),
                    $this->getPopularCountries(),
                    'rgba(75, 186, 227, 1)'
                );
                break;
                
            case 'carrier_names':
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportCarrierNameLabel'),
                    $this->getCarrierNames(), 'rgba(75, 186, 227, 1)'
                );
                break;
                
            default:
                $dataset = $this->getGraphDataSet(
                    __('dashboard.reportReceivedLabel'),
                    $this->graphData,
                    'rgba(75, 186, 227, 1)'
                );
        }

        // We show the graph data in number
        $graph_data = $dataset[0]['data'];
        $data_set   = $args['data_set'];

        if ($data_set === 'ratio') {
            $graph_data_count = $this->getSatisfactionRatio()->avg('satisfaction_ratio');

            // O SR is not possible it means no ratings
            if (empty($graph_data_count)) {
                $graph_data_count = 0;
            }

            $graph_data_count = round($graph_data_count) . '%';
        } else if($data_set === 'nps') {
            // Get the NPS data from parent class
            $this->data = $this->getNpsScoreData();
            $this->count = count($this->data);

            // Get Computed NPS score
            $graph_data_count = $this->computeNpsScore();
        } else {
            $graph_data_count = array_sum($graph_data);
        }

        $title = '<span class="font-weight-bold">'. $graph_data_count .'</span> ';

        return [
            'title'    => $title,
            'labels'   => $this->labels,
            'xLabel'   => $this->xLabel,
            'datasets' => $dataset
        ];
    }

    /**
     * Config and initialize reusable variables
     */
    private function initializeSetup()
    {
        if (!in_array($this->args['data_set'], ['pushes_sent', 'pushes_clicked'])) {
            $this->feedbackEntries = $this->getSatisfactionRatio()->oldest()->get();
        }
        
        if (count($this->feedbackEntries) > 0) {
            $time_start = (new Carbon($this->feedbackEntries->first()->created_at))->endOfDay();
            $time_end   = (new Carbon($this->feedbackEntries->last()->created_at))->endOfDay();
        } else {
            $time_start = now()->subDays(self::DEFAULT_DAY_INTERVAL - 1)->startOfDay();
            $time_end   = now()->endOfDay();
        }
        
        if (in_array($this->args['data_set'], ['pushes_sent', 'pushes_clicked'])) {
            $time_start = $this->pushStartDate;
            $time_end   = $this->pushEndDate;
        }

        $start     = Carbon::parse($time_start);
        $end       = Carbon::parse($time_end);
        $diff_days = $start->diffInDays($end);
    
        /*
         * For minimum of 7 days range
         */
        if ($diff_days < 6) {
            /*
             * When date range is within 7 days from now
             */
            if (
                $time_start->greaterThan(now()->subDays(self::DEFAULT_DAY_INTERVAL - 1)->startOfDay()->toDateTimeString()) &&
                $time_end->lessThanOrEqualTo(now()->endOfDay())
            ) {
                $start = now()->subDays(self::DEFAULT_DAY_INTERVAL - 1)->startOfDay();
                $end   = now()->endOfDay();
            } else {
                /*
                 * Here we adjust date to get 7 days period
                 *
                 * Example:
                 * If we have data only 1 date 2020-04-13
                 * We show the date range 2020-04-10 to 2020-04-16
                 *
                 */
                for ($i = 0; $i < (self::DEFAULT_DAY_INTERVAL - $diff_days); $i++) {
                    if ($i % 2 === 0) {
                        $start->subDay(1);
                    } else {
                        $end->addDays(1);
                    }
                }
            }
        }
        
        $is_year      = ($diff_days > 40);
        $this->xLabel = __('dashboard.xLabelTime', ['type' => __('dashboard.last24Hours')]);
        
        if ($is_year) {
            $this->format = 'M/Y';
        }

        // We build the labels
        $cursor_date = $start;
        $this->labels = [];
        
        if ($is_year) {
            while ($cursor_date->lessThanOrEqualTo($end) || $cursor_date->isSameMonth($end)) {
                array_push($this->labels, $cursor_date->format($this->format));
                $cursor_date->addMonth(1);
                $this->xLabel = __('dashboard.xLabelTime', ['type' => __('dashboard.months')]);
            }
        } else {
            while ($cursor_date->lessThanOrEqualTo($end)) {
                array_push($this->labels, $cursor_date->format($this->format));
                $cursor_date->addDay(1);
                $this->xLabel = __('dashboard.xLabelTime', ['type' => __('dashboard.days')]);
            }
        }

        // Graph data for received
        $this->graphData         = [];
        $this->satisfactionRatios = [];

        foreach ($this->labels as $label) {
            $count = 0;
            $satisfaction_ratio = 0;

            foreach ($this->feedbackEntries as $key => $item) {

                if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
                    continue;
                }

                $count++;
                $satisfaction_ratio = $satisfaction_ratio + $item->satisfaction_ratio;
            }

            array_push($this->graphData, $count);

            // Satisfaction ratio
            if ($count) {
                array_push($this->satisfactionRatios, round(($satisfaction_ratio / $count), 2));
            } else {
                array_push($this->satisfactionRatios, $count);
            }
        }
    }
    
    
    /**
     * Get data those needs only date wise counts based on the created_at field
     *
     * @param array $data
     *
     * @return array
     */
    public function countableLabelData($data) {
        $data_collection = [];
        
        foreach ($this->labels as $label) {
            $count = 0;
            
            foreach ($data as $key => $item) {
                if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
                    continue;
                }
                
                $count++;
            }
            
            array_push($data_collection, $count);
        }
        
        return $data_collection;
    }
    
	/**
	 * Get Chart.js dataset
	 *
	 * @param string $label
	 * @param array $data
	 * @param string $color
	 *
	 * @return array
	 */
    private function getGraphDataSet($label, $data, $color)
    {
		return [
			[
				'label'                     => $label,
				'data'                      => $data,
				'yAxisID'                   => 'left-y-axis',
				'fill'                      => true,
				'lineTension'               => .4,
				'xLabel'                    => .4,
				'borderWidth'               => 2,
				'borderColor'               => $color,
				'borderDash'                => [],
				'borderDashOffset'          => 0,
				'pointBorderColor'          => $color,
				'pointBackgroundColor'      => $color,
				'pointBorderWidth'          => 2,
				'pointHoverRadius'          => 5,
				'pointHoverBackgroundColor' => $color,
				'pointHoverBorderColor'     => $color,
				'pointHoverBorderWidth'     => 4,
				'pointRadius'               => 4,
				'pointHitRadius'            => 8,
				'spanGaps'                  => false,
			],
		];
    }
	
	/**
     * Get feedback completion rate
     *
     * @return array
     */
    public function getFeedbackCompletionRate() {
        $completion_rates = [];
    
        foreach ($this->labels as $label) {
            $count       = 0;
            $completed   = 0;
        
            foreach ($this->feedbackEntries as $key => $item) {
                if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
                    continue;
                }
            
                $count++;
            
                if ($item->completed) {
                    $completed++;
                }
            }
        
            if ($count) {
                array_push($completion_rates, (round($completed / $count, 2) * 100));
            } else {
                array_push($completion_rates, $count);
            }
        }
    
        return $completion_rates;
    }
    
    /**
     * Get sent rewards data
     *
     * @return array
     */
    private function getRewardSentData()
    {
        $rewards = DB::table('reward_transactions')
            ->join('feedbacks', 'reward_transactions.feedback_id', '=', 'feedbacks.id')
            ->joinFql('feedbacks.id')
            ->restrictedUser('feedbacks.id')
            ->where('reward_transactions.status', 'sent')
            ->whereNull('feedbacks.deleted_at')
            ->get();
        
        return $this->countableLabelData($rewards);
    }
    
    /**
     * Get engagements data
     *
     * @return array
     */
    private function getEngagements()
    {
        $engagements = DB::table('feedback_metas')
            ->join('feedbacks', 'feedback_metas.feedback_id', '=',  'feedbacks.id')
            ->joinFql('feedbacks.id')
            ->restrictedUser('feedbacks.id')
            ->where('feedback_metas.name', 'engagement_type')
            ->get();
    
        return $this->countableLabelData($engagements);
    }
    
    /**
     * We build the graph data set for NPS
     *
     * @param array $labels
     *
     * @return array
     */
    private function getNPSData($labels)
    {
        $avg_nps = [];

        $nps_answers = DB::table('question_answers')->select('question_answers.value', 'question_answers.created_at')
                        ->join('questions', 'questions.id', '=', 'question_answers.question_id')
                        ->where('questions.type', 'nps')
                        ->joinFql('question_answers.feedback_id')
                        ->restrictedUser('question_answers.feedback_id')
                        ->whereNull('question_answers.deleted_at')
                        ->get();

        if (!$nps_answers) {
            return $avg_nps;
        }

        foreach ($labels as $label) {
            $count = 0;
            $detractors = 0;
            $promoters = 0;

            foreach ($nps_answers as $key => $item) {
                if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
                    continue;
                }

                $count++;

                if ($item->value < 7) {
                    $detractors++;
                } elseif($item->value > 8) {
                    $promoters++;
                }

                unset($nps_answers[$key]);
            }

            if ($count) {
                // % $promoters - % $detractors to get NPS score
                $d_percentage = round($detractors / $count, 2) * 100;
                $p_percentage = round($promoters / $count, 2) * 100;
                array_push($avg_nps, $p_percentage - $d_percentage);
            } else {
                array_push($avg_nps, $count);
            }
        }
        
        return $avg_nps;
    }

	/**
	 * Views data for report
	 *
	 * @param array   $labels
	 * @param array   $args
	 * @param boolean $sent - whether sent or clicked
	 *
	 * @return array
	 */
	private function getPushData($labels, $args, $sent = true)
	{
		$push_array = [];

        $push_requests = Push::select('pushes.id', 'pushes.created_at')
                            ->where('pushes.status', '!=', Push::STATUS_SCHEDULED)
                            ->whereIn('pushes.carrier_id', $args['carrier_ids'])
                            ->fql($args['fql'])
                            ->orderBy('pushes.id', 'asc');

		if (!$sent) {
			$push_requests = $push_requests->where('pushes.status', Push::STATUS_CLICKED);
		}

		$push_requests = $push_requests->get();

		if (!$push_requests || empty($push_requests)) {
			return $push_array;
        }

        if ($push_requests->count() > 0) {
            $this->pushStartDate = Carbon::parse($push_requests->first()->created_at)->startOfDay();
            $this->pushEndDate = Carbon::parse($push_requests->last()->created_at)->endOfDay();
        } else {
            $this->pushStartDate = now()->subDays(self::DEFAULT_DAY_INTERVAL - 1)->startOfDay();
            $this->pushEndDate   = now()->endOfDay();
        }

        $this->initializeSetup();

        $labels = $this->labels;

		foreach ($labels as $label) {
			$count = 0;

			foreach ($push_requests as $key => $item) {
				if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
					continue;
				}

				$count++;

				unset($push_requests[$key]);
			}

			array_push($push_array, $count);
		}

		return $push_array;
	}

    /**
     * Get the exported data
     *
     * @param $args
     *
     * @return array
     */
    public function getExportData($args)
    {
        // Report data for all dataset
        $report_data_set = [
            'carrier_names',
            'views',
            'received',
            'dropout_rate',
            'completion_rate',
            'ratio',
            'nps',
            'rewards_sent',
            'engagement_clicks',
            'popular_countries'
        ];

        $formatted      = [];
        $formatted[0][] = ucfirst(__('dashboard.month'));
        $is_label_added = false;
        
        foreach ($report_data_set as $data_set) {
            $args['data_set'] = $data_set;
            $data = $this->getData($args);
            
            if (!$is_label_added) {
                foreach ($data['labels'] as $key => $label) {
                    $formatted[$key + 1][] = $label;
                }

                $is_label_added = true;
            }

            foreach ($data['datasets'] as $key => $set) {
                $formatted[0][] = $set['label'];

                foreach ($set['data'] as $item_key => $item) {
                    $formatted[$item_key + 1][] = $item;
                }
            }
        }
        
	    if ($this->format === 'H') {
		    $formatted[0][0] = ucfirst(__('dashboard.hour'));
	    }
	    
        $result = [];
        foreach ($formatted as $key => $item) {
            array_push($result, array_values($item));
        }
        
        return $result;
    }
}
