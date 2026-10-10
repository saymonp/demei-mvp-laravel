<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StationRawData extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'fetched_start_at' => 'datetime',
        'fetched_end_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class, 'station_raw_data_id');
    }
}
