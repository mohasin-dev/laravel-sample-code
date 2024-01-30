<?php

namespace App\Components\Report\Single;

use App\Components\Report\Report;
use Illuminate\Support\Facades\DB;

/**
 * Class Country
 *
 * Description and arguments can be found in the parent class
 *
 * @package App\Components\Report\Single
 */
class Country extends Report
{
    public $name = 'country';
    public $exportable = false;
	public $benchmark = true;
    public $label = '__reportCountryLabel';
    public $description = '__reportCountryDescription';
    
    public function getData($args)
    {
	    $item = DB::table('feedback_metas')->select(DB::raw('value, count(value) as country_count'))
                  ->where('feedback_metas.name', 'country_code')
                  ->joinFql('feedback_metas.feedback_id')
                  ->restrictedUser('feedback_metas.feedback_id')
                  ->groupBy('feedback_metas.value')
                  ->orderBy('country_count', 'desc')
	              ->get(['value'])
	              ->first();

        return ($item) ? '<img src="' . asset('images/flags/' . strtolower($item->value) .'.svg') . '" class="rounded-circle" width="40">' : '';
    }
}
