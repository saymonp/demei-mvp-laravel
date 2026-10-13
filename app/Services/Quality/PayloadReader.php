<?php

namespace App\Services\Quality;

use Carbon\CarbonImmutable;
use Throwable;

/** Filtra o payload (só temperatura e irradiação), normaliza unidades e agrupa por série. */
final class PayloadReader
{
    public function __construct(private readonly UnitNormalizer $units)
    {
    }

    /**
     * @return array<string, list<Reading>> chave da série => leituras em ordem cronológica, sem duplicatas
     */
    public function seriesFrom(array $payload): array
    {
        $types = config('station-quality.measurement_types');
        $series = [];

        foreach ($payload['data'] ?? [] as $item) {
            $type = $item['sensor_measurement_type'] ?? null;

            if (! is_string($type) || ! isset($types[$type]) || empty($item['timestamp']) || empty($item['sensor_sn'])) {
                continue; // Battery e demais tipos não entram nesta etapa
            }

            try {
                $timestamp = CarbonImmutable::parse($item['timestamp'], 'UTC');
            } catch (Throwable) {
                continue;
            }

            $standardUnit = (string) config("station-quality.{$types[$type]}.unit");
            $raw = $item['value'] ?? null;
            $unit = (string) ($item['unit'] ?? '');
            $supported = true;

            if (! is_numeric($raw)) {
                $value = null;
                $outUnit = $standardUnit;
            } else {
                $converted = $this->units->normalize($type, (float) $raw, $unit);
                if ($converted === null) {
                    $value = (float) $raw; // guarda o valor original para auditoria
                    $outUnit = $unit;
                    $supported = false;
                } else {
                    $value = $converted;
                    $outUnit = $standardUnit;
                }
            }

            $reading = new Reading(
                $type,
                (string) ($item['data_type'] ?? 'CURRENT'),
                (string) $item['sensor_sn'],
                (string) ($item['logger_sn'] ?? ''),
                $timestamp,
                $value,
                $outUnit,
                $supported,
            );

            // A fronteira entre duas janelas pode vir repetida: a última leitura vence.
            $series[$reading->seriesKey()][$timestamp->timestamp] = $reading;
        }

        return array_map(function (array $byTimestamp) {
            ksort($byTimestamp);

            return array_values($byTimestamp);
        }, $series);
    }
}
