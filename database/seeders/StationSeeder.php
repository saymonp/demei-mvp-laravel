<?php

namespace Database\Seeders;

use App\Models\Station;
use Illuminate\Database\Seeder;

class StationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Station::updateOrCreate(
            // Condição de busca (procura pela estação com este nome)
            ['name' => 'unijui_gaic'], 
            
            // Dados a serem inseridos ou atualizados
            [
                'api_url' => env('LICOR_API_URL', 'https://api.licor.cloud/v1/data'),
                'provider' => 'licor',
                'bearer_token' => env('LICOR_BEARER_TOKEN'),
                'loggers' => env('LICOR_LOGGERS', '20770262,20748028'),
                'is_active' => true,
            ]
        );

        Station::updateOrCreate(
            // Condição de busca (procura pela estação com este nome)
            ['name' => 'open_meteo'], 
            
            // Dados a serem inseridos ou atualizados
            [
                'api_url' => env('OPENMETEO_API_URL', 'https://api.open-meteo.com/v1/forecast'),
                'provider' => 'open_meteo',
                'bearer_token' => env('OPENMETEO_BEARER_TOKEN', ""),
                'latitude' => env('OPENMETEO_LATITUDE', '-28.30'),
                'longitude' => env('OPENMETEO_LONGITUDE', '-54.26'),
                'is_active' => true,
            ]
        );
    }
}https://api.open-meteo.com/v1/forecast?latitude=-28.30&longitude=-54.26&minutely_15=temperature_2m,shortwave_radiation&past_minutely_15=2&forecast_minutely_15=0&timezone=America%2FSao_Paulo