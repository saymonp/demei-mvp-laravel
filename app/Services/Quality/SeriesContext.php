<?php

namespace App\Services\Quality;

use App\Enums\QualityFlag;

/** Memória de uma série (sensor + tipo de dado) enquanto as leituras são classificadas em ordem. */
final class SeriesContext
{
    /** Tolerância, em segundos, ao comparar com a "leitura imediatamente anterior". */
    private const TOLERANCE = 30;

    /**
     * @param  list<array{0:int,1:float}>  $history  pares [timestamp unix, valor] das leituras válidas
     */
    public function __construct(
        private ?int $previousTs = null,
        private ?QualityFlag $previousFlag = null,
        private ?float $previousValue = null,
        private array $history = [],
    ) {
    }

    public function previousTimestamp(): ?int
    {
        return $this->previousTs;
    }

    /** Valor da leitura anterior, só se ela veio um passo antes e foi classificada como válida. */
    public function previousValidValue(int $ts, int $stepSeconds): ?float
    {
        if ($this->previousTs === null || $this->previousFlag !== QualityFlag::Valid) {
            return null;
        }

        return abs(($ts - $this->previousTs) - $stepSeconds) <= self::TOLERANCE ? $this->previousValue : null;
    }

    /** @return array{mean: float, sd: float, n: int}|null */
    public function windowStats(int $ts, int $windowSeconds): ?array
    {
        $values = [];
        foreach ($this->history as [$t, $v]) {
            if ($t >= $ts - $windowSeconds && $t < $ts) {
                $values[] = $v;
            }
        }

        $n = count($values);
        if ($n === 0) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / $n;

        return ['mean' => $mean, 'sd' => sqrt($variance), 'n' => $n];
    }

    public function record(int $ts, ?float $value, QualityFlag $flag): void
    {
        $this->previousTs = $ts;
        $this->previousFlag = $flag;
        $this->previousValue = $value;

        if ($flag === QualityFlag::Valid && $value !== null) {
            $this->history[] = [$ts, $value];
        }
    }
}
