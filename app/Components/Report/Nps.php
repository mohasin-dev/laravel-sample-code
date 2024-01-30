<?php

namespace App\Components\Report;

/**
 * Trait Nps
 *
 * NPS common functions
 *
 * @package App\Components\Report
 */
trait Nps
{
	/**
	 * The count of answers
	 *
	 * @var int
	 */
	public $count;

	/**
	 * The data coming from our DB
	 *
	 * @var Illuminate\Database\Eloquent\Collection
	 */
	public $data;

	/**
	 * Compute the NPS score according to his definition
	 * @link https://www.netpromoter.com/know/
	 *
	 * @return float
	 */
	public function computeNpsScore()
	{
		$promoters = $this->getPromoterCount();
		$detractors = $this->getDetractorCount();

		if (!$promoters && !$detractors) {
			return 0;
		}

		$promoters_percentage = ($promoters * 100) / $this->count;
		$detractors_percentage = ($detractors * 100) / $this->count;

		return round(($promoters_percentage - $detractors_percentage), 2);
	}

	/**
	 * Get the promoter count of answers
	 *
	 * @return int
	 */
	public function getPromoterCount()
	{
		return $this->data->filter(function ($answer) {
			return $answer->value > 8;
		})->count();
	}

	/**
	 * Get the detractor count of answers
	 *
	 * @return int
	 */
	public function getDetractorCount()
	{
		return $this->data->filter(function ($answer) {
			return $answer->value < 7;
		})->count();
	}
}
