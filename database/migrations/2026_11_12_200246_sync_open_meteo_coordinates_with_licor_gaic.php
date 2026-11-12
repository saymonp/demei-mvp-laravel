<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $licorCoordinates = DB::table('stations')
            ->where('name', 'unijui_gaic')
            ->first(['latitude', 'longitude']);

        if ($licorCoordinates === null || $licorCoordinates->latitude === null || $licorCoordinates->longitude === null) {
            return;
        }

        DB::table('stations')
            ->where('name', 'open_meteo')
            ->update([
                'latitude' => $licorCoordinates->latitude,
                'longitude' => $licorCoordinates->longitude,
            ]);
    }

    public function down(): void
    {
        $licorCoordinates = DB::table('stations')
            ->where('name', 'unijui_gaic')
            ->first(['latitude', 'longitude']);

        if ($licorCoordinates === null || $licorCoordinates->latitude === null || $licorCoordinates->longitude === null) {
            return;
        }

        DB::table('stations')
            ->where('name', 'open_meteo')
            ->where('latitude', $licorCoordinates->latitude)
            ->where('longitude', $licorCoordinates->longitude)
            ->update([
                'latitude' => -28.30,
                'longitude' => -54.26,
            ]);
    }
};
