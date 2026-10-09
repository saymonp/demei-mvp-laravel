<?php

use App\Jobs\FetchStationData;
use App\Models\Station;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $stations = Station::where('is_active', true)->get();

    foreach ($stations as $station) {
        FetchStationData::dispatch($station);
    }
})->everyThirtyMinutes();


