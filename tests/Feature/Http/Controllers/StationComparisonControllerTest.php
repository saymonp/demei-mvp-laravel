<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\QualityFlag;
use App\Models\Measurement;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StationComparisonControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_comparison_requires_authentication(): void
    {
        $this->get(route('stations.compare'))
            ->assertRedirect(route('login'));
    }

    public function test_quality_override_requires_authentication(): void
    {
        $station = $this->createStation('Ijuí');

        $this->post(route('stations.measurements.override'), [
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => '2026-10-14T12:00:00Z',
            'quality_flag' => QualityFlag::Invalid->value,
        ])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('measurement_overrides', 0);
    }

    public function test_authenticated_user_receives_selected_station_measurements(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');
        $measurement = $this->createMeasurement($station, '2026-10-14 12:00:00');
        $this->createMeasurement($station, '2026-10-14 12:00:00', 'AVERAGE');

        $response = $this->actingAs($user)
            ->get(route('stations.compare', [
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-10-14',
                'end' => '2026-10-14',
            ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('StationComparison')
            ->where('selectedStationIds', [$station->id, $secondStation->id])
            ->has("series.{$station->id}", 1)
            ->where("series.{$station->id}.0.measurement_id", $measurement->id)
            ->where("series.{$station->id}.0.value", 33.25));
    }

    public function test_long_comparison_period_defers_series_loading_to_chunks(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');
        $this->createMeasurement($station, '2026-09-30 12:00:00');

        $this->actingAs($user)
            ->get(route('stations.compare', [
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-06-01',
                'end' => '2026-09-30',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('requiresChunking', true)
                ->where('series', [])
                ->where('seriesB', []));
    }

    public function test_30_day_comparison_period_defers_series_loading_to_chunks(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');
        $this->createMeasurement($station, '2026-10-30 12:00:00');

        $this->actingAs($user)
            ->get(route('stations.compare', [
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-10-01',
                'end' => '2026-10-30',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('requiresChunking', true)
                ->where('series', []));
    }

    public function test_comparison_series_endpoint_returns_the_requested_seven_day_chunk(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');
        $measurement = $this->createMeasurement($station, '2026-07-07 12:00:00');

        $this->actingAs($user)
            ->getJson(route('stations.compare.series', [
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-07-01',
                'end' => '2026-07-07',
            ]))
            ->assertOk()
            ->assertJsonPath("series.{$station->id}.0.measurement_id", $measurement->id);
    }

    public function test_comparison_series_endpoint_rejects_chunks_longer_than_seven_days(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');

        $this->actingAs($user)
            ->getJson(route('stations.compare.series', [
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-07-01',
                'end' => '2026-07-08',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');
    }

    public function test_comparison_series_endpoint_requires_authentication(): void
    {
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');

        $this->getJson(route('stations.compare.series', [
            'stations' => [$station->id, $secondStation->id],
            'start' => '2026-07-01',
            'end' => '2026-07-01',
        ]))
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_save_a_quality_override_with_its_original_flag(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $measurement = $this->createMeasurement($station, '2026-10-14 12:00:00');

        $this->actingAs($user)
            ->post(route('stations.measurements.override'), [
                'measurement_id' => $measurement->id,
                'quality_flag' => QualityFlag::Invalid->value,
                'note' => 'Conferido manualmente',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('measurement_overrides', [
            'measurement_id' => $measurement->id,
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => '2026-10-14 12:00:00',
            'original_flag' => QualityFlag::Valid->value,
            'quality_flag' => QualityFlag::Invalid->value,
            'user_id' => $user->id,
            'note' => 'Conferido manualmente',
        ]);
    }

    public function test_updating_a_quality_override_preserves_its_creation_time(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $measurement = $this->createMeasurement($station, '2026-10-14 12:00:00');
        $createdAt = '2026-10-01 09:00:00';
        DB::table('measurement_overrides')->insert([
            'measurement_id' => $measurement->id,
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => '2026-10-14 12:00:00',
            'original_flag' => QualityFlag::Valid->value,
            'quality_flag' => QualityFlag::Suspect->value,
            'user_id' => $user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->actingAs($user)
            ->post(route('stations.measurements.override'), [
                'measurement_id' => $measurement->id,
                'quality_flag' => QualityFlag::Invalid->value,
                'note' => 'Revisado novamente',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('measurement_overrides', [
            'measurement_id' => $measurement->id,
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => '2026-10-14 12:00:00',
            'original_flag' => QualityFlag::Valid->value,
            'quality_flag' => QualityFlag::Invalid->value,
            'created_at' => $createdAt,
            'note' => 'Revisado novamente',
        ]);
        $this->assertDatabaseCount('measurement_overrides', 1);
    }

    public function test_invalid_quality_override_is_not_persisted(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $measurement = $this->createMeasurement($station, '2026-10-14 12:00:00');

        $this->actingAs($user)
            ->from(route('stations.compare'))
            ->post(route('stations.measurements.override'), [
                'measurement_id' => $measurement->id,
                'quality_flag' => 4,
            ])
            ->assertSessionHasErrors('quality_flag');

        $this->assertDatabaseCount('measurement_overrides', 0);
    }

    public function test_authenticated_user_can_remove_a_quality_override(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $timestamp = '2026-10-14 12:00:00';
        $measurement = $this->createMeasurement($station, $timestamp);
        DB::table('measurement_overrides')->insert([
            'measurement_id' => $measurement->id,
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => $timestamp,
            'original_flag' => QualityFlag::Valid->value,
            'quality_flag' => QualityFlag::Invalid->value,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('stations.measurements.override.destroy'), [
                'measurement_id' => $measurement->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('measurement_overrides', [
            'measurement_id' => $measurement->id,
            'station_id' => $station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'timestamp' => $timestamp,
        ]);
    }

    public function test_override_updates_and_removes_only_the_selected_measurement_when_timestamps_match(): void
    {
        $user = User::factory()->create();
        $station = $this->createStation('Ijuí');
        $secondStation = $this->createStation('Panambi');
        $current = $this->createMeasurement($station, '2026-10-14 12:00:00');
        $average = $this->createMeasurement($station, '2026-10-14 12:00:00', 'AVERAGE');

        $this->actingAs($user)
            ->post(route('stations.measurements.override'), [
                'measurement_id' => $current->id,
                'quality_flag' => QualityFlag::Invalid->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('measurement_overrides', [
            'measurement_id' => $current->id,
            'quality_flag' => QualityFlag::Invalid->value,
        ]);
        $this->assertDatabaseMissing('measurement_overrides', [
            'measurement_id' => $average->id,
        ]);

        $this->actingAs($user)
            ->post(route('stations.measurements.override'), [
                'measurement_id' => $average->id,
                'quality_flag' => QualityFlag::Suspect->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('measurement_overrides', [
            'measurement_id' => $current->id,
            'quality_flag' => QualityFlag::Invalid->value,
        ]);
        $this->assertDatabaseHas('measurement_overrides', [
            'measurement_id' => $average->id,
            'quality_flag' => QualityFlag::Suspect->value,
        ]);

        $this->actingAs($user)
            ->get(route('stations.compare', [
                'stations' => [$station->id, $secondStation->id],
                'start' => '2026-10-14',
                'end' => '2026-10-14',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has("series.{$station->id}", 1)
                ->where("series.{$station->id}.0.measurement_id", $current->id)
                ->where("overrides.{$station->id}|measurement|{$current->id}", QualityFlag::Invalid->value)
                ->where("overrides.{$station->id}|measurement|{$average->id}", QualityFlag::Suspect->value));

        $this->actingAs($user)
            ->delete(route('stations.measurements.override.destroy'), [
                'measurement_id' => $current->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('measurement_overrides', [
            'measurement_id' => $current->id,
        ]);
        $this->assertDatabaseHas('measurement_overrides', [
            'measurement_id' => $average->id,
            'quality_flag' => QualityFlag::Suspect->value,
        ]);
    }

    private function createStation(string $name): Station
    {
        return Station::query()->create([
            'name' => $name,
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
    }

    private function createMeasurement(Station $station, string $timestamp, string $dataType = 'CURRENT'): Measurement
    {
        return Measurement::query()->create([
            'station_id' => $station->id,
            'logger_sn' => 'logger-1',
            'sensor_sn' => 'sensor-'.$station->id,
            'sensor_measurement_type' => 'Air Temperature',
            'data_type' => $dataType,
            'timestamp' => $timestamp,
            'value' => 33.25,
            'unit' => '°C',
            'quality_flag' => QualityFlag::Valid,
        ]);
    }
}
