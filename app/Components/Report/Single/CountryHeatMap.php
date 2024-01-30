<?php

namespace App\Components\Report\Single;

use App\Models\Rating;
use App\Models\Question;
use App\Models\RatingAnswer;
use App\Models\QuestionAnswer;
use App\Components\Report\Report;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CountryHeatMap extends Report
{
    public $name = 'countryHeatMap';

    /**
     * Group by city/country according to received feedback
     * If feedback in single country then group by city
     *
     * @var string
     */
    protected $groupByMeta = 'city';

    /**
     * The country code if all feedback are in single country
     * The value is null if multiple country
     *
     * @var string|null
     */
    protected $countryCode;

    /**
     * The resource type that was requested `questions/ratings`
     *
     * @var string
     */
    protected $itemType;

    /**
     * Resource ids separated by comma, we turn this to array for easier selection
     *
     * @var string
     */
    protected $itemIds;

    /**
     * Single resource type that was requested
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $item;

    /**
     * Report containing results grouped by country or city
     * Array contains:
     * label    => Most selected answer
     * location => City or Country code
     * total    => Number of feedback in the region
     *
     * @var array
     */
    protected $result = [];

    /**
     * Get report data
     *
     * @param array $args
     *
     * @return array
     */
    public function getData($args)
    {
        if (empty($args['item_id']) || empty($args['item_type']) || $args['item_type'] === 'custom_field') {
            return [];
        }

        // Turn string items_id into array of integers
        $item_ids      = explode(',', $args['item_id']);
        $this->itemIds = array_map(function ($item) {
            return intval($item);
        }, $item_ids);

        $this->itemType = $args['item_type'];

        // Set the question/rating model
        $this->setItem();

        // Macro for re-using the joining of french_cities table
        Builder::macro('joinFrenchCity', function () {
            $this->join('french_cities', 'feedback_metas.value', 'french_cities.city');
            $this->whereNotNull('french_cities.region');
            $this->whereNull('french_cities.deleted_at');

            return $this;
        });

        if ($this->itemType === 'ratings') {
            $this->computeRatings();
        } elseif ($this->itemType === 'questions') {
            $this->computeQuestion();
        }

        return [
            'country' => 'FR',
            'report'  => $this->result,
        ];
    }

    /**
     * Set the requested item
     *
     * @return void
     */
    public function setItem()
    {
        if ($this->itemType === 'ratings') {
            $this->item = Rating::whereIn('id', $this->itemIds)->first();
        } elseif ($this->itemType === 'questions') {
            $this->item = Question::whereIn('id', $this->itemIds)->first();
        }
    }

    /**
     * Generate report for ratings answers
     *
     * @return void
     */
    public function computeRatings()
    {
        $this->result = RatingAnswer::select(
            'french_cities.region as location',
            DB::raw('ROUND(avg(rating_answers.value)/20, 2) as label'),
            DB::raw('count(feedback_metas.value) as total')
        )
            ->joinFql('rating_answers.feedback_id')
            ->join('feedback_metas', 'feedback_metas.feedback_id', 'rating_answers.feedback_id')
            ->joinFrenchCity()
            ->whereIn('rating_id', $this->itemIds)
            ->where('feedback_metas.name', $this->groupByMeta)
            ->groupBy('french_cities.region')
            ->get()->toArray();
    }

    /**
     * Generate report for questions answers
     *
     * @return void
     */
    public function computeQuestion()
    {
        if (in_array($this->item->type, ['slider', 'largeSlider'])) {
            $this->getAverageValuePerRegion();
        }

        if (in_array($this->item->type, ['select', 'dropdown', 'image'])) {
            $this->getHighestSelectedOptionPerRegion();
        }

        if ($this->item->type === 'nps') {
            $this->getNpsTypeAnswers();
        }

        if (in_array($this->item->type, ['smiley', 'toggle'])) {
            $this->getMostSelectedAnswers();
        }
    }

    /**
     * Find the average value of a slider type question
     *
     * @return void
     */
    protected function getAverageValuePerRegion()
    {
        $this->result = QuestionAnswer::query()
                    ->select(
                        'french_cities.region as location',
                        DB::raw('ROUND(avg(question_answers.value), 2) as label'),
                        DB::raw('count(*) as total')
                    )
                    ->joinFql('question_answers.feedback_id')
                    ->join('feedback_metas', 'feedback_metas.feedback_id', 'question_answers.feedback_id')
                    ->joinFrenchCity()
                    ->whereIn('question_id', $this->itemIds)
                    ->where('feedback_metas.name', $this->groupByMeta)
                    ->groupBy('french_cities.region')
                    ->get()->toArray();
    }

    /**
     * Find most selected answer for smily and toggle type question
     *
     * @return void
     */
    protected function getMostSelectedAnswers()
    {
        $this->result = QuestionAnswer::query()
            ->select(
                'french_cities.region as location',
                'question_answers.value as label',
                DB::raw('count(*) as total')
            )
            ->joinFql('question_answers.feedback_id')
            ->join('feedback_metas', 'feedback_metas.feedback_id', 'question_answers.feedback_id')
            ->joinFrenchCity()
            ->where('feedback_metas.name', $this->groupByMeta)
            ->whereIn('question_answers.question_id', $this->itemIds)
            ->groupBy('french_cities.region', 'question_answers.value')
            ->orderBy('total', 'DESC')
            ->get()->groupBy('location')->values()->map(function ($data) {
                return $data->first();
            })->toArray();
    }

    /**
     * Calculate NPS for each region
     *
     * @return void
     */
    public function getNpsTypeAnswers()
    {
        $nps = QuestionAnswer::query()
            ->selectRaw('count(case when question_answers.value > 8 then 1 end) as promoters')
            ->selectRaw('count(case when question_answers.value < 7 then 1 end) as detractors')
            ->selectRaw('count(*) as total')
            ->addSelect('french_cities.region as location')
            ->joinFql('question_answers.feedback_id')
            ->join('feedback_metas', 'feedback_metas.feedback_id', 'question_answers.feedback_id')
            ->joinFrenchCity()
            ->where('feedback_metas.name', $this->groupByMeta)
            ->groupBy('french_cities.region')
            ->whereIn('question_id', $this->itemIds);

        $this->result = DB::query()->select(DB::raw('ROUND((100*(promoters-detractors))/total, 2) as label'), 'location', 'total')
            ->fromSub($nps, 'a')->get()->map(function ($data) {
                return (array) $data;
            })->toArray();
    }

    /**
     * Calculate highest selected option per region
     * For select/choice, dropdown and image type question
     *
     * @return void
     */
    public function getHighestSelectedOptionPerRegion()
    {
        $column = 'question_options.name';

        // Exceptional case for image type
        if ($this->item->type === 'image') {
            $column = 'question_options.url';
        }

        $this->result = QuestionAnswer::query()
            ->select(
                'french_cities.region as location',
                'question_options.name as label',
                DB::raw('count(feedback_metas.value) as total')
            )
            ->joinFql('question_answers.feedback_id')
            ->join('question_options', 'question_options.question_id', 'question_answers.question_id')
            ->join('feedback_metas', 'feedback_metas.feedback_id', 'question_answers.feedback_id')
            ->joinFrenchCity()
            ->where('feedback_metas.name', 'city')
            ->whereIn('question_answers.question_id', $this->itemIds)
            ->whereRaw("question_answers.value like CONCAT('%', {$column}, '%')")
            ->groupBy('french_cities.region', 'question_options.name')
            ->orderBy('total', 'DESC')
            ->get()->groupBy('location')->values()->map(function ($one) {
                return $one->first();
            })->toArray();
    }

    /**
     * Set the logic for group by - which we will use to group our answers
     * It's usually country_code or city
     *
     * @return void
     */
    private function setGroupByClause()
    {
        $table  = 'question_answers';
        $column = 'question_id';

        if ($this->itemType === 'ratings') {
            $table  = 'rating_answers';
            $column = 'rating_id';
        }

        $query = DB::table($table)
            ->whereIn($column, $this->itemIds)
            ->select('feedback_metas.value', DB::raw('count(*) as total'))
            ->joinFql("{$table}.feedback_id")
            ->join('feedback_metas', "{$table}.feedback_id", 'feedback_metas.feedback_id')
            ->where('feedback_metas.name', 'country_code')
            ->groupBy('feedback_metas.value');

        $country_count = DB::query()->fromSub($query, 'a')->count();

        // If feedback received by only 1 country then group all result by city
        if ($country_count === 1) {
            $this->groupByMeta = 'city';

            $this->countryCode = $query->first()->value;
        }
    }
}
