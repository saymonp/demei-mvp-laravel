<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('station_id')->constrained()->cascadeOnDelete();

            // Linhagem: de qual coleta bruta (e de qual job) esta medição saiu.
            $table->foreignId('station_raw_data_id')
                ->nullable()
                ->index()
                ->constrained('station_raw_data')
                ->nullOnDelete();

            // Identificam a série temporal (um sensor pode enviar CURRENT e AVERAGE).
            $table->string('logger_sn', 32);
            $table->string('sensor_sn', 64);
            $table->string('sensor_measurement_type'); // "Air Temperature" | "Solar Radiation"
            $table->string('data_type', 16);           // "CURRENT" | "AVERAGE"

            $table->timestamp('timestamp');            // sempre em UTC

            $table->double('value')->nullable();       // NULL quando quality_flag = 3 (lacuna)
            $table->string('unit', 10);                // "°C" | "W/m²" (ou a unidade original, se não reconhecida)

            // 0 = válido | 1 = inválido | 2 = suspeito | 3 = lacuna
            $table->unsignedTinyInteger('quality_flag')->default(0);
            $table->string('flag_reason', 40)->nullable(); // código do enum FlagReason

            $table->timestamps();

            // Garante idempotência: reprocessar o mesmo dado atualiza em vez de duplicar.
            $table->unique(['station_id', 'sensor_sn', 'data_type', 'timestamp'], 'measurements_series_unique');

            $table->index(['station_id', 'timestamp']);
            $table->index(['sensor_measurement_type', 'timestamp']);
            $table->index(['quality_flag', 'timestamp']); // tela de logs de inválidos e suspeitos
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurements');
    }
};
