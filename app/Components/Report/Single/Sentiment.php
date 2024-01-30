<?php
    
namespace App\Components\Report\Single;

use App\Components\Report\Report;
use App\Models\QuestionAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Class Sentiment
 *
 * Description and arguments can be found in the parent class
 *
 * @package App\Components\Report\Single
 */
class Sentiment extends Report
{
    public $name        = 'sentiment';
    public $exportable  = false;
    public $clickable   = true;
    public $benchmark   = false;
    public $label       = '__reportSentimentLabel';
    public $description = '__reportSentimentDescription';
    public $per_page    = 4;

    /**
     * Get data as per provided arguments
     *
     * @param array $args
     *
     * @return array
     */
    public function getData($args)
    {
        $answers = DB::table('question_answers')
                    ->select(['question_answers.*', 'feedbacks.*', 'carriers.name'])
                    ->joinFql('question_answers.feedback_id')
                    ->restrictedUser('question_answers.feedback_id')
                    ->join('feedbacks', 'feedbacks.id', '=', 'question_answers.feedback_id')
                    ->join('carriers', 'feedbacks.carrier_id', '=', 'carriers.id')
	                ->whereNotNull('sentiment_score')
	                ->whereNull('question_answers.deleted_at')
	                ->whereNull('feedbacks.deleted_at')
                    ->get();

        $result = [
            'good_feeling'    => 0,
            'neutral_feeling' => 0,
            'bad_feeling'     => 0
        ];
        $total = sizeof($answers) > 0 ? sizeof($answers) : 1;

        foreach ($answers as $answer) {
            if ($answer->sentiment_score <= QuestionAnswer::NEGATIVE_THRESHOLD) {
                $result['bad_feeling'] += 1;
            } elseif ($answer->sentiment_score >= QuestionAnswer::POSITIVE_THRESHOLD) {
                $result['good_feeling'] += 1;
            } else {
                $result['neutral_feeling'] += 1;
            }
        }
        
        $feedback_ids = $answers->pluck('feedback_id')->unique()->toArray();
        $sentiment_data = [
            'good_feeling'              => $this->percentageValue($result['good_feeling'], $total) . '%',
            'neutral_feeling'           => $this->percentageValue($result['neutral_feeling'], $total) . '%',
            'bad_feeling'               => $this->percentageValue($result['bad_feeling'], $total) . '%',
            'time_period'               => $this->getTimePeriod($this->feedbackQuery),
            'feedback_carrier_selected' => implode(',', $answers->pluck('name')->unique()->toArray()),
            'feedback_analysed'         => sizeof($feedback_ids),
            'satisfaction_ratio'        => round($answers->sum('satisfaction_ratio') / $total)
        ];
    
        if (isset($args['on_report'])) {
            $report_data  = [
                'nps'                  => $this->getNpsCount($feedback_ids),
                'drop_out_rate'        => $this->getDropOutRate($args),
                'completion_rate'      => $this->getCompletionRate($feedback_ids),
                'rewards_sent'         => $this->getRewardSent($feedback_ids),
                'engagements_clicked'  => $this->getEngagementCount($feedback_ids),
                'most_popular_country' => implode(',', $this->getPopularCountries($feedback_ids, true))
            ];
        
            $sentiment_data = array_merge($sentiment_data, $report_data);
        }
        
        return [$sentiment_data];
    }

    /**
     * Get percentage value of the sentiment
     *
     * @param float $score
     * @param int $total
     *
     * @return float|int
     */
    public function percentageValue($score, $total)
    {
        if ($total === 0) {
            return 0;
        }

        return round(($score / $total) * 100, 1);
    }

    /**
     * Get data of export
     *
     * @param array $args
     *
     * @return array
     */
    public function getExportData($args)
    {
        $this->per_page = 100;

        return $this->getData(array_merge($args, ['on_report' => 1]));
    }
}
