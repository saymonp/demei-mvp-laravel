<?php

namespace App\Services\Quality;

use App\Models\StationRawData;
use Carbon\CarbonImmutable;
use Throwable;

class PayloadReader
{
    public function __construct(private readonly UnitNormalizer $units) {}

    /**
     * Extrai e padroniza as leituras brutas com base no provedor da estação.
     * Só temperatura do ar e irradiação entram (Battery e o resto são ignorados).
     *
     * @return array<string, list<Reading>> séries em ordem cronológica, sem repetidas
     */
    public function seriesFrom(StationRawData $rawData): array
    {
        $profile = ProviderProfile::for($rawData->station->provider);
        $payload = $this->decode($rawData->payload);

        $readings = match ($profile->provider) {
            'licor' => $this->parseLicor($payload),
            'open_meteo' => $this->parseOpenMeteo($payload),
            'wunderground' => $this->parseWunderground($payload),
            default => throw new UnsupportedProviderException($profile->provider),
        };

        return $this->groupBySeries($readings);
    }

    private function decode(mixed $payload): array
    {
        for ($i = 0; $i < 2 && is_string($payload); $i++) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    }

    private function parseLicor(array $payload): array
    {
        $readings = [];

        foreach ($payload['data'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['sensor_measurement_type'] ?? null;

            if (! $this->isTracked($type) || empty($item['timestamp']) || empty($item['sensor_sn'])) {
                continue;
            }

            try {
                $timestamp = CarbonImmutable::parse($item['timestamp'], 'UTC')->utc();
            } catch (Throwable) {
                continue;
            }

            $readings[] = $this->reading(
                $type,
                (string) ($item['data_type'] ?? 'CURRENT'),
                (string) $item['sensor_sn'],
                (string) ($item['logger_sn'] ?? 'unknown'),
                $timestamp,
                $item['value'] ?? null,
                (string) ($item['unit'] ?? ''),
            );
        }

        return $readings;
    }

    private function parseOpenMeteo(array $payload): array
    {
        $block = is_array($payload['minutely_15'] ?? null) ? $payload['minutely_15'] : [];
        $units = $payload['minutely_15_units'] ?? [];
        $variables = [
            'temperature_2m' => [Reading::TEMPERATURE, 'open-meteo-temp-sensor', '°C'],
            'shortwave_radiation' => [Reading::RADIATION, 'open-meteo-rad-sensor', 'W/m²'],
        ];
        $readings = [];

        foreach ($block['time'] ?? [] as $index => $time) {
            $timestamp = $this->openMeteoTime($time, $payload);

            if ($timestamp === null) {
                continue;
            }

            foreach ($variables as $key => [$type, $sensorSn, $defaultUnit]) {
                if (! array_key_exists($key, $block)) {
                    continue;
                }

                $readings[] = $this->reading(
                    $type,
                    'CURRENT',
                    $sensorSn,
                    'open-meteo-logger',
                    $timestamp,
                    $block[$key][$index] ?? null,
                    (string) ($units[$key] ?? $defaultUnit),
                );
            }
        }

        return $readings;
    }

    private function openMeteoTime(mixed $time, array $payload): ?CarbonImmutable
    {
        if (is_numeric($time)) {
            return CarbonImmutable::createFromTimestampUTC((int) $time);
        }

        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($time, $payload['timezone'] ?? 'UTC')->utc();
        } catch (Throwable) {
            // Fuso inválido: usa utc_offset_seconds, se disponível.
        }

        try {
            $hasExplicitTimezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $time) === 1;
            $timestamp = CarbonImmutable::parse($time, 'UTC');

            if (! $hasExplicitTimezone) {
                $timestamp = $timestamp->subSeconds((int) ($payload['utc_offset_seconds'] ?? 0));
            }

            return $timestamp->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseWunderground(array $payload): array
    {
        $readings = [];

        foreach ($payload['observations'] ?? [] as $observation) {
            if (! is_array($observation)) {
                continue;
            }

            try {
                if (! empty($observation['obsTimeUtc'])) {
                    $timestamp = CarbonImmutable::parse($observation['obsTimeUtc'])->utc();
                } elseif (isset($observation['epoch']) && is_numeric($observation['epoch'])) {
                    $timestamp = CarbonImmutable::createFromTimestampUTC((int) $observation['epoch']);
                } else {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $logger = (string) ($observation['stationID'] ?? 'unknown');
            $metric = is_array($observation['metric'] ?? null) ? $observation['metric'] : [];

            $readings[] = $this->reading(
                Reading::TEMPERATURE,
                'CURRENT',
                'wunderground-temp-sensor',
                $logger,
                $timestamp,
                $metric['temp'] ?? $metric['tempAvg'] ?? null,
                '°C',
            );

            $solar = $observation['solarRadiation'] ?? $observation['solarRadiationHigh'] ?? null;
            if (is_numeric($solar)) {
                $readings[] = $this->reading(
                    Reading::RADIATION,
                    'CURRENT',
                    'wunderground-rad-sensor',
                    $logger,
                    $timestamp,
                    $solar,
                    'W/m²',
                );
            }
        }

        return $readings;
    }

    private function isTracked(mixed $type): bool
    {
        return is_string($type) && array_key_exists($type, config('station-quality.measurement_types', []));
    }

    private function reading(string $type, string $dataType, string $sensorSn, string $loggerSn, CarbonImmutable $timestamp, mixed $raw, string $unit): Reading
    {
        $section = config("station-quality.measurement_types.{$type}");
        $standardUnit = (string) config("station-quality.{$section}.unit");
        $supported = true;

        if (! is_numeric($raw)) {
            $value = null;
            $outUnit = $standardUnit;
        } else {
            $converted = $this->units->normalize($type, (float) $raw, $unit);

            if ($converted === null) {
                $value = (float) $raw;
                $outUnit = $unit;
                $supported = false;
            } else {
                $value = $converted;
                $outUnit = $standardUnit;
            }
        }

        return new Reading($type, $dataType, $sensorSn, $loggerSn, $timestamp, $value, $outUnit, $supported);
    }

    /** @param list<Reading> $readings */
    private function groupBySeries(array $readings): array
    {
        $series = [];

        foreach ($readings as $reading) {
            $series[$reading->seriesKey()][$reading->timestamp->timestamp] = $reading;
        }

        return array_map(static function (array $byTimestamp): array {
            ksort($byTimestamp);

            return array_values($byTimestamp);
        }, $series);
    }
}
