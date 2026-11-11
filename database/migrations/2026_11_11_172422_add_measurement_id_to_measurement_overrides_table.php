<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_overrides', function (Blueprint $table) {
            $table->foreignId('measurement_id')->nullable()->constrained()->nullOnDelete();
            $table->dropUnique('measurement_overrides_point_unique');
        });

        $lastExistingId = DB::table('measurement_overrides')->max('id');

        if ($lastExistingId !== null) {
            DB::table('measurement_overrides')->where('id', '<=', $lastExistingId)
                ->orderBy('id')->chunkById(100, function ($overrides): void {
                    foreach ($overrides as $override) {
                        $measurementIds = DB::table('measurements')
                            ->where('station_id', $override->station_id)
                            ->where('sensor_measurement_type', $override->sensor_measurement_type)
                            ->where('timestamp', $override->timestamp)
                            ->orderBy('id')
                            ->pluck('id');

                        if ($measurementIds->isEmpty()) {
                            continue;
                        }

                        DB::table('measurement_overrides')
                            ->where('id', $override->id)
                            ->update(['measurement_id' => $measurementIds->first()]);

                        foreach ($measurementIds->skip(1) as $measurementId) {
                            $copy = (array) $override;
                            unset($copy['id']);
                            $copy['measurement_id'] = $measurementId;

                            DB::table('measurement_overrides')->insert($copy);
                        }
                    }
                });
        }

        Schema::table('measurement_overrides', function (Blueprint $table) {
            $table->unique('measurement_id', 'measurement_overrides_measurement_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_overrides', function (Blueprint $table) {
            $table->dropUnique('measurement_overrides_measurement_id_unique');
        });

        // The previous schema cannot represent coincident measurements; preserve the latest override.
        $duplicateGroups = DB::table('measurement_overrides')
            ->select(['station_id', 'sensor_measurement_type', 'timestamp'])
            ->groupBy('station_id', 'sensor_measurement_type', 'timestamp')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $keepId = DB::table('measurement_overrides')
                ->where('station_id', $group->station_id)
                ->where('sensor_measurement_type', $group->sensor_measurement_type)
                ->where('timestamp', $group->timestamp)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('measurement_overrides')
                ->where('station_id', $group->station_id)
                ->where('sensor_measurement_type', $group->sensor_measurement_type)
                ->where('timestamp', $group->timestamp)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('measurement_overrides', function (Blueprint $table) {
            $table->dropForeign(['measurement_id']);
            $table->dropColumn('measurement_id');
            $table->unique(
                ['station_id', 'sensor_measurement_type', 'timestamp'],
                'measurement_overrides_point_unique',
            );
        });
    }
};
