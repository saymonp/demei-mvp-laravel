<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StationComparisonController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StationLatestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('station-dashboard')
        : redirect()->route('register');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/home', function () {
    return Inertia::render('Home');
});

Route::middleware('auth')->group(function () {
    Route::get('/station-dashboard/version', [StationController::class, 'classificationVersion'])
        ->name('station-dashboard.classification-version');
    Route::get('/station-dashboard', [StationController::class, 'index'])
        ->name('station-dashboard');
    Route::get('/stations/compare', [StationComparisonController::class, 'index'])
        ->name('stations.compare');
    Route::get('/stations/latest', StationLatestController::class)
        ->name('stations.latest');
    Route::post('/stations/measurements/override', [StationComparisonController::class, 'override'])
        ->name('stations.measurements.override');
    Route::delete('/stations/measurements/override', [StationComparisonController::class, 'destroyOverride'])
        ->name('stations.measurements.override.destroy');
});

require __DIR__.'/reports.php';

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
