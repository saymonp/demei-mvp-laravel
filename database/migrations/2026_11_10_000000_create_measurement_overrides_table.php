<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria manual: guarda a sobrescrita separada de `measurements`, para que
 * reprocessar (ClassifyStationData) nunca apague a decisão do auditor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('sensor_measurement_type');
            $table->timestamp('timestamp');
            $table->unsignedTinyInteger('original_flag')->nullable();
            $table->unsignedTinyInteger('quality_flag');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'sensor_measurement_type', 'timestamp'], 'measurement_overrides_point_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_overrides');
    }
};
