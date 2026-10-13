<?php

namespace App\Services\Quality;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class GapFiller
{
    /**
     * Instantes esperados que ficaram sem leitura entre duas leituras conhecidas (exclusivo nas pontas).
     *
     * @return list<CarbonImmutable>
     */
    public function missingBetween(CarbonImmutable $previous, CarbonImmutable $next, ?int $stepSeconds = null): array
    {
        // Usa o step específico da estação ou o padrão do arquivo de config
        $step = $stepSeconds ?? ((int) config('station-quality.step_minutes') * 60);
        $cap = (int) config('station-quality.max_gap_points');
        $limit = $next->timestamp - intdiv($step, 2); // meio passo de tolerância

        $points = [];
        $t = $previous->timestamp + $step;

        for (; $t < $limit && count($points) < $cap; $t += $step) {
            $points[] = CarbonImmutable::createFromTimestampUTC($t);
        }

        if ($t < $limit) {
            Log::warning("Interrupção entre {$previous->toIso8601String()} e {$next->toIso8601String()} excede o limite de {$cap} lacunas; o restante não foi preenchido.");
        }

        return $points;
    }
}