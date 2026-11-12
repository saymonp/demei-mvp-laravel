<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StationLatestController extends Controller
{
    private const MAX_AGE_HOURS = 6;

    public function __invoke(): JsonResponse
    {
        $since = CarbonImmutable::now('UTC')
            ->subHours(self::MAX_AGE_HOURS)
            ->format('Y-m-d H:i:s');

        $rows = DB::table('measurements')
            ->select(['station_id', 'sensor_measurement_type', 'value', 'timestamp'])
            ->where('data_type', 'CURRENT')
            ->where('quality_flag', 0)
            ->whereNotNull('value')
            ->whereIn('sensor_measurement_type', ['Air Temperature', 'Solar Radiation'])
            ->where('timestamp', '>=', $since)
            ->orderBy('station_id')
            ->orderBy('sensor_measurement_type')
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $stationId = $row->station_id;
            $key = $row->sensor_measurement_type === 'Air Temperature' ? 'temperature' : 'radiation';
            $latest[$stationId] ??= ['temperature' => null, 'radiation' => null, 'observed_at' => null];

            if ($latest[$stationId][$key] !== null) {
                continue;
            }

            $latest[$stationId][$key] = round((float) $row->value, 1);

            $observedAt = CarbonImmutable::parse($row->timestamp, 'UTC');
            $current = $latest[$stationId]['observed_at'];

            if ($current === null || $observedAt->greaterThan(CarbonImmutable::parse($current))) {
                $latest[$stationId]['observed_at'] = $observedAt->toIso8601String();
            }
        }

        return response()->json(['data' => (object) $latest]);
    }
}
