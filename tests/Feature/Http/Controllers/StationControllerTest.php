<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Station;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_classification_version_returns_the_marker_for_the_selected_station_and_date(): void
    {
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);
        Cache::put("weather.classified.{$station->id}.2026-10-14", 'batch-id', now()->addDay());

        $this->getJson("/station-dashboard/version?station_id={$station->id}&date=2026-10-14")
            ->assertOk()
            ->assertExactJson(['batch_id' => 'batch-id']);
    }

    public function test_classification_version_rejects_an_unknown_station(): void
    {
        $this->getJson('/station-dashboard/version?station_id=999&date=2026-10-14')
            ->assertUnprocessable();
    }
}
