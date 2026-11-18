<?php

namespace Tests\Feature\Console\Commands;

use App\Events\MeasurementsClassified;
use App\Models\Measurement;
use App\Models\Station;
use App\Models\StationRawData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillOpenMeteoHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_data_is_resampled_classified_and_updated_idempotently(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'timezone' => 'America/Sao_Paulo',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-05-16T00:00', '2026-05-16T00:15'],
                    'temperature_2m' => [20.0, 21.0],
                    'shortwave_radiation' => [100.0, 200.0],
                ],
            ]),
        ]);
        Event::fake([MeasurementsClassified::class]);
        $station = $this->station();

        $arguments = [
            '--station' => ['open_meteo'],
            '--from' => '2026-05-16',
            '--to' => '2026-05-16',
            '--resolution' => '5',
            '--noise' => '0',
            '--cooldown' => '0',
        ];

        $this->assertSame(0, Artisan::call('weather:backfill-open-meteo', $arguments));
        $this->assertDatabaseCount('station_raw_data', 1);
        $this->assertDatabaseCount('measurements', 8);

        $rawData = StationRawData::query()->sole();
        $this->assertSame('open_meteo', $rawData->payload['_source_provider']);
        $this->assertSame(5, $rawData->payload['_backfill']['resolution_minutes']);
        $this->assertSame(
            ['2026-05-16T00:00', '2026-05-16T00:05', '2026-05-16T00:10', '2026-05-16T00:15'],
            $rawData->payload['minutely_15']['time'],
        );
        $this->assertDatabaseHas('measurements', [
            'station_id' => $station->id,
            'sensor_sn' => 'open-meteo-temp-sensor',
            'timestamp' => '2026-05-16 03:05:00',
            'value' => 20.33,
        ]);

        Event::assertDispatched(MeasurementsClassified::class, fn (MeasurementsClassified $event): bool => $event->stationIds === [$station->id]
            && $event->date === '2026-05-16');
        $this->assertNotNull(Cache::get("weather.classified.{$station->id}.2026-05-16"));

        $this->assertSame(0, Artisan::call('weather:backfill-open-meteo', $arguments));
        $this->assertDatabaseCount('station_raw_data', 1);
        $this->assertSame(8, Measurement::query()->count());
    }

    public function test_dry_run_does_not_store_or_classify_archive_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'archive-api.open-meteo.com/*' => Http::response([
                'timezone' => 'America/Sao_Paulo',
                'minutely_15' => [
                    'time' => ['2026-05-16T00:00'],
                    'temperature_2m' => [20.0],
                    'shortwave_radiation' => [100.0],
                ],
            ]),
        ]);
        Event::fake([MeasurementsClassified::class]);
        $this->station();

        $status = Artisan::call('weather:backfill-open-meteo', [
            '--station' => ['open_meteo'],
            '--from' => '2026-05-16',
            '--to' => '2026-05-16',
            '--cooldown' => '0',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $status, Artisan::output());

        $this->assertDatabaseCount('station_raw_data', 0);
        $this->assertDatabaseCount('measurements', 0);
        Event::assertNotDispatched(MeasurementsClassified::class);
    }

    private function station(): Station
    {
        return Station::forceCreate([
            'name' => 'open_meteo',
            'api_url' => 'https://api.open-meteo.com/v1/forecast',
            'provider' => 'open_meteo',
            'latitude' => -28.353,
            'longitude' => -53.836,
            'is_active' => true,
        ]);
    }
}
