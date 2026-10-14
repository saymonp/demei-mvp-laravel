<?php

namespace App\Jobs;

use App\Exports\StationMeasurementsSheet;
use App\Exports\StationReportExport;
use App\Models\StationReport;
use App\Services\StationReportData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class GenerateStationReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 2;

    public $deleteWhenMissingModels = true;

    public function __construct(public StationReport $report)
    {
        $this->onQueue('reports');
    }

    public function handle(StationReportData $builder): void
    {
        $report = $this->report;
        $report->update(['status' => 'processing', 'error' => null]);

        $date = $report->reference_date->toDateString();
        $data = $builder->build($report->station, $date) + ['notes' => $report->notes];
        $path = sprintf(
            'reports/%d/%s-%s-%d.%s',
            $report->station_id,
            Str::slug($report->station->provider),
            $date,
            $report->id,
            $report->format,
        );

        $stored = match ($report->format) {
            'pdf' => Storage::disk('local')->put(
                $path,
                Pdf::loadView('reports.station-pdf', ['data' => $data])->setPaper('a4')->output(),
            ),
            'xlsx' => Excel::store(new StationReportExport($data), $path, 'local', ExcelFormat::XLSX),
            'csv' => Excel::store(new StationMeasurementsSheet($data), $path, 'local', ExcelFormat::CSV),
            default => false,
        };

        if (! $stored) {
            throw new RuntimeException("Unable to store station report {$report->id}.");
        }

        $report->update(['status' => 'ready', 'path' => $path, 'finished_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->report->update([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Station report generation failed.',
            'finished_at' => now(),
        ]);
    }
}
