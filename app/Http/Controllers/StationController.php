<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use App\Models\Station;
use App\Services\StationComparisonData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StationController extends Controller
{
    public function classificationVersion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'batch_id' => Cache::get(
                'weather.classified.'.(int) $validated['station_id'].'.'.$validated['date'],
            ),
        ]);
    }

    public function index(Request $request, StationComparisonData $comparisonData)
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'stations' => ['nullable', 'array', 'min:2', 'max:4'],
            'stations.*' => ['integer', 'exists:stations,id'],
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'compare_start' => ['nullable', 'date_format:Y-m-d'],
            'compare_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:compare_start'],
        ]);
        // Keep the application's existing station visibility/authorization scopes.
        $stations = Station::query()->orderBy('name')->get([
            'id',
            'name',
            'provider',
            'latitude',
            'longitude',
            'altitude',
        ]);
        $stationId = isset($validated['station_id'])
            ? (int) $validated['station_id'] : $stations->first()?->id;
        $date = $validated['date'] ?? CarbonImmutable::now('America/Sao_Paulo')->format('Y-m-d');
        $selectedStationIds = collect($validated['stations'] ?? $stations->take(3)->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $periodA = [
            'start' => $validated['start'] ?? $date,
            'end' => $validated['end'] ?? ($validated['start'] ?? $date),
        ];
        $periodB = isset($validated['compare_start'])
            ? [
                'start' => $validated['compare_start'],
                'end' => $validated['compare_end'] ?? $validated['compare_start'],
            ]
            : null;
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Sao_Paulo')->utc();
        $end = $start->setTimezone('America/Sao_Paulo')->addDay()->utc();
        $measurementRows = $stationId === null ? collect() : Measurement::query()
            ->where('station_id', $stationId)
            ->where('timestamp', '>=', $start)
            ->where('timestamp', '<', $end)
            ->orderBy('timestamp')
            ->orderBy('id')
            ->get();
        $measurementOverrides = DB::table('measurement_overrides')
            ->whereIn('measurement_id', $measurementRows->pluck('id'))
            ->get()
            ->keyBy('measurement_id');
        $measurements = $measurementRows->map(fn ($measurement) => [
            'measurement_id' => $measurement->id,
            'timestamp' => CarbonImmutable::parse($measurement->timestamp, 'UTC')->utc()->toIso8601String(),
            'sensor_measurement_type' => $measurement->sensor_measurement_type,
            'value' => $measurement->value === null ? null : (float) $measurement->value,
            'quality_flag' => isset($measurementOverrides[$measurement->id])
                ? (int) $measurementOverrides[$measurement->id]->quality_flag
                : $measurement->quality_flag->value,
            'flag_reason' => $measurement->flag_reason,
            'audited' => isset($measurementOverrides[$measurement->id]),
            'original_flag' => isset($measurementOverrides[$measurement->id])
                ? (int) $measurementOverrides[$measurement->id]->original_flag
                : null,
        ]);

        return Inertia::render('StationDashboard', array_merge([
            'measurements' => $measurements,
            'stations' => $stations,
            'selectedStationId' => $stationId,
            'selectedDate' => $date,
            'canGenerateReports' => $request->user() !== null,
            'canAudit' => $request->user() !== null,
        ], $comparisonData->props($selectedStationIds, $periodA, $periodB)));
    }
}
