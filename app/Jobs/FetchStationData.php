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
use Throwable;

class FetchStationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const OPEN_METEO_API_URL = 'https://api.open-meteo.com/v1/forecast';

    private const OPEN_METEO_MAX_PAST_STEPS = 96;

    public $timeout = 60;

    public $tries = 3;

    public function __construct(
        public Station $station,
        public ?Carbon $start = null,
        public ?Carbon $end = null
    ) {
        $this->end = $end ?? now();

        if (! $start) {
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

        DispatchClassificationBatch::dispatch([$rawData->id]);

        Log::info(
            "Dados da estação {$this->station->name} coletados com sucesso."
        );
    }

    private function fetchPayload(): array
    {
        return match ($this->station->provider) {
            'licor' => $this->fetchLicor(),
            'open_meteo' => $this->fetchOpenMeteo($this->station->api_url),
            'wunderground' => $this->fetchWunderground(),
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

    private function fetchOpenMeteo(string $url): array
    {
        $requestedSteps = (int) ceil(abs($this->start->diffInMinutes($this->end)) / 15);
        $steps = $requestedSteps + 2;
        $pastSteps = min(max($steps, 2), self::OPEN_METEO_MAX_PAST_STEPS);

        $response = Http::acceptJson()
            ->timeout(30)
            ->get($url, [
                'latitude' => $this->station->latitude,
                'longitude' => $this->station->longitude,
                'minutely_15' => 'temperature_2m,shortwave_radiation',
                'past_minutely_15' => $pastSteps,
                'forecast_minutely_15' => 0,
                'timezone' => 'America/Sao_Paulo',
            ]);

        $payload = $response->throw()->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Open-Meteo retornou um payload inválido.');
        }

        if ($requestedSteps > self::OPEN_METEO_MAX_PAST_STEPS) {
            Log::warning(
                "Open-Meteo limitou a recuperação da estação {$this->station->name} às últimas 24 horas.",
                [
                    'station_id' => $this->station->id,
                    'requested_start' => $this->start->toIso8601String(),
                    'requested_end' => $this->end->toIso8601String(),
                    'past_steps_requested' => $requestedSteps,
                    'past_steps_with_padding' => $steps,
                    'past_steps_used' => $pastSteps,
                ],
            );
            $payload['_backfill_limited'] = true;
        }

        return $payload;
    }

    private function fetchWunderground(): array
    {
        $apiKey = config('services.wunderground.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('A chave WUNDERGROUND_PUBLIC_KEY não está configurada.');
        }

        if (! is_string($this->station->loggers) || $this->station->loggers === '') {
            throw new RuntimeException("A estação {$this->station->name} não tem um station ID configurado.");
        }

        $response = Http::acceptJson()
            ->timeout(30)
            ->get(config('services.wunderground.url'), [
                'stationId' => $this->station->loggers,
                'format' => 'json',
                'units' => 'm',
                'apiKey' => $apiKey,
            ]);

        if (in_array($response->status(), [401, 403], true)) {
            Log::warning(
                "Weather Underground recusou a autenticação da estação {$this->station->name}; usando Open-Meteo.",
                [
                    'station_id' => $this->station->id,
                    'http_status' => $response->status(),
                ],
            );

            return [
                ...$this->fetchOpenMeteo(self::OPEN_METEO_API_URL),
                '_source_provider' => 'open_meteo',
            ];
        }

        $payload = $response->throw()->json();

        if (is_array($payload) && $this->wundergroundCoversRequestedRange($payload)) {
            return $payload;
        }

        Log::warning(
            "Weather Underground não cobriu o intervalo da estação {$this->station->name}; usando Open-Meteo.",
            [
                'station_id' => $this->station->id,
                'requested_start' => $this->start->toIso8601String(),
                'requested_end' => $this->end->toIso8601String(),
            ],
        );

        return [
            ...$this->fetchOpenMeteo(self::OPEN_METEO_API_URL),
            '_source_provider' => 'open_meteo',
        ];
    }

    private function wundergroundCoversRequestedRange(array $payload): bool
    {
        $timestamps = [];
        $observations = is_array($payload['observations'] ?? null) ? $payload['observations'] : [];

        foreach ($observations as $observation) {
            if (! is_array($observation)) {
                continue;
            }

            try {
                if (! empty($observation['obsTimeUtc'])) {
                    $timestamps[] = Carbon::parse($observation['obsTimeUtc'], 'UTC')->timestamp;
                } elseif (isset($observation['epoch']) && is_numeric($observation['epoch'])) {
                    $timestamps[] = (int) $observation['epoch'];
                }
            } catch (Throwable) {
                continue;
            }
        }

        if ($timestamps === []) {
            return false;
        }

        $stepMinutes = (int) config('station-quality.providers.wunderground.step_minutes', 30);
        $startLimit = $this->start->copy()->addMinutes($stepMinutes)->timestamp;
        $endLimit = $this->end->copy()->subMinutes($stepMinutes)->timestamp;

        return min($timestamps) <= $startLimit && max($timestamps) >= $endLimit;
    }
}
