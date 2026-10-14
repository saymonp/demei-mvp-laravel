<?php

namespace Tests\Feature\Jobs;

use App\Events\MeasurementsClassified;
use App\Jobs\ClassifyStationData;
use App\Jobs\DispatchClassificationBatch;
use App\Models\Station;
use App\Models\StationRawData;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DispatchClassificationBatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_classification_runs_in_a_failure_tolerant_batch(): void
    {
        $rawData = $this->createRawData();
        Bus::fake();

        (new DispatchClassificationBatch([$rawData->id]))->handle();

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof ClassifyStationData
            && $batch->options['allowFailures'] === true);
    }

    public function test_notifies_once_when_the_classification_batch_finishes(): void
    {
        $rawData = $this->createRawData();
        Event::fake([MeasurementsClassified::class]);

        (new DispatchClassificationBatch([$rawData->id]))->handle();

        Event::assertDispatchedTimes(MeasurementsClassified::class, 1);
        Event::assertDispatched(
            MeasurementsClassified::class,
            fn (MeasurementsClassified $event): bool => $event->stationIds === [$rawData->station_id]
                && $event->date === '2026-10-14',
        );
    }

    private function createRawData(): StationRawData
    {
        $station = Station::query()->create([
            'name' => 'Test station',
            'api_url' => 'https://example.test/weather',
            'provider' => 'open_meteo',
        ]);

        return $station->rawData()->create([
            'payload' => [],
            'fetched_start_at' => '2026-10-14 11:30:00',
            'fetched_end_at' => '2026-10-14 12:00:00',
        ]);
    }
}
