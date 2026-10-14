<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MeasurementsClassified implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int>  $stationIds
     */
    public function __construct(
        public array $stationIds,
        public string $date,
        public string $batchId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('weather.measurements')];
    }

    public function broadcastAs(): string
    {
        return 'measurements.classified';
    }

    /**
     * @return array{station_ids: array<int>, date: string, batch_id: string}
     */
    public function broadcastWith(): array
    {
        return [
            'station_ids' => $this->stationIds,
            'date' => $this->date,
            'batch_id' => $this->batchId,
        ];
    }
}
