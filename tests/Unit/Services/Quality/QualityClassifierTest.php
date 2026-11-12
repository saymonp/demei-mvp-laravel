<?php

namespace Tests\Unit\Services\Quality;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;
use App\Services\Quality\GapFiller;
use App\Services\Quality\ProviderProfile;
use App\Services\Quality\QualityClassifier;
use App\Services\Quality\Reading;
use App\Services\Quality\SeriesContext;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class QualityClassifierTest extends TestCase
{
    public function test_previous_reading_tolerance_uses_the_provider_step(): void
    {
        $reading = $this->temperature(26.0);
        $context = new SeriesContext(
            $reading->timestamp->timestamp - 27 * 60,
            QualityFlag::Valid,
            20.0,
        );

        $wunderground = $this->classifier()->classify($reading, $context, ProviderProfile::for('wunderground'));
        $openMeteo = $this->classifier()->classify($reading, $context, ProviderProfile::for('open_meteo'));

        $this->assertSame(FlagReason::Spike, $wunderground->reason);
        $this->assertSame(QualityFlag::Valid, $openMeteo->flag);
    }

    public function test_temperature_minimum_samples_come_from_the_provider_profile(): void
    {
        $reading = $this->temperature(23.0);
        $history = [];
        foreach ([19.9, 20.1, 19.9, 20.1, 19.9] as $index => $value) {
            $history[] = [$reading->timestamp->timestamp - 1800 * ($index + 1), $value];
        }
        $context = new SeriesContext(history: $history);

        $wunderground = $this->classifier()->classify($reading, $context, ProviderProfile::for('wunderground'));
        $openMeteo = $this->classifier()->classify($reading, $context, ProviderProfile::for('open_meteo'));

        $this->assertSame(FlagReason::DeviationFromMean, $wunderground->reason);
        $this->assertSame(QualityFlag::Valid, $openMeteo->flag);
    }

    public function test_gap_filler_uses_days_when_configured(): void
    {
        config([
            'station-quality.max_gap_days' => 1,
            'station-quality.max_gap_points' => 2,
        ]);
        $previous = CarbonImmutable::parse('2026-10-09 00:00:00', 'UTC');
        $next = $previous->addDay()->addMinutes(5);

        $gaps = (new GapFiller)->missingBetween($previous, $next, 300);

        $this->assertCount(288, $gaps);
    }

    public function test_gap_filler_falls_back_to_the_legacy_point_limit(): void
    {
        config([
            'station-quality.max_gap_days' => null,
            'station-quality.max_gap_points' => 2,
        ]);
        $previous = CarbonImmutable::parse('2026-10-09 00:00:00', 'UTC');

        $gaps = (new GapFiller)->missingBetween($previous, $previous->addMinutes(25), 300);

        $this->assertCount(2, $gaps);
    }

    private function classifier(): QualityClassifier
    {
        return new QualityClassifier;
    }

    private function temperature(float $value): Reading
    {
        return new Reading(
            Reading::TEMPERATURE,
            'CURRENT',
            'TEMP-1',
            'LOGGER-1',
            CarbonImmutable::parse('2026-10-09 15:00:00', 'UTC'),
            $value,
            '°C',
        );
    }
}
