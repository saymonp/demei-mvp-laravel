<?php

namespace App\Services;

use App\Models\Measurement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StationComparisonData
{
    public const MAX_CHUNK_DAYS = 7;

    /**
     * @param  array<int, int>  $stationIds
     * @param  array{start: string, end: string}  $periodA
     * @param  array{start: string, end: string}|null  $periodB
     * @return array<string, mixed>
     */
    public function props(array $stationIds, array $periodA, ?array $periodB): array
    {
        $requiresChunking = $this->requiresChunking($periodA)
            || ($periodB !== null && $this->requiresChunking($periodB));

        return [
            'selectedStationIds' => $stationIds,
            'periodA' => $periodA,
            'periodB' => $periodB,
            'requiresChunking' => $requiresChunking,
            'series' => fn (): array => $requiresChunking ? [] : $this->series($stationIds, $periodA),
            'seriesB' => fn (): array => $requiresChunking || $periodB === null ? [] : $this->series($stationIds, $periodB),
            'overrides' => fn (): array => $requiresChunking ? [] : $this->overrides(
                $stationIds,
                $periodB === null ? [$periodA] : [$periodA, $periodB],
            ),
        ];
    }

    /**
     * @param  array<int, int>  $stationIds
     * @param  array{start: string, end: string}  $period
     * @return array{series: array<int, array<int, array<string, mixed>>>, overrides: array<string, int>}
     */
    public function chunk(array $stationIds, array $period): array
    {
        return [
            'series' => $this->series($stationIds, $period),
            'overrides' => $this->overrides($stationIds, [$period]),
        ];
    }

    /**
     * @param  array{start: string, end: string}  $period
     */
    public function requiresChunking(array $period): bool
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $period['start'], 'America/Sao_Paulo');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $period['end'], 'America/Sao_Paulo');

        return $start->addDays(self::MAX_CHUNK_DAYS - 1)->lessThan($end);
    }

    /**
     * @param  array<int, int>  $stationIds
     * @param  array{start: string, end: string}  $period
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function series(array $stationIds, array $period): array
    {
        [$start, $end] = $this->range($period);

        return Measurement::query()
            ->whereIn('station_id', $stationIds)
            ->where('data_type', 'CURRENT')
            ->where('timestamp', '>=', $start)->where('timestamp', '<', $end)
            ->orderBy('timestamp')
            ->get(['id', 'station_id', 'timestamp', 'sensor_measurement_type', 'value', 'quality_flag', 'flag_reason'])
            ->groupBy('station_id')
            ->map(fn ($rows) => $rows->map(fn ($measurement) => [
                'measurement_id' => $measurement->id,
                'timestamp' => CarbonImmutable::parse($measurement->timestamp, 'UTC')->utc()->toIso8601String(),
                'sensor_measurement_type' => $measurement->sensor_measurement_type,
                'value' => $measurement->value === null ? null : (float) $measurement->value,
                'quality_flag' => $measurement->quality_flag->value,
                'flag_reason' => $measurement->flag_reason,
            ])->values())
            ->all();
    }

    /**
     * @param  array<int, int>  $stationIds
     * @param  array<int, array{start: string, end: string}>  $periods
     * @return array<string, int>
     */
    private function overrides(array $stationIds, array $periods): array
    {
        $query = DB::table('measurement_overrides')
            ->whereIn('station_id', $stationIds)
            ->whereNotNull('measurement_id')
            ->where(function ($where) use ($periods) {
                foreach ($periods as $period) {
                    [$start, $end] = $this->range($period);
                    $where->orWhere(fn ($range) => $range
                        ->where('timestamp', '>=', $start)
                        ->where('timestamp', '<', $end));
                }
            });

        return $query->get()->mapWithKeys(fn ($override) => [
            $override->station_id.'|measurement|'.$override->measurement_id => (int) $override->quality_flag,
        ])->all();
    }

    /**
     * @param  array{start: string, end: string}  $period
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(array $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $period['start'], 'America/Sao_Paulo');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $period['end'], 'America/Sao_Paulo')->addDay();

        return [$start->utc(), $end->utc()];
    }
}
