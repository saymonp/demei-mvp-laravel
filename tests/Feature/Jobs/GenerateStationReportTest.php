<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateStationReport;
use App\Models\Station;
use App\Models\StationReport;
use App\Services\StationReportData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateStationReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_supported_format_is_stored_and_marks_its_report_ready(): void
    {
        Storage::fake('local');
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);

        foreach (StationReport::FORMATS as $format) {
            $report = StationReport::query()->create([
                'station_id' => $station->id,
                'reference_date' => '2026-10-14',
                'format' => $format,
            ]);

            (new GenerateStationReport($report))->handle(app(StationReportData::class));

            $report->refresh();
            $this->assertSame('ready', $report->status);
            Storage::disk('local')->assertExists($report->path);
        }
    }
}
