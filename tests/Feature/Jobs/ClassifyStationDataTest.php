<?php

namespace Tests\Feature\Jobs;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;
use App\Jobs\ClassifyStationData;
use App\Models\Measurement;
use App\Models\Station;
use App\Models\StationRawData;
use App\Services\Quality\PayloadReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassifyStationDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_licor_ignores_battery_and_marks_null_values_as_missing(): void
    {
        $station = $this->station('licor');
        $rawData = $station->rawData()->create([
            'payload' => ['data' => [
                $this->licorReading('Air Temperature', 'TEMP-1', '°C', null, '2026-10-09 15:00:00Z'),
                $this->licorReading('Solar Radiation', 'RAD-1', 'W/m²', 800, '2026-10-09 15:00:00Z'),
                $this->licorReading('Battery', 'BAT-1', 'V', 4.2, '2026-10-09 15:00:00Z'),
            ]],
        ]);

        $this->classify($rawData);

        $this->assertDatabaseCount('measurements', 2);
        $this->assertDatabaseHas('measurements', [
            'sensor_sn' => 'TEMP-1',
            'value' => null,
            'quality_flag' => QualityFlag::Missing->value,
            'flag_reason' => FlagReason::MissingValue->value,
        ]);
        $this->assertDatabaseMissing('measurements', ['sensor_sn' => 'BAT-1']);
    }

    public function test_open_meteo_converts_local_times_and_deduplicates_before_upserting(): void
    {
        $station = $this->station('open_meteo');
        $first = $station->rawData()->create([
            'payload' => [
                'timezone' => 'America/Sao_Paulo',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-10-13T13:45', '2026-10-13T13:45', '2026-10-13T14:00'],
                    'temperature_2m' => [24.4, 24.5, 24.2],
                    'shortwave_radiation' => [607, 600, 521],
                ],
                'minutely_15_units' => ['temperature_2m' => '°C', 'shortwave_radiation' => 'W/m²'],
            ],
        ]);
        $second = $station->rawData()->create([
            'payload' => [
                'timezone' => 'America/Sao_Paulo',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-10-13T14:30'],
                    'temperature_2m' => [24.0],
                    'shortwave_radiation' => [500],
                ],
                'minutely_15_units' => ['temperature_2m' => '°C', 'shortwave_radiation' => 'W/m²'],
            ],
        ]);

        $this->classify($first);
        $this->classify($second);

        $temperature = Measurement::query()->where('sensor_sn', 'open-meteo-temp-sensor')->orderBy('timestamp')->get();
        $this->assertCount(4, $temperature);
        $this->assertSame('2026-10-13 16:45:00', $temperature[0]->getRawOriginal('timestamp'));
        $this->assertSame(24.5, (float) $temperature[0]->value);

        $gap = $temperature->firstWhere('quality_flag', QualityFlag::Missing);
        $this->assertSame('2026-10-13 17:15:00', $gap->getRawOriginal('timestamp'));
        $this->assertSame(8, Measurement::count());
    }

    public function test_open_meteo_uses_the_payload_offset_when_its_timezone_is_invalid(): void
    {
        $station = $this->station('open_meteo');
        $rawData = $station->rawData()->create([
            'payload' => [
                'timezone' => 'Invalid/Timezone',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-10-13T13:45'],
                    'temperature_2m' => [24.4],
                ],
            ],
        ]);

        $series = app(PayloadReader::class)->seriesFrom($rawData);

        $this->assertSame(
            '2026-10-13 16:45:00',
            array_values($series)[0][0]->timestamp->format('Y-m-d H:i:s'),
        );
    }

    public function test_wunderground_uses_its_profile_and_allows_observation_time_drift(): void
    {
        $station = $this->station('wunderground', 'IIJU2');
        $first = $station->rawData()->create(['payload' => $this->wundergroundPayload('2026-11-11T15:00:00Z', 20.0)]);
        $second = $station->rawData()->create(['payload' => $this->wundergroundPayload('2026-11-11T15:27:00Z', 27.0)]);

        $this->classify($first);
        $this->classify($second);

        $this->assertSame(2, Measurement::count());
        $this->assertDatabaseHas('measurements', [
            'sensor_sn' => 'wunderground-temp-sensor',
            'timestamp' => '2026-11-11 15:27:00',
            'quality_flag' => QualityFlag::Suspect->value,
            'flag_reason' => FlagReason::Spike->value,
        ]);
        $this->assertDatabaseMissing('measurements', ['sensor_sn' => 'wunderground-rad-sensor']);
    }

    public function test_wunderground_open_meteo_fallback_uses_the_open_meteo_profile(): void
    {
        $rawData = $this->station('wunderground', 'IIJU2')->rawData()->create([
            'payload' => [
                '_source_provider' => 'open_meteo',
                'timezone' => 'America/Sao_Paulo',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-10-13T13:45', '2026-10-13T14:15'],
                    'temperature_2m' => [24.0, 24.5],
                ],
            ],
        ]);

        $this->classify($rawData);

        $this->assertDatabaseHas('measurements', [
            'sensor_sn' => 'open-meteo-temp-sensor',
            'timestamp' => '2026-10-13 17:00:00',
            'quality_flag' => QualityFlag::Missing->value,
        ]);
        $this->assertDatabaseHas('measurements', [
            'sensor_sn' => 'open-meteo-temp-sensor',
            'timestamp' => '2026-10-13 17:15:00',
            'value' => 24.5,
        ]);
    }

    public function test_licor_open_meteo_backfill_uses_the_open_meteo_profile(): void
    {
        $rawData = $this->station('licor')->rawData()->create([
            'payload' => [
                '_source_provider' => 'open_meteo',
                'timezone' => 'America/Sao_Paulo',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-10-13T13:45'],
                    'temperature_2m' => [24.0],
                    'shortwave_radiation' => [500.0],
                ],
            ],
        ]);

        $this->classify($rawData);

        $this->assertDatabaseCount('measurements', 2);
        $this->assertDatabaseHas('measurements', [
            'station_id' => $rawData->station_id,
            'sensor_sn' => 'open-meteo-temp-sensor',
            'timestamp' => '2026-10-13 16:45:00',
            'value' => 24.0,
        ]);
        $this->assertDatabaseHas('measurements', [
            'station_id' => $rawData->station_id,
            'sensor_sn' => 'open-meteo-rad-sensor',
            'timestamp' => '2026-10-13 16:45:00',
            'value' => 500.0,
        ]);
    }

    public function test_cancelled_batch_does_not_write_measurements(): void
    {
        $rawData = $this->station('licor')->rawData()->create([
            'payload' => ['data' => [
                $this->licorReading('Air Temperature', 'TEMP-1', '°C', 25.0, '2026-10-09 15:00:00Z'),
            ]],
        ]);
        $job = new ClassifyStationData($rawData);
        $job->withFakeBatch(cancelledAt: CarbonImmutable::now());

        app()->call([$job, 'handle']);

        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_unsupported_provider_is_failed_without_creating_measurements(): void
    {
        $rawData = $this->station('unknown')->rawData()->create(['payload' => []]);
        $job = (new ClassifyStationData($rawData))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertFailed();
        $this->assertDatabaseCount('measurements', 0);
    }

    private function station(string $provider, ?string $loggers = null): Station
    {
        return Station::forceCreate([
            'name' => "test-{$provider}",
            'api_url' => 'https://example.test',
            'provider' => $provider,
            'loggers' => $loggers,
            'is_active' => true,
        ]);
    }

    private function classify(StationRawData $rawData): void
    {
        app()->call([new ClassifyStationData($rawData), 'handle']);
    }

    private function licorReading(string $type, string $sensor, string $unit, ?float $value, string $timestamp): array
    {
        return [
            'unit' => $unit,
            'value' => $value,
            'data_type' => 'CURRENT',
            'logger_sn' => 'LOGGER-1',
            'sensor_sn' => $sensor,
            'timestamp' => $timestamp,
            'sensor_measurement_type' => $type,
        ];
    }

    private function wundergroundPayload(string $timestamp, float $temperature): array
    {
        return ['observations' => [[
            'stationID' => 'IIJU2',
            'obsTimeUtc' => $timestamp,
            'solarRadiation' => null,
            'metric' => ['temp' => $temperature],
        ]]];
    }
}
