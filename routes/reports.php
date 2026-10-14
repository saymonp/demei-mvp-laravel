<?php

use App\Http\Controllers\StationReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('station-dashboard/reports')->name('station-reports.')->group(function () {
    Route::get('/', [StationReportController::class, 'index'])->name('index');
    Route::post('/', [StationReportController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('/{report}', [StationReportController::class, 'show'])->name('show');
    Route::get('/{report}/download', [StationReportController::class, 'download'])->name('download');
});
