<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('reference_date');
            $table->string('format', 10);
            $table->string('status', 12)->default('pending');
            $table->text('notes')->nullable();
            $table->string('path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'reference_date']);
            $table->index(['user_id', 'station_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_reports');
    }
};
