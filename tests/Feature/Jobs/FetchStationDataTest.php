<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DispatchClassificationBatch;
use App\Jobs\FetchStationData;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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

    private function station(): Station
    {
        return Station::forceCreate([
            'name' => 'wunderground-test',
            'api_url' => 'https://example.test/unused',
            'provider' => 'wunderground',
            'loggers' => 'IIJU2',
            'is_active' => true,
        ]);
    }
}
