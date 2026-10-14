<?php

namespace Tests\Feature\Services;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;
use App\Models\Measurement;
use App\Models\Station;
use App\Services\StationReportData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StationReportDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_rows_convert_measurement_enum_casts_to_their_backed_values(): void
    {
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        Measurement::query()->create([
            'station_id' => $station->id,
            'logger_sn' => 'logger-1',
            'sensor_sn' => 'sensor-1',
            'sensor_measurement_type' => 'Air Temperature',
            'data_type' => 'CURRENT',
            'timestamp' => '2026-10-14 12:00:00',
            'value' => 33.25,
            'unit' => '°C',
            'quality_flag' => QualityFlag::Suspect,
            'flag_reason' => FlagReason::Spike,
        ]);

        $data = app(StationReportData::class)->build($station, '2026-10-14');

        $this->assertSame(2, $data['rows']->sole()['flag']);
        $this->assertSame('spike', $data['rows']->sole()['reason']);
        $this->assertSame('Suspeito', $data['rows']->sole()['status']);
    }
}
