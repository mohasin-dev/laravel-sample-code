<?php

namespace App\Components\Report;

interface ReportInterface
{
    /**
     * Get the data for the report
     *
     * @param array $args
     *
     * @return mixed
     */
    public function getData($args);
}
