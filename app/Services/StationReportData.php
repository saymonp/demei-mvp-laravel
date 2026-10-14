<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StationReportData
{
    public const TZ = 'America/Sao_Paulo';

    public const LABELS = [0 => 'Válido', 1 => 'Inválido', 2 => 'Suspeito', 3 => 'Lacuna'];

    public const SENSORS = ['Solar Radiation' => 'Radiação', 'Air Temperature' => 'Temperatura'];

    /**
     * @return array<string, mixed>
     */
    public function build(Station $station, string $date): array
    {
        $start = CarbonImmutable::parse($date, self::TZ)->startOfDay();

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = Measurement::query()
            ->where('station_id', $station->id)
            ->where('timestamp', '>=', $start->utc()->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $start->addDay()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('timestamp')
            ->get()
            ->map(function (Measurement $measurement): array {
                $utc = CarbonImmutable::parse($measurement->getRawOriginal('timestamp'), 'UTC');
                $flag = $measurement->quality_flag->value;
                $reason = $measurement->flag_reason;

                return [
                    'utc' => $utc->format('Y-m-d H:i:s'),
                    'local' => $utc->setTimezone(self::TZ)->format('Y-m-d H:i:s'),
                    'time' => $utc->setTimezone(self::TZ)->format('H:i'),
                    'sensor' => $measurement->sensor_measurement_type,
                    'sensor_label' => self::SENSORS[$measurement->sensor_measurement_type]
                        ?? $measurement->sensor_measurement_type,
                    'value' => $measurement->value,
                    'unit' => $measurement->unit,
                    'flag' => $flag,
                    'status' => self::LABELS[$flag] ?? (string) $flag,
                    'reason' => $reason?->value,
                ];
            });

        $total = $rows->count();
        $qualityCounts = collect(self::LABELS)->map(function (string $label, int $flag) use ($rows, $total): array {
            $count = $rows->where('flag', $flag)->count();

            return [
                'flag' => $flag,
                'label' => $label,
                'count' => $count,
                'percent' => $total > 0 ? $count / $total * 100 : 0,
            ];
        })->values();

        return [
            'station' => $station,
            'date' => $date,
            'generated_at' => CarbonImmutable::now(self::TZ),
            'rows' => $rows,
            'total' => $total,
            'qc' => $qualityCounts,
            'reliability' => $total > 0 ? $rows->where('flag', 0)->count() / $total * 100 : null,
            'peak_radiation' => $this->peak($rows, 'Solar Radiation'),
            'max_temperature' => $this->peak($rows, 'Air Temperature'),
            'anomalies' => $rows->where('flag', '!=', 0)->values(),
            'summary' => $this->summary($rows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{value: float, time: string}|null
     */
    private function peak(Collection $rows, string $sensor): ?array
    {
        $best = $rows->where('sensor', $sensor)
            ->where('flag', 0)
            ->whereNotNull('value')
            ->sortByDesc('value')
            ->first();

        return $best ? ['value' => $best['value'], 'time' => $best['time']] : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function summary(Collection $rows): array
    {
        $calculate = function (Collection $values, string $sensor, string $period): array {
            $count = $values->count();
            $average = $count > 0 ? $values->avg() : null;
            $standardDeviation = $count > 1
                ? sqrt($values->map(fn (float $value): float => ($value - $average) ** 2)->sum() / ($count - 1))
                : null;

            return [
                'period' => $period,
                'sensor' => self::SENSORS[$sensor] ?? $sensor,
                'count' => $count,
                'avg' => $average,
                'min' => $count > 0 ? $values->min() : null,
                'max' => $count > 0 ? $values->max() : null,
                'std' => $standardDeviation,
            ];
        };

        $summary = [];
        foreach ($rows->groupBy('sensor') as $sensor => $sensorRows) {
            $valid = $sensorRows->where('flag', 0)->whereNotNull('value');
            $summary[] = $calculate($valid->pluck('value'), $sensor, 'Dia');

            foreach ($valid->groupBy(fn (array $row): string => substr($row['time'], 0, 2).':00') as $hour => $hourRows) {
                $summary[] = $calculate($hourRows->pluck('value'), $sensor, $hour);
            }
        }

        return $summary;
    }
}
