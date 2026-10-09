<?php

namespace App\Jobs;

use App\Models\StationRawData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClassifyStationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public StationRawData $rawData)
    {
    }

    public function handle(): void
    {
        // $payload = $this->rawData->payload;
        // Aqui desenvolveremos a lógica de parse, extração de variáveis (temperatura, vento, etc)
        // e salvamento na tabela estruturada para o frontend (Vue/Inertia).
    }
}