<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationReport extends Model
{
    public const FORMATS = ['pdf', 'xlsx', 'csv'];

    protected $fillable = [
        'station_id',
        'user_id',
        'reference_date',
        'format',
        'status',
        'notes',
        'path',
        'error',
        'finished_at',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'finished_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @return array{id: int, format: string, status: string, reference_date: string, error: ?string, download_url: ?string, created_at: ?string}
     */
    public function toClient(): array
    {
        return [
            'id' => $this->id,
            'format' => $this->format,
            'status' => $this->status,
            'reference_date' => $this->reference_date->toDateString(),
            'error' => $this->status === 'failed' ? 'Não foi possível gerar o documento.' : null,
            'download_url' => $this->status === 'ready' ? route('station-reports.download', $this) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
