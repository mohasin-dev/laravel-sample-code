<?php

namespace App\Components\Report\Traits;

use App\Models\FeedbackCustomFieldValue;
use App\Models\FeedbackMeta;
use App\Models\QuestionOption;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Common methods amongst different reports
 *
 * Trait Commons
 *
 * @package App\Components\Report\Traits
 */
trait Commons
{
    
    /**
     * Get unique array from string
     *
     * @param string $values
     *
     * @return array
     */
    public function getUniqueArray($values) {
        return array_unique(explode(',', $values));
    }
    
    /**
     * Get time period in report
     *
     * @param Builder $feedbackQuery
     *
     * @return null|string
     */
    public function getTimePeriod(Builder $feedbackQuery)
    {
        $start = $feedbackQuery->oldest()->first();
        $last = $feedbackQuery->latest()->first();

        if (!$start || !$last) {
            return null;
        }

        $period    = $start->created_at->format('m/d/y');
        $period    = $period .' - '. $last->created_at->format('m/d/y');

        return $period;
    }
    
    /**
     * Get engagements count for given feedback ids
     *
     * @param array $feedback_ids
     *
     * @return int
     */
    public function getEngagementCount($feedback_ids)
    {
        return $data = DB::table('feedback_metas')->select('feedback_metas.id')
            ->where('feedback_metas.name', 'engagement_email')
            ->whereIn('feedback_metas.feedback_id', $feedback_ids)
            ->whereNull('feedback_metas.deleted_at')
            ->count();
    }
    
    /**
     * Get nps count for given feedback ids
     *
     * @param array $feedback_ids
     *
     * @return int
     */
    public function getNpsCount($feedback_ids)
    {
        return DB::table('question_answers')->select('question_answers.id')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->where('questions.type', 'nps')
            ->whereIn('question_answers.feedback_id', $feedback_ids)
            ->whereNull('question_answers.deleted_at')
            ->count();
    }
    
    /**
     * Get country names based on the feedback ids
     *
     * @param array $feedback_ids
     *
     * @return array
     */
    public function getCountries($feedback_ids)
    {
        $countries = DB::table('feedback_metas')->select('carriers.name')
            ->join('feedbacks', 'feedbacks.id', '=', 'feedback_metas.feedback_id')
            ->join('carriers', 'carriers.id', '=', 'feedbacks.carrier_id')
            ->where('feedback_metas.name', 'country_code')
            ->whereIn('feedbacks.id', $feedback_ids)
            ->whereNull('feedbacks.deleted_at')
            ->whereNull('carriers.deleted_at')
            ->whereNull('feedback_metas.deleted_at')
            ->pluck('name')
            ->unique()
            ->toArray();
        return implode(',', $countries);
    }
    
    /**
     * Get drop out rate for given feedback ids and time range
     *
     * @param array $feedback_ids
     *
     * @return int
     */
    public function getDropOutRate($args)
    {
	    $total_feedbacks = DB::table('feedbacks')->whereIn('feedbacks.carrier_id', $args['carrier_ids'])
		    ->joinFql('feedbacks.id')
		    ->restrictedUser('feedbacks.id')
		    ->whereNull('deleted_at')
		    ->count();
	
        $total_views = DB::table('carrier_views')->whereIn('carrier_views.carrier_id', $args['carrier_ids'])
            ->join('feedbacks', 'feedbacks.carrier_id', '=', 'carrier_views.carrier_id')
            ->joinFql('feedbacks.id')
            ->restrictedUser('feedbacks.id')
            ->count();

	    if ($total_views === 0 ) {
		    return 0;
	    }
	    
	    if ($total_feedbacks > $total_views) {
		    $rate = 100;
	    } else {
		    $rate = round((100 - ($total_feedbacks / $total_views) * 100), 1);
	    }
    
        return $rate;
    }
    
    /**
     * Get reward sent count
     *
     * @param array $feedback_ids
     *
     * @return int
     */
    public function getRewardSent($feedback_ids)
    {
        return DB::table('reward_transactions')
            ->select('reward_transactions.*')
            ->whereIn('feedback_id', $feedback_ids)
            ->count();
    }
    
    /**
     * Get carrier names
     *
     * @param array $carrier_ids
     * @param boolean $only_carrier -if we need only carrier names
     *
     * @return array
     */
    public function getCarrierNames($carrier_ids = [] , $only_carrier = false)
    {
        if (empty($carrier_ids)) {
            $carrier_ids = $this->getCarrierIds();
        }
        
        $carriers = DB::table('carriers')->select(['name', 'feedbacks.created_at'])
            ->join('feedbacks', 'feedbacks.carrier_id', 'carriers.id')
            ->whereIn('carriers.id', $carrier_ids)
            ->get();
        
        if ($only_carrier) {
            return $carriers->pluck('name')->unique()->toArray();
        }
    
        // Activity report data
        $carrier_names = [];
        foreach ($this->labels as $label) {
            $names = [];
            
            foreach ($carriers as $key => $item) {
                if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
                    continue;
                }
                
                $names[] = $item->name;
            }
            
            array_push($carrier_names,  implode(',', array_unique($names)));
        }
        
        return $carrier_names;
    }
    
    /**
     * Get popular countries data
     *
     * @param array $feedback_ids
     * @param bool  $only_countries
     *
     * @return array
     */
    public function getPopularCountries($feedback_ids = [], $only_countries = false)
    {
        if (empty($feedback_ids)) {
            $feedback_ids = $this->feedbackEntries->pluck('id')->toArray();
        }
        
        $countries = DB::table('feedback_metas')
            ->join('feedbacks', 'feedback_metas.feedback_id', '=',  'feedbacks.id')
            ->whereIn('feedbacks.id', $feedback_ids)
            ->where('feedback_metas.name', 'country_code')
            ->get();
        
        if ($only_countries) {
            return $countries->pluck('value')->unique()->toArray();
        }
        
        // Activity report data
        $countries_data = [];
        foreach ($this->labels as $label) {
            $country_counts = [];
            
            foreach ($countries as $key => $item) {
                if (Carbon::createFromFormat('Y-m-d H:i:s', $item->created_at)->format($this->format) !== $label) {
                    continue;
                }
                
                if(!isset($country_counts[$item->value])) {
                    $country_counts[$item->value] = 1;
                }
                else {
                    $country_counts[$item->value] += 1;
                }
            }
            
            $country_code = sizeof($country_counts) ? array_flip($country_counts)[max(array_values($country_counts))] : null;
            array_push($countries_data,  $country_code);
        }
        
        return $countries_data;
    }
    
    /**
     * Get feedback completion rate(completed/total) for given feedback ids and time range
     *
     * @param array $feedback_ids
     *
     * @return int
     */
    public function getCompletionRate($feedback_ids)
    {
        $feedbacks =  DB::table('feedbacks')->select('feedbacks.*')
            ->whereIn('feedbacks.id', $feedback_ids)
            ->whereNull('feedbacks.deleted_at');
        
        $total = $feedbacks->count();
        
        if ($total === 0 ) {
            return 0;
        }
        
        return round(($feedbacks->where('completed', 1)->count() / $total) * 100, 2);
    }
    
    /**
     * Common report format data with feedback email, country and custom fields
     *
     * @param Collection $result
     *
     * @return array
     */
    public function generalReportData($result)
    {
        $data = [];
        foreach ($result as $row) {
            $row         = (array)$row;
            $feedback_id = $row['feedback_id'];
            if ($row['date_completed']) {
                $row['date_completed'] = (new Carbon($row['date_completed']))->format('Y-m-d');
            }
            
            if (isset($row['question_type']) && $row['question_type'] === 'image') {
                $values = QuestionOption::select('name')
                            ->whereIn('url', json_decode($row['response']))
                            ->get()
                            ->toArray();
                
                if (!empty($values)) {
                    $row['response'] = json_encode(Arr::flatten($values));
                }
            }
            
            $this->getFeedbackAttributes($feedback_id, $row);
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Get feedback's metas and custom fields attributes
     *
     * @param int     $feedback_id
     * @param array   $row
     *
     * @return void
     */
    public function getFeedbackAttributes($feedback_id, &$row)
    {
        $metas               = FeedbackMeta::where('feedback_id', $feedback_id)
            ->whereIn('feedback_metas.name', ['country_code', 'email'])
            ->get();
        $email               = $metas->where('name', 'email')->first();
        $row['email']        = ($email) ? $email->value : null;
        $country_code        = $metas->where('name', 'country_code')->first();
        $row['country_code'] = ($country_code) ? $country_code->value : null;
        $custom_fields       = FeedbackCustomFieldValue::with('carrierCustomField')
            ->where('feedback_id', $feedback_id)
            ->get();
        
        if ($custom_fields) {
            foreach ($custom_fields as $field) {
                $row['custom_fields'][$field->carrierCustomField->name] = $field->value ?? '';
            }
        }
    }
}
