<?php

namespace App\Jobs;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;
use App\Models\Measurement;
use App\Models\StationRawData;
use App\Services\Quality\GapFiller;
use App\Services\Quality\PayloadReader;
use App\Services\Quality\QualityClassifier;
use App\Services\Quality\Reading;
use App\Services\Quality\SeriesContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyStationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [30, 120];
    public $deleteWhenMissingModels = true;

    public function __construct(public StationRawData $rawData)
    {
    }

    /** Uma estação por vez: a classificação depende da ordem cronológica das coletas. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("classify-station-{$this->rawData->station_id}"))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function handle(PayloadReader $reader, QualityClassifier $classifier, GapFiller $gaps): void
    {
        $raw = $this->rawData;
        $payload = is_array($raw->payload) ? $raw->payload : (json_decode((string) $raw->payload, true) ?? []);
        $series = $reader->seriesFrom($payload);

        if ($series === []) {
            Log::warning("Coleta bruta #{$raw->id}: nenhuma leitura de temperatura ou irradiação para classificar.");

            return;
        }

        $now = now();
        $rows = [];
        $totals = [0 => 0, 1 => 0, 2 => 0, 3 => 0];

        foreach ($series as $readings) {
            $context = $this->loadContext($raw->station_id, $readings[0]);

            foreach ($readings as $reading) {
                $previousTs = $context->previousTimestamp();

                // Lacunas entre a última leitura conhecida (do banco ou deste lote) e esta.
                if ($previousTs !== null) {
                    $from = CarbonImmutable::createFromTimestampUTC($previousTs);
                    foreach ($gaps->missingBetween($from, $reading->timestamp) as $missingAt) {
                        $rows[] = $this->row($raw, $reading, $missingAt, null, $reading->unit, QualityFlag::Missing, FlagReason::Gap, $now);
                        $totals[QualityFlag::Missing->value]++;
                    }
                }

                $result = $classifier->classify($reading, $context);
                $rows[] = $this->row($raw, $reading, $reading->timestamp, $reading->value, $reading->unit, $result->flag, $result->reason, $now);
                $totals[$result->flag->value]++;

                $context->record($reading->timestamp->timestamp, $reading->value, $result->flag);
            }
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Measurement::upsert(
                    $chunk,
                    ['station_id', 'sensor_sn', 'data_type', 'timestamp'],
                    ['station_raw_data_id', 'logger_sn', 'sensor_measurement_type', 'value', 'unit', 'quality_flag', 'flag_reason', 'updated_at'],
                );
            }
        });

        Log::info(sprintf(
            'Coleta bruta #%d classificada: %d válidas, %d suspeitas, %d inválidas, %d lacunas.',
            $raw->id,
            $totals[0],
            $totals[2],
            $totals[1],
            $totals[3],
        ));
    }

    public function failed(Throwable $e): void
    {
        Log::error("Falha ao classificar a coleta bruta #{$this->rawData->id}: {$e->getMessage()}");
    }

    /** Última medição anterior e, para temperatura, as leituras válidas da janela móvel. */
    private function loadContext(int $stationId, Reading $first): SeriesContext
    {
        $before = Measurement::query()
            ->where('station_id', $stationId)
            ->where('sensor_sn', $first->sensorSn)
            ->where('data_type', $first->dataType)
            ->where('timestamp', '<', $first->timestamp->format('Y-m-d H:i:s'));

        $previous = (clone $before)->orderByDesc('timestamp')->first(['timestamp', 'value', 'quality_flag']);

        $history = [];
        if ($first->type === Reading::TEMPERATURE) {
            $windowStart = $first->timestamp->subHours((int) config('station-quality.temperature.window_hours'));

            $history = (clone $before)
                ->where('quality_flag', QualityFlag::Valid->value)
                ->where('timestamp', '>=', $windowStart->format('Y-m-d H:i:s'))
                ->orderBy('timestamp')
                ->get(['timestamp', 'value'])
                ->map(fn (Measurement $m) => [$this->unix($m), (float) $m->value])
                ->all();
        }

        return new SeriesContext(
            $previous ? $this->unix($previous) : null,
            $previous?->quality_flag,
            $previous?->value,
            $history,
        );
    }

    /** O banco guarda UTC; lê o valor cru para não depender do fuso da aplicação. */
    private function unix(Measurement $m): int
    {
        return CarbonImmutable::parse($m->getRawOriginal('timestamp'), 'UTC')->timestamp;
    }

    private function row(StationRawData $raw, Reading $r, CarbonImmutable $at, ?float $value, string $unit, QualityFlag $flag, ?FlagReason $reason, $now): array
    {
        return [
            'station_id' => $raw->station_id,
            'station_raw_data_id' => $raw->id,
            'logger_sn' => $r->loggerSn,
            'sensor_sn' => $r->sensorSn,
            'sensor_measurement_type' => $r->type,
            'data_type' => $r->dataType,
            'timestamp' => $at->format('Y-m-d H:i:s'),
            'value' => $value,
            'unit' => $unit,
            'quality_flag' => $flag->value,
            'flag_reason' => $reason?->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
