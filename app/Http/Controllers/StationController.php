<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StationController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // Keep the application's existing station visibility/authorization scopes.
        $stations = Station::query()->orderBy('name')->get(['id', 'name', 'provider']);
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
        ]);
    }
}
