<?php

namespace App\Components\Report\Single;

use App\Components\Report\Report;
use App\Models\Feedback;
use App\Services\GoogleCloud\Language;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Class SingleInsight
 *
 * Description and arguments can be found in the parent class
 *
 * @package App\Components\Report\Single
 */
class SingleInsight extends Report
{
    public $name = 'singleInsight';
    public $exportable = false;
	public $benchmark = false;
    
    public function getData($args)
    {
        $to = new Carbon($args['time_end']);
        $answers_query = DB::table('question_answers')->select(DB::raw('question_answers.value, question_answers.feedback_id, question_answers.created_at'))
                              ->join('questions', 'question_answers.question_id', '=', 'questions.id')
                              ->whereIn('questions.type', ['text', 'textarea'])
                              ->whereIn('questions.carrier_id', $args['carrier_ids'])
                              ->where('question_answers.value', '<>', '')
                              ->whereBetween('question_answers.created_at', [$args['time_start'], $to->addDay()->toDateTimeString()])
                              ->where('question_answers.value', 'like', '%' . $args['search'] . '%');

        $answers        = $answers_query->orderBy('question_answers.created_at', 'ASC')->get();
        $feedbacks      = $this->getFeedbacks($answers);
        $language_class = new Language();
        $language       = $language_class->client;

        // We get the sentiment
        try {
            $sentiment = $language->analyzeSentiment($args['search'])->sentiment();
            $score = $sentiment['score'] * 100;
        } catch (\Exception $e) {
            $score = null;
        }

        // We get the tag
        try {
            $tokens = $language->analyzeSyntax($args['search'])->tokens();
            $tag = $tokens[0]['partOfSpeech']['tag'];
        } catch (\Exception $e) {
            $tag = null;
        }

        // We get the category
        try {
            $categories = $language->classifyText($args['search'])->categories();
            $category = $categories[0];
        } catch (\Exception $e) {
            $category = null;
        }

        // We get the entity
        try {
            $entities = $language->analyzeEntities($args['search'])->entities();
            $entity = $entities[0]['type'];
        } catch (\Exception $e) {
            $entity = null;
        }
        
        return [
            'sentiment'          => $score,
            'category'           => ($category) ? $category : null,
            'syntax'             => ($tag && $tag !== 'X') ? Str::title($tag) : null,
            'entity'             => ($entity && $entity !== 'OTHER') ? Str::title($entity) : null,
            'mentions'           => sizeof($answers),
            'satisfaction_ratio' => round($feedbacks->avg('satisfaction_ratio'), 2),
            'date_first_seen'    => $this->getDate($answers[0]->created_at),
            'date_last_seen'     => $this->getDate($answers[sizeof($answers) - 1]->created_at),
            'word'               => $args['search'],
        ];
    }
    
    /**
     * Returns the feedbacks related to the answers
     *
     * @param array $answers
     *
     * @return Collection
     */
    private function getFeedbacks($answers)
    {
        $ids = [];
        foreach ($answers as $answer) {
            $ids[] = $answer->feedback_id;
        }
        
        return Feedback::whereIn('id', $ids)->get();
    }
    
    /**
     * Get the date from a created at
     *
     * @param string $created_at
     * @return string
     */
    private function getDate($created_at)
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $created_at)->diffForHumans();
    }
}
