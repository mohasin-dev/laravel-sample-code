<?php

namespace App\Components\Report\Single;

use App\Components\Report\Report;
use Illuminate\Support\Facades\DB;

/**
 * Class Countries
 *
 * Description and arguments can be found in the parent class
 *
 * @package App\Components\Report\Single
 */
class Countries extends Report
{
    public $name = 'countries';
	public $benchmark = false;
    public $exportable = true;
    public $label = '__reportCountriesLabel';
    public $description = '__reportCountriesDescription';
    
    public function getData($args)
    {
        $data = DB::table('feedback_metas')->select(DB::raw('value, count(value) as country_count'))
                  ->where('feedback_metas.name', 'country_code')
                  ->joinFql('feedback_metas.feedback_id')
                  ->restrictedUser('feedback_metas.feedback_id')
                  ->groupBy('feedback_metas.value')
                  ->orderBy('country_count', 'desc')
	              ->distinct()
                  ->get(['value', 'feedback_id']);

        $formatted = [];

        foreach ($data as $row) {
            array_push($formatted, [
                $row->value,
                $row->country_count,
            ]);
        }
        
        return $formatted;
    }
    
    /**
     * Get country wise report data
     *
     * @param array $args
     *
     * @return array
     */
    public function reportCountriesData($args)
    {
        $select = 'value, count(value) as country_count, count(DISTINCT(feedbacks.id)) as total_feedback, avg(feedbacks.satisfaction_ratio) as satisfaction_ratio, count(DISTINCT(reward_transactions.id)) as rewards, GROUP_CONCAT(feedbacks.id SEPARATOR ",") as feedback_ids';
        
        $data = DB::table('feedback_metas')->select(DB::raw($select))
            ->join('feedbacks', 'feedbacks.id', '=', 'feedback_metas.feedback_id')
            ->join('carriers', 'feedbacks.carrier_id', '=', 'carriers.id')
            ->leftJoin('rewards', 'rewards.carrier_id', '=', 'carriers.id')
            ->leftJoin('reward_transactions', 'rewards.id', '=', 'reward_transactions.reward_id')
            ->where('feedback_metas.name', 'country_code')
            ->whereNull('feedbacks.deleted_at')
            ->whereNull('carriers.deleted_at')
            ->whereIn('carriers.id', $this->getCarrierIds())
            ->joinFql('feedback_metas.feedback_id')
            ->restrictedUser('feedback_metas.feedback_id')
            ->groupBy('feedback_metas.value')
            ->orderBy('country_count', 'desc')
            ->get();
        
        $result = [];
        foreach ($data as $country_data) {
            $feedback_ids = $this->getUniqueArray($country_data->feedback_ids);
            $result[] = [
                'country'                    => $country_data->value,
                'time_period'                => $this->getTimePeriod($this->feedbackQuery),
                'feedback_carriers_selected' => $this->getCountries($feedback_ids),
                'feedback_received'          => $country_data->total_feedback,
                'satisfaction_ratio'         => round($country_data->satisfaction_ratio),
                'nps'                        => $this->getNpsCount($feedback_ids),
                'drop_out_rate'              => $this->getDropOutRate($args),
                'completion_rate'            => $this->getCompletionRate($feedback_ids),
                'rewards_sent'               => $country_data->rewards,
                'engagement_clicked'         => $this->getEngagementCount($feedback_ids)
            ];
        }
        
        return $result;
    }
    
    /**
     * Get data for export
     *
     * @param array $args
     *
     * @return array|mixed
     */
    public function getExportData($args)
    {
        $this->per_page = 100000;
        
        return $this->reportCountriesData($args);
    }
}
