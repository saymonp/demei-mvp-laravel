<?php

namespace App\Services\Quality;

/**
 * Reamostra uma série temporal para um passo menor (ex.: 15 min -> 5 min).
 *
 * A interpolação é cúbica monotônica (Fritsch-Carlson, a mesma do PCHIP): suave, mas nunca passa
 * dos valores vizinhos. Por isso a irradiação não fica negativa e nenhum pico aparece entre dois pontos.
 * Valores ausentes (null) não são inventados: os pontos entre uma âncora nula e a vizinha também ficam nulos.
 */
final class SeriesResampler
{
    /**
     * @param  list<int>  $times  instantes (unix) em ordem crescente
     * @param  list<float|null>  $values  valores das âncoras, na mesma ordem
     * @return array<int, float|null> instante => valor, do primeiro ao último instante, de $step em $step segundos
     */
    public function interpolate(array $times, array $values, int $step): array
    {
        $n = count($times);
        $out = [];

        if ($n === 0) {
            return $out;
        }

        $slopes = $this->slopes($times, $values);

        for ($i = 0; $i < $n - 1; $i++) {
            $t0 = $times[$i];
            $t1 = $times[$i + 1];
            $out[$t0] = $values[$i]; // a âncora (leitura original) nunca é alterada

            for ($t = $t0 + $step; $t < $t1; $t += $step) {
                $out[$t] = ($values[$i] === null || $values[$i + 1] === null)
                    ? null
                    : $this->hermite($t0, $t1, $values[$i], $values[$i + 1], $slopes[$i], $slopes[$i + 1], $t);
            }
        }

        $out[$times[$n - 1]] = $values[$n - 1];

        return $out;
    }

    /**
     * Ruído determinístico: o mesmo ($seed, instante) sempre gera o mesmo valor, então reprocessar não muda nada.
     * Só mexe nos pontos interpolados (nunca nas âncoras) e deixa quieto o que está perto de zero (noite).
     *
     * @param  array<int, float|null>  $series
     * @param  array<int, true>  $anchors  instantes que são leituras originais
     * @return array<int, float|null>
     */
    public function withNoise(
        array $series,
        array $anchors,
        string $seed,
        float $relative = 0.0,
        float $absolute = 0.0,
        ?float $min = null,
        ?float $max = null,
        float $floor = 0.0,
    ): array {
        foreach ($series as $ts => $value) {
            if ($value === null || isset($anchors[$ts]) || abs($value) < $floor) {
                continue;
            }

            // média de dois sorteios: concentra o ruído perto de zero, sem extremos
            $unit = ($this->unit("{$seed}|{$ts}|a") + $this->unit("{$seed}|{$ts}|b")) / 2;
            $noisy = $value + $unit * ($absolute + $relative * abs($value));

            if ($min !== null) {
                $noisy = max($min, $noisy);
            }
            if ($max !== null) {
                $noisy = min($max, $noisy);
            }

            $series[$ts] = $noisy;
        }

        return $series;
    }

    /** Número estável em [-1, 1] a partir de um texto. */
    private function unit(string $key): float
    {
        return (crc32($key) / 4294967295) * 2 - 1;
    }

    private function hermite(int $t0, int $t1, float $y0, float $y1, float $m0, float $m1, int $t): float
    {
        $h = $t1 - $t0;
        $s = ($t - $t0) / $h;
        $s2 = $s * $s;
        $s3 = $s2 * $s;

        return (2 * $s3 - 3 * $s2 + 1) * $y0
            + ($s3 - 2 * $s2 + $s) * $h * $m0
            + (-2 * $s3 + 3 * $s2) * $y1
            + ($s3 - $s2) * $h * $m1;
    }

    /**
     * Inclinação em cada âncora, calculada por trecho contínuo (sem nulos).
     *
     * @param  list<int>  $times
     * @param  list<float|null>  $values
     * @return array<int, float|null>
     */
    private function slopes(array $times, array $values): array
    {
        $n = count($times);
        $slopes = array_fill(0, $n, null);
        $i = 0;

        while ($i < $n) {
            if ($values[$i] === null) {
                $i++;

                continue;
            }

            $j = $i;
            while ($j + 1 < $n && $values[$j + 1] !== null) {
                $j++;
            }

            $this->runSlopes($times, $values, $i, $j, $slopes);
            $i = $j + 1;
        }

        return $slopes;
    }

    /** @param array<int, float|null> $m */
    private function runSlopes(array $t, array $y, int $a, int $b, array &$m): void
    {
        $length = $b - $a + 1;

        if ($length === 1) {
            $m[$a] = 0.0;

            return;
        }

        $h = [];
        $d = [];
        for ($k = $a; $k < $b; $k++) {
            $h[$k] = $t[$k + 1] - $t[$k];
            $d[$k] = ($y[$k + 1] - $y[$k]) / $h[$k];
        }

        if ($length === 2) {
            $m[$a] = $m[$b] = $d[$a];

            return;
        }

        for ($k = $a + 1; $k < $b; $k++) {
            if ($d[$k - 1] * $d[$k] <= 0) {
                $m[$k] = 0.0; // máximo ou mínimo local: tangente plana

                continue;
            }

            $w1 = 2 * $h[$k] + $h[$k - 1];
            $w2 = $h[$k] + 2 * $h[$k - 1];
            $m[$k] = ($w1 + $w2) / ($w1 / $d[$k - 1] + $w2 / $d[$k]);
        }

        $m[$a] = $this->endSlope($h[$a], $h[$a + 1], $d[$a], $d[$a + 1]);
        $m[$b] = $this->endSlope($h[$b - 1], $h[$b - 2], $d[$b - 1], $d[$b - 2]);
    }

    private function endSlope(float $h0, float $h1, float $d0, float $d1): float
    {
        $m = ((2 * $h0 + $h1) * $d0 - $h0 * $d1) / ($h0 + $h1);

        if ($m * $d0 <= 0) {
            return 0.0;
        }

        if ($d0 * $d1 <= 0 && abs($m) > 3 * abs($d0)) {
            return 3 * $d0;
        }

        return $m;
    }
}
