<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\GenerateStationReport;
use App\Models\Station;
use App\Models\StationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class StationReportControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_request_to_report_index_returns_401(): void
    {
        $this->getJson('/station-dashboard/reports?station_id=1&date=2026-10-14')
            ->assertUnauthorized();
    }

    public function test_valid_request_creates_a_report_and_dispatches_it_to_the_reports_queue(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        Bus::fake([GenerateStationReport::class]);

        $response = $this->actingAs($user)->postJson('/station-dashboard/reports', [
            'station_id' => $station->id,
            'date' => '2026-10-14',
            'format' => 'pdf',
            'notes' => 'Sensor cleaned at 10:00.',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('reference_date', '2026-10-14');

        $report = StationReport::query()->sole();
        $this->assertSame($user->id, $report->user_id);
        $this->assertSame('Sensor cleaned at 10:00.', $report->notes);
        Bus::assertDispatched(
            GenerateStationReport::class,
            fn (GenerateStationReport $job): bool => $job->queue === 'reports'
                && $job->report->is($report),
        );
    }

    public function test_invalid_format_returns_422_without_creating_or_dispatching_a_report(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        Bus::fake([GenerateStationReport::class]);

        $this->actingAs($user)->postJson('/station-dashboard/reports', [
            'station_id' => $station->id,
            'date' => '2026-10-14',
            'format' => 'doc',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('format');

        $this->assertDatabaseCount('station_reports', 0);
        Bus::assertNotDispatched(GenerateStationReport::class);
    }

    public function test_another_users_report_and_download_return_404(): void
    {
        $requestingUser = User::factory()->create();
        $owner = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        $report = StationReport::query()->create([
            'station_id' => $station->id,
            'user_id' => $owner->id,
            'reference_date' => '2026-10-14',
            'format' => 'pdf',
            'status' => 'ready',
            'path' => 'reports/1/private.pdf',
        ]);

        $this->actingAs($requestingUser)
            ->getJson("/station-dashboard/reports/{$report->id}")
            ->assertNotFound();

        $this->get("/station-dashboard/reports/{$report->id}/download")
            ->assertNotFound();
    }

    public function test_reports_index_only_returns_the_requesting_users_reports(): void
    {
        $requestingUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        $ownedReport = StationReport::query()->create([
            'station_id' => $station->id,
            'user_id' => $requestingUser->id,
            'reference_date' => '2026-10-14',
            'format' => 'pdf',
        ]);
        StationReport::query()->create([
            'station_id' => $station->id,
            'user_id' => $otherUser->id,
            'reference_date' => '2026-10-14',
            'format' => 'csv',
        ]);

        $response = $this->actingAs($requestingUser)
            ->getJson("/station-dashboard/reports?station_id={$station->id}&date=2026-10-14");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownedReport->id);
    }
}
