<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StationLatestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_the_latest_valid_current_reading_per_station(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 16:00:00', 'UTC'));

        $station = Station::query()->create([
            'name' => 'ijui_pws_iiiju2',
            'api_url' => 'https://example.test/weather',
            'provider' => 'wunderground',
        ]);

        DB::table('measurements')->insert([
            $this->measurement($station->id, 'Air Temperature', 24.8, '2026-11-12 15:50:00'),
            $this->measurement($station->id, 'Air Temperature', 22.0, '2026-11-12 15:20:00'),
            $this->measurement($station->id, 'Solar Radiation', 812.3, '2026-11-12 15:45:00'),
            $this->measurement($station->id, 'Solar Radiation', 1340.0, '2026-11-12 15:45:00', 1, 'CURRENT', 'S-2'),
            $this->measurement($station->id, 'Solar Radiation', 100.0, '2026-11-12 07:00:00', 0, 'CURRENT', 'S-3'),
            $this->measurement($station->id, 'Air Temperature', 30.0, '2026-11-12 15:55:00', 0, 'AVERAGE', 'S-4'),
            $this->measurement($station->id, 'Solar Radiation', null, '2026-11-12 15:59:00', 0, 'CURRENT', 'S-5'),
        ]);

        $data = $this->actingAs(User::factory()->create())
            ->getJson(route('stations.latest'))
            ->assertOk()
            ->json("data.{$station->id}");

        $this->assertSame(24.8, $data['temperature']);
        $this->assertSame(812.3, $data['radiation']);
        $this->assertSame('2026-11-12T15:50:00+00:00', $data['observed_at']);
    }

    public function test_omits_stations_with_only_stale_readings(): void
    {
        $station = Station::query()->create([
            'name' => 'stale_station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'wunderground',
        ]);
        DB::table('measurements')->insert(
            $this->measurement($station->id, 'Air Temperature', 18.0, '2026-11-12 07:00:00'),
        );

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('stations.latest'))
            ->assertOk();

        $this->assertSame('{"data":{}}', $response->getContent());
    }

    public function test_requires_login(): void
    {
        $this->getJson(route('stations.latest'))->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function measurement(
        int $stationId,
        string $type,
        ?float $value,
        string $at,
        int $flag = 0,
        string $dataType = 'CURRENT',
        string $sensor = 'S-1',
    ): array {
        return [
            'station_id' => $stationId,
            'logger_sn' => 'L-1',
            'sensor_sn' => $sensor.$type,
            'sensor_measurement_type' => $type,
            'data_type' => $dataType,
            'timestamp' => $at,
            'value' => $value,
            'unit' => $type === 'Air Temperature' ? '°C' : 'W/m²',
            'quality_flag' => $flag,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
