<?php

namespace App\Jobs;

use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchStationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60; // Timeout de 60 segundos na fila do Redis
    public $tries = 3;    // Tenta 3 vezes antes de marcar como failed_job

    public function __construct(
        public Station $station,
        public ?Carbon $start = null,
        public ?Carbon $end = null
    ) {
        // Se as datas não forem passadas, assume os últimos 30 minutos (operação em tempo real)
        $this->end = $end ?? now();
        $this->start = $start ?? now()->subMinutes(30);
    }

    public function handle(): void
    {
        $startStr = $this->start->format('Y-m-d H:i:s');
        $endStr = $this->end->format('Y-m-d H:i:s');

        $response = Http::withToken($this->station->bearer_token)
            ->acceptJson()
            ->timeout(30)
            ->get($this->station->api_url, [
                'loggers' => $this->station->loggers,
                'start_date_time' => $startStr,
                'end_date_time' => $endStr,
            ]);

        if ($response->successful()) {
            // Salva o JSON bruto no PostgreSQL
            $rawData = $this->station->rawData()->create([
                'payload' => $response->json(),
                'fetched_start_at' => $this->start,
                'fetched_end_at' => $this->end,
            ]);

            // Dispara o próximo Job na fila e repassa o dado salvo
            ClassifyStationData::dispatch($rawData);
            
            Log::info("Dados da estação {$this->station->name} coletados com sucesso.");
        } else {
            // Lança uma exceção para o Horizon registrar a falha e acionar o retry (tries = 3)
            $response->throw(); 
        }
    }
}