<?php

namespace Tests\Unit;

use App\Services\Quality\SeriesResampler;
use Tests\TestCase;

class SeriesResamplerTest extends TestCase
{
    public function test_keeps_anchors_and_fills_the_grid(): void
    {
        $series = (new SeriesResampler)->interpolate([0, 900, 1800], [0.0, 10.0, 0.0], 300);

        $this->assertSame([0, 300, 600, 900, 1200, 1500, 1800], array_keys($series));
        $this->assertSame(0.0, $series[0]);
        $this->assertSame(10.0, $series[900]);
        $this->assertSame(0.0, $series[1800]);
    }

    public function test_two_points_are_interpolated_linearly(): void
    {
        $series = (new SeriesResampler)->interpolate([0, 900], [0.0, 30.0], 300);

        $this->assertEqualsWithDelta(10.0, $series[300], 1e-9);
        $this->assertEqualsWithDelta(20.0, $series[600], 1e-9);
    }

    public function test_never_overshoots_so_radiation_stays_non_negative_and_bounded(): void
    {
        $series = (new SeriesResampler)->interpolate([0, 900, 1800, 2700, 3600], [0.0, 0.0, 800.0, 800.0, 0.0], 300);

        foreach ($series as $value) {
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThanOrEqual(800.0, $value);
        }
    }

    public function test_missing_anchors_are_not_invented(): void
    {
        $series = (new SeriesResampler)->interpolate([0, 900, 1800], [1.0, null, 3.0], 300);

        $this->assertSame(1.0, $series[0]);
        $this->assertNull($series[300]);   // entre 1.0 e null
        $this->assertNull($series[900]);
        $this->assertNull($series[1200]);  // entre null e 3.0
        $this->assertSame(3.0, $series[1800]);
    }

    public function test_same_step_returns_only_the_anchors(): void
    {
        $series = (new SeriesResampler)->interpolate([0, 900], [1.0, 2.0], 900);

        $this->assertSame([0 => 1.0, 900 => 2.0], $series);
    }

    public function test_noise_is_deterministic_skips_anchors_and_leaves_zeros_alone(): void
    {
        $resampler = new SeriesResampler;
        $series = $resampler->interpolate([0, 900, 1800], [0.0, 400.0, 0.0], 300);
        $anchors = [0 => true, 900 => true, 1800 => true];

        $first = $resampler->withNoise($series, $anchors, 'estacao-1', relative: 0.05, min: 0.0, max: 1400.0, floor: 5.0);
        $second = $resampler->withNoise($series, $anchors, 'estacao-1', relative: 0.05, min: 0.0, max: 1400.0, floor: 5.0);

        $this->assertSame($first, $second); // reprocessar gera exatamente os mesmos valores

        foreach ([0, 900, 1800] as $ts) {
            $this->assertSame($series[$ts], $first[$ts]); // as leituras originais não mudam
        }

        $this->assertNotSame($series[300], $first[300]);

        foreach ($first as $value) {
            $this->assertGreaterThanOrEqual(0.0, $value);
        }

        $zeros = $resampler->withNoise([0 => 0.0, 300 => 0.0, 600 => 0.0], [0 => true, 600 => true], 'x', relative: 0.5, floor: 5.0);
        $this->assertSame(0.0, $zeros[300]); // noite continua noite
    }
}
