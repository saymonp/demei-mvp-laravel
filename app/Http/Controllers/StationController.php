<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StationController extends Controller
{
    public function index(Request $request)
    {
        // Pega a primeira estação por padrão se nenhuma for informada
        $stationId = $request->input('station_id', Station::first()?->id ?? 1);

        $measurements = Measurement::query()
            ->where('station_id', '=', $stationId)
            ->orderBy('timestamp')
            ->get();

        $stations = Station::all(['id', 'name', 'provider']);

        return Inertia::render('StationDashboard', [
            'measurements' => $measurements,
            'stations' => $stations,
            'selectedStationId' => (int) $stationId,
        ]);
    }
}