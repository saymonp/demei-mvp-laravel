<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class StationReportExport implements Export, WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    public function sheets(): array
    {
        return [
            new StationMeasurementsSheet($this->data),
            new StationSummarySheet($this->data),
        ];
    }
}
