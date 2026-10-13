<?php

namespace App\Services\Quality;

use App\Models\StationRawData;
use App\Services\Quality\ProviderProfile;

class PayloadReader
{
    /**
     * Extrai e padroniza as leituras brutas com base no perfil da estação.
     */
    public function seriesFrom(StationRawData $rawData): array
    {
        $profile = ProviderProfile::for($rawData->station->provider);

        $payload = is_array($rawData->payload) 
            ? $rawData->payload 
            : (json_decode((string) $rawData->payload, true) ?? []);

        return match ($profile->provider) {
            'licor' => $this->parseLicor($payload),
            'open_meteo' => $this->parseOpenMeteo($payload),
            default => throw new UnsupportedProviderException($profile->provider),
        };
    }

    private function parseLicor(array $payload): array
    {
        $readings = [];

        foreach ($payload['data'] ?? [] as $item) {
            $readings[] = new Reading(
                loggerSn: $item['logger_sn'] ?? 'unknown',
                sensorSn: $item['sensor_sn'] ?? 'unknown',
                type: $item['sensor_measurement_type'] ?? 'Unknown',
                dataType: $item['data_type'] ?? 'CURRENT',
                timestamp: \Carbon\CarbonImmutable::parse($item['timestamp'], 'UTC'),
                value: (float) $item['value'],
                unit: $item['unit'] ?? '',
            );
        }

        // Agrupa por sensor e tipo de dado
        return collect($readings)
            ->groupBy(fn (Reading $r) => $r->sensorSn . '-' . $r->dataType)
            ->all();
    }

    private function parseOpenMeteo(array $payload): array
    {
        $readings = [];

        // Exemplo de adaptação para o JSON da Open-Meteo
        foreach ($payload['minutely_15']['time'] ?? [] as $index => $timeStr) {
            // Adapte conforme os campos retornados pela Open-Meteo
            $readings[] = new Reading(
                loggerSn: 'open-meteo-logger',
                sensorSn: 'open-meteo-sensor',
                type: 'Air Temperature',
                dataType: 'CURRENT',
                timestamp: \Carbon\CarbonImmutable::parse($timeStr, 'UTC'),
                value: (float) ($payload['minutely_15']['temperature_2m'][$index] ?? 0),
                unit: '°C',
            );
        }

        return collect($readings)
            ->groupBy(fn (Reading $r) => $r->sensorSn . '-' . $r->dataType)
            ->all();
    }
}