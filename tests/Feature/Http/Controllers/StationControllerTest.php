<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\QualityFlag;
use App\Models\Measurement;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_station_dashboard_requires_authentication(): void
    {
        $this->get('/station-dashboard')
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_name_is_shared_with_the_station_dashboard(): void
    {
        $user = User::factory()->create(['name' => 'Weather User']);

        $this->actingAs($user)
            ->get('/station-dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('StationDashboard')
                ->where('auth.user.name', 'Weather User'));
    }

    public function test_station_dashboard_includes_comparison_data_for_selected_stations_and_period(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');
        $measurement = Measurement::query()->create([
            'station_id' => $station->id,
            'logger_sn' => 'logger-1',
            'sensor_sn' => 'sensor-1',
            'sensor_measurement_type' => 'Air Temperature',
            'data_type' => 'CURRENT',
            'timestamp' => '2026-10-14 12:00:00',
            'value' => 28.5,
            'unit' => '°C',
            'quality_flag' => QualityFlag::Valid,
        ]);
        DB::table('measurement_overrides')->insert([
            'measurement_id' => $measurement->id,
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => '2026-10-14 12:00:00',
            'original_flag' => QualityFlag::Valid->value,
            'quality_flag' => QualityFlag::Suspect->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('station-dashboard', [
                'date' => '2026-10-14',
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-10-14',
                'end' => '2026-10-14',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('StationDashboard')
                ->where('selectedStationIds', [$station->id, $secondStation->id])
                ->where('periodA.start', '2026-10-14')
                ->where('periodA.end', '2026-10-14')
                ->where('measurements.0.measurement_id', $measurement->id)
                ->where('measurements.0.quality_flag', QualityFlag::Suspect->value)
                ->where('measurements.0.audited', true)
                ->where('measurements.0.original_flag', QualityFlag::Valid->value)
                ->where("series.{$station->id}.0.measurement_id", $measurement->id)
                ->where("overrides.{$station->id}|measurement|{$measurement->id}", QualityFlag::Suspect->value));
    }

    public function test_classification_version_returns_the_marker_for_the_selected_station_and_date(): void
    {
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        Cache::put("weather.classified.{$station->id}.2026-10-14", 'batch-id', now()->addDay());

        $this->actingAs(User::factory()->create())
            ->getJson("/station-dashboard/version?station_id={$station->id}&date=2026-10-14")
            ->assertOk()
            ->assertExactJson(['batch_id' => 'batch-id']);
    }

    public function test_classification_version_rejects_an_unknown_station(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/station-dashboard/version?station_id=999&date=2026-10-14')
            ->assertUnprocessable();
    }

    public function test_classification_version_requires_authentication(): void
    {
        $this->get('/station-dashboard/version?station_id=1&date=2026-10-14')
            ->assertRedirect(route('login'));
    }

    private function createStation(string $name): Station
    {
        return Station::query()->create([
            'name' => $name,
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
    }
}
