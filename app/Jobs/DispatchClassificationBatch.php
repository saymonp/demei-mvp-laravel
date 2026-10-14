<?php

namespace App\Jobs;

use App\Events\MeasurementsClassified;
use App\Models\StationRawData;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class DispatchClassificationBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>  $rawDataIds
     */
    public function __construct(public array $rawDataIds) {}

    public function handle(): void
    {
        if ($this->rawDataIds === []) {
            throw new InvalidArgumentException('At least one raw-data record is required.');
        }

        $rawData = StationRawData::query()->findOrFail($this->rawDataIds);
        $stationIds = $rawData->pluck('station_id')
            ->map(static fn ($stationId): int => (int) $stationId)
            ->unique()
            ->values()
            ->all();
        $date = CarbonImmutable::parse($rawData->first()->fetched_end_at)
            ->setTimezone('America/Sao_Paulo')
            ->toDateString();

        Bus::batch($rawData->map(
            static fn (StationRawData $record): ClassifyStationData => new ClassifyStationData($record),
        ))
            ->name("classification-{$date}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($stationIds, $date): void {
                foreach ($stationIds as $stationId) {
                    Cache::put(
                        "weather.classified.{$stationId}.{$date}",
                        $batch->id,
                        now()->addDays(7),
                    );
                }

                MeasurementsClassified::dispatch($stationIds, $date, $batch->id);
            })
            ->dispatch();
    }
}
