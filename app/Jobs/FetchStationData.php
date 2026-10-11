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
use RuntimeException;

class FetchStationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;

    public function __construct(
        public Station $station,
        public ?Carbon $start = null,
        public ?Carbon $end = null
    ) {
        $this->end = $end ?? now();

        if (!$start) {
            $lastRecord = $station->rawData()
                ->latest('fetched_end_at')
                ->first();

            $this->start = $lastRecord
                ? Carbon::parse($lastRecord->fetched_end_at)
                : now()->subMinutes(30);
        } else {
            $this->start = $start;
        }
    }

    public function handle(): void
    {
        $payload = $this->fetchPayload();

        $rawData = $this->station->rawData()->create([
            'payload' => $payload,
            'fetched_start_at' => $this->start,
            'fetched_end_at' => $this->end,
        ]);

        ClassifyStationData::dispatch($rawData);

        Log::info(
            "Dados da estação {$this->station->name} coletados com sucesso."
        );
    }

    private function fetchPayload(): array
    {
        return match ($this->station->provider) {
            'licor' => $this->fetchLicor(),
            'open_meteo' => $this->fetchOpenMeteo(),
            default => throw new RuntimeException(
                "Fonte não suportada: {$this->station->provider}"
            ),
        };
    }

    private function fetchLicor(): array
    {
        $response = Http::withToken($this->station->bearer_token)
            ->acceptJson()
            ->timeout(30)
            ->get($this->station->api_url, [
                'loggers' => $this->station->loggers,
                'start_date_time' => $this->start->format('Y-m-d H:i:s'),
                'end_date_time' => $this->end->format('Y-m-d H:i:s'),
            ]);

        return $response->throw()->json();
    }

    private function fetchOpenMeteo(): array
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->get($this->station->api_url, [
                'latitude' => $this->station->latitude,
                'longitude' => $this->station->longitude,
                'minutely_15' => 'temperature_2m,shortwave_radiation',
                'past_minutely_15' => 2,
                'forecast_minutely_15' => 0,
                'timezone' => 'America/Sao_Paulo',
            ]);

        return $response->throw()->json();
    }
}
