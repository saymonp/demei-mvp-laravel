<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchClassificationBatch;
use App\Jobs\FetchStationData;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FetchStationDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_wunderground_fetch_uses_configured_key_and_station_id(): void
    {
        config([
            'services.wunderground.key' => 'test-api-key',
            'services.wunderground.url' => 'https://api.weather.com/v2/pws/observations/current',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.weather.com/*' => Http::response(['observations' => []]),
        ]);
        Bus::fake();
        $station = $this->station();

        $job = new FetchStationData(
            $station,
            Carbon::parse('2026-11-11 15:00:00'),
            Carbon::parse('2026-11-11 15:30:00'),
        );
        app()->call([$job, 'handle']);

        Http::assertSent(static function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_PATH) === '/v2/pws/observations/current'
                && $query === [
                    'stationId' => 'IIJU2',
                    'format' => 'json',
                    'units' => 'm',
                    'apiKey' => 'test-api-key',
                ];
        });
        $this->assertDatabaseCount('station_raw_data', 1);
        Bus::assertDispatched(DispatchClassificationBatch::class);
    }

    public function test_wunderground_fetch_fails_when_the_api_key_is_missing(): void
    {
        config(['services.wunderground.key' => null]);
        Http::preventStrayRequests();
        $job = new FetchStationData($this->station());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A chave WUNDERGROUND_PUBLIC_KEY não está configurada.');

        app()->call([$job, 'handle']);
    }

    #[DataProvider('authorizationFailureStatuses')]
    public function test_wunderground_falls_back_to_open_meteo_when_authentication_is_rejected(int $status): void
    {
        config([
            'services.wunderground.key' => 'test-api-key',
            'services.wunderground.url' => 'https://api.weather.com/v2/pws/observations/current',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.weather.com/*' => Http::response([], $status),
            'api.open-meteo.com/*' => Http::response([
                'timezone' => 'America/Sao_Paulo',
                'utc_offset_seconds' => -10800,
                'minutely_15' => [
                    'time' => ['2026-11-11T12:00'],
                    'temperature_2m' => [24.5],
                    'shortwave_radiation' => [500],
                ],
                'minutely_15_units' => [
                    'temperature_2m' => '°C',
                    'shortwave_radiation' => 'W/m²',
                ],
            ]),
        ]);
        Bus::fake();
        $station = $this->station();

        $job = new FetchStationData(
            $station,
            Carbon::parse('2026-11-11 15:00:00'),
            Carbon::parse('2026-11-11 15:30:00'),
        );
        app()->call([$job, 'handle']);

        Http::assertSentCount(2);
        Http::assertSent(static function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_HOST) === 'api.open-meteo.com'
                && $query['latitude'] === '-28.353'
                && $query['longitude'] === '-53.836'
                && $query['minutely_15'] === 'temperature_2m,shortwave_radiation';
        });
        $this->assertSame('open_meteo', $station->rawData()->sole()->payload['_source_provider']);
        Bus::assertDispatched(DispatchClassificationBatch::class);
    }

    public function test_wunderground_does_not_fall_back_on_server_errors(): void
    {
        config([
            'services.wunderground.key' => 'test-api-key',
            'services.wunderground.url' => 'https://api.weather.com/v2/pws/observations/current',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.weather.com/*' => Http::response([], 500),
        ]);
        $job = new FetchStationData($this->station());

        try {
            app()->call([$job, 'handle']);
            $this->fail('Expected the Weather Underground server error to be thrown.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        Http::assertSentCount(1);
        $this->assertDatabaseCount('station_raw_data', 0);
    }

    public static function authorizationFailureStatuses(): array
    {
        return [
            'unauthorized' => [401],
            'forbidden' => [403],
        ];
    }

    private function station(): Station
    {
        return Station::forceCreate([
            'name' => 'wunderground-test',
            'api_url' => 'https://example.test/unused',
            'provider' => 'wunderground',
            'loggers' => 'IIJU2',
            'latitude' => -28.353,
            'longitude' => -53.836,
            'is_active' => true,
        ]);
    }
}
