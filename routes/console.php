<?php

use App\Jobs\FetchStationData;
use App\Models\Station;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Station::query()->where('is_active', true)->each(function (Station $station): void {
        try {
            FetchStationData::dispatch($station);
        } catch (Throwable $exception) {
            report($exception);
        }
    });
})->name('fetch-stations')->everyThirtyMinutes()->withoutOverlapping(10)->onOneServer();
