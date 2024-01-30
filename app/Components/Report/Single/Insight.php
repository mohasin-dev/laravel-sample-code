<?php

namespace App\Components\Report\Single;

use App\Components\Report\Report;

use Illuminate\Support\Facades\DB;

/**
 * Class Insight
 *
 * Description and arguments can be found in the parent class
 *
 * @package App\Components\Report\Single
 */
class Insight extends Report
{
    public $name = 'insight';
    public $exportable = true;
	public $benchmark = true;
    public $label = '__reportInsightLabel';
    public $description = '__reportInsightDescription';
    
    /**
     * Get data
     *
     * @param array $args
     *
     * @return array|mixed
     */
    public function getData($args)
    {
        $data = $this->fetchData($args);

        /*
         * We build the labels
         */
        $ratios     = (sizeof($data) === 0) ? [] : collect($data)->pluck('satisfaction_ratio')->unique()->sort()->toArray();
        $graph_data = [];
        $max        = (sizeof($data) === 0) ? 0 : $data->max('occurrences');

        foreach ($ratios as $ratio) {
            foreach ($data as $key => $item) {
                if ($item->satisfaction_ratio !== $ratio) {
                    continue;
                }

                // When no rating set satisfaction ratio to 100
                if ($ratio === null) {
                    $ratio = 100;
                }

                $array = [
                    'backgroundColor' => fd_get_ratio_color($ratio),
                    'borderColor'     => fd_get_ratio_color($ratio),
                    'data'            => [
                        [
                            'x'     => intval($ratio),
                            'y'     => intval($item->occurrences),
                            'r'     => $this->setRadius($item->occurrences, $max),
                            'label' => $item->term,
                        ],
                    ],
                ];

                array_push($graph_data, $array);
                unset($data[$key]);
            }
        }
        
        return [
            'title'    => $this->label,
            'labelX'   => 'Satisfaction Ratio (in %)',
            'labelY'   => 'Insight Mentions',
            'datasets' => $graph_data,
        ];
    }
    
    /**
     * Get export data
     *
     * @param array $args
     *
     * @return array
     */
    public function getExportData($args)
    {
        return $this->reportData($args);
    }
    
    /**
     * Fetch data
     *
     * @return array|\Illuminate\Support\Collection
     */
    private function fetchData()
    {
        /*
         * First we get all the possible answers
         */
        $answer_candidates = DB::table('question_answers AS q')
                               ->select('q.id')
                               ->join('questions', 'q.question_id', '=', 'questions.id')
                               ->joinFql('q.feedback_id')
                               ->restrictedUser('q.feedback_id')
                               ->whereIn('questions.type', ['text', 'textarea'])
                               ->whereRaw("q.value NOT RLIKE '" . implode('|', $this->getBlacklist()) . "'")
                               ->get(['id']);

        // We exit if more than 2000 candidates, not supported for now
        if (!$answer_candidates || sizeof($answer_candidates) > 2000) {
            return [];
        }

        $arguments = $this->buildUnionArguments($answer_candidates);
        $select = "substring_index(substring_index(q.value, ' ', n), ' ', -1) AS term, count(*) AS occurrences, avg(satisfaction_ratio) AS satisfaction_ratio, GROUP_CONCAT(feedbacks.carrier_id SEPARATOR ',') as carrier_ids, count(DISTINCT(reward_transactions.id)) as rewards, GROUP_CONCAT(feedbacks.id SEPARATOR ',') as feedback_ids, count(DISTINCT(feedbacks.id)) as total_feedback";
        /*
         * We build the query and return it
         */
        return DB::table('question_answers AS q')
			->select(DB::raw($select))
			->join(DB::raw("( $arguments ) n"), 'n', '<=', DB::raw("length(q.value) - length(replace(q.value, ' ', '')) + 1"))
			->whereIn('q.id', $answer_candidates->pluck('id')->toArray())
			->join('feedbacks', 'feedbacks.id', '=', 'q.feedback_id')
			->join('reward_transactions', 'feedbacks.id', '=', 'reward_transactions.feedback_id')
			->whereRaw("length(substring_index(substring_index(q.value, ' ', n), ' ', -1)) > 3")
			->groupBy('term')
			->orderBy('occurrences', 'DESC')
			->limit(15)
			->get();
    }
    
    /**
     * Get data for the report
     *
     * @param array $args
     *
     * @return array
     */
    public function reportData($args)
    {
        $insight = $this->fetchData($args)->toArray();
        $data = [];
        // TODO: will need to get commented columns in the report in future
        foreach ($insight as $item) {
            $feedback_ids = $this->getUniqueArray($item->feedback_ids);
            $carrier_names = $this->getCarrierNames($this->getUniqueArray($item->carrier_ids), true);
            $data[]= [
                'term'                       => $item->term,
                'occurrences'                => $item->occurrences,
                'time_period'                => $this->getTimePeriod($this->feedbackQuery),
                'feedback_carriers_selected' => implode(',', $carrier_names),
//                'feedback_received'          => $item->total_feedback,
                'satisfaction_ratio'         => round($item->satisfaction_ratio),
                'nps'                        => $this->getNpsCount($feedback_ids),
                'drop_out_rate'              => $this->getDropOutRate($args),
                'completion_rate'            => $this->getCompletionRate($feedback_ids),
//                'rewards_sent'               => $item->rewards,
//                'engagement_clicked'         => $this->getEngagementCount($feedback_ids),
                'most_popular_country'       => implode(',', $this->getPopularCountries($feedback_ids, true))
            ];
        }
        
        return $data;
    }
    
    /**
     * Build the argument for the SQL query
     *
     * @param $answer_candidates
     *
     * @return string
     */
    private function buildUnionArguments($answer_candidates)
    {
        $max_length = DB::table('question_answers')
                        ->whereIn('id', $answer_candidates->pluck('id')->toArray())
                        ->selectRaw('max(length(value)) as max_length')
                        ->first()->max_length;

        $string = "select 1 as n ";

        for ($i = 2; $i <= intval($max_length); $i++) {
            $string .= "union all select $i ";
        }
        
        return $string;
    }
    
    /**
     * Set the radius value - avoid having tones of different values
     *
     * @param int $value
     * @param     $max
     * @return float
     */
    private function setRadius(int $value, $max)
    {
        // In order to determine the radius, we take a proportion of the max value
        $ratio = $value / ($max ?? 100);

        switch ($ratio) {
            case ($ratio < 0.2):
                return 25;
                break;
            case ($ratio >= 0.2 && $ratio < 0.4):
                return 30;
                break;
            case ($ratio >= 0.4 && $ratio < 0.6):
                return 40;
                break;
            case ($ratio >= 0.6 && $ratio < 0.8):
                return 45;
                break;
            case ($ratio >= 0.8):
                return 50;
                break;
        }
    }
    
    /**
     * Returns a list of words that are not relevant for the Insight report
     *
     * @return array
     */
    private function getBlacklist()
    {
        $blacklist = [
            'guys',
            'like',
            'will',
            'would',
            'am',
            'are',
            'I',
            'you',
            'he',
            'she',
            'me',
            'mine',
            'the',
            'company',
            'organization',
            'feedier',
            'is',
            'they',
            'your',
            'that',
            'them',
            'him',
            'more',
            'much',
            'with',
            'has',
            'have',
            'good',
            'great',
            'just',
            'better',
            'none',
            'no',
            'nope',
            'not',
            'test',
            'from',
            'do',
            'did',
        ];

        foreach ($blacklist as $key => $word) {
            $blacklist[$key] = '[[:<:]]' . $word . '[[:>:]]';
        }
        
        return $blacklist;
    }
}
