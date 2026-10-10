<?php

namespace App\Models;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class Measurement extends Model
{
    protected $fillable = [
        'station_id',
        'station_raw_data_id',
        'logger_sn',
        'sensor_sn',
        'sensor_measurement_type',
        'data_type',
        'timestamp',
        'value',
        'unit',
        'quality_flag',
        'flag_reason',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'value' => 'float',
        'quality_flag' => QualityFlag::class,
        'flag_reason' => FlagReason::class,
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function rawData(): BelongsTo
    {
        return $this->belongsTo(StationRawData::class, 'station_raw_data_id');
    }

    /** Inválidos e suspeitos: alimenta a tela de logs de dados. */
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->whereIn('quality_flag', [QualityFlag::Invalid->value, QualityFlag::Suspect->value]);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('quality_flag', QualityFlag::Valid->value);
    }
}
