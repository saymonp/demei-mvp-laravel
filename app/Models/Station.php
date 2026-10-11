<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $guarded = [];

     protected $fillable = [
        'name',
        'api_url',
        'provider',
        'bearer_token',
        'loggers',
        'latitude',
        'longitude',
        'is_active',
    ];

    public function rawData(): HasMany
    {
        return $this->hasMany(StationRawData::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }
}
