<?php

namespace Tests\Feature\Database;

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
}
