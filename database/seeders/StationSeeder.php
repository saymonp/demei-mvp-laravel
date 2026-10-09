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
                'bearer_token' => env('LICOR_BEARER_TOKEN'),
                'loggers' => env('LICOR_LOGGERS', '20770262,20748028'),
                'is_active' => true,
            ]
        );
    }
}