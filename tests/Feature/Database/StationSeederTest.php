<?php

namespace Tests\Feature\Database;

use App\Models\Station;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_seeder_creates_the_two_weather_underground_stations(): void
    {
        $this->seed(StationSeeder::class);

        $this->assertDatabaseHas('stations', [
            'name' => 'ijui_pws_iiiju3',
            'api_url' => 'https://www.wunderground.com/dashboard/pws/IIJU3',
            'provider' => 'wunderground',
            'loggers' => 'IIJU3',
            'latitude' => -28.353,
            'longitude' => -53.836,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('stations', [
            'name' => 'ijui_pws_iiiju2',
            'api_url' => 'https://www.wunderground.com/dashboard/pws/IIJU2',
            'provider' => 'wunderground',
            'loggers' => 'IIJU2',
            'latitude' => -28.391,
            'longitude' => -53.926,
            'is_active' => true,
        ]);
    }

    public function test_open_meteo_uses_the_licor_gaic_coordinates_when_reseeded(): void
    {
        Station::query()->create([
            'name' => 'open_meteo',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
            'latitude' => -28.30,
            'longitude' => -54.26,
        ]);

        $this->seed(StationSeeder::class);

        $licor = Station::query()->where('name', 'unijui_gaic')->firstOrFail();
        $openMeteo = Station::query()->where('name', 'open_meteo')->firstOrFail();

        $this->assertSame((float) $licor->latitude, (float) $openMeteo->latitude);
        $this->assertSame((float) $licor->longitude, (float) $openMeteo->longitude);
    }

    public function test_migration_copies_existing_licor_coordinates_to_open_meteo(): void
    {
        Station::query()->create([
            'name' => 'unijui_gaic',
            'api_url' => 'https://example.test/licor',
            'provider' => 'licor',
            'latitude' => -28.39,
            'longitude' => -53.95,
        ]);
        Station::query()->create([
            'name' => 'open_meteo',
            'api_url' => 'https://example.test/open-meteo',
            'provider' => 'open_meteo',
            'latitude' => -28.30,
            'longitude' => -54.26,
        ]);

        $migration = require database_path('migrations/2026_11_12_200246_sync_open_meteo_coordinates_with_licor_gaic.php');
        $migration->up();

        $this->assertDatabaseHas('stations', [
            'name' => 'open_meteo',
            'latitude' => -28.39,
            'longitude' => -53.95,
        ]);
    }
}
