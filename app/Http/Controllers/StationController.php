<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    public function index(Request $request)
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
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
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Sao_Paulo')->utc();
        $end = $start->setTimezone('America/Sao_Paulo')->addDay()->utc();
        $measurements = $stationId === null ? collect() : Measurement::query()
            ->where('station_id', $stationId)
            ->where('timestamp', '>=', $start)
            ->where('timestamp', '<', $end)
            ->orderBy('timestamp')->get()->map(fn ($m) => [
                'timestamp' => CarbonImmutable::parse($m->timestamp, 'UTC')->utc()->toIso8601String(),
                'sensor_measurement_type' => $m->sensor_measurement_type,
                'value' => $m->value === null ? null : (float) $m->value,
                'quality_flag' => $m->quality_flag->value,
                'flag_reason' => $m->flag_reason,
            ]);

        return Inertia::render('StationDashboard', [
            'measurements' => $measurements,
            'stations' => $stations,
            'selectedStationId' => $stationId,
            'selectedDate' => $date,
            'canGenerateReports' => $request->user() !== null,
        ]);
    }
}
