<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
