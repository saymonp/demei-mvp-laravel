<?php

namespace App\Services\Quality;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;

/**
 * Regras de qualidade. Ordem de precedência: inválido > suspeito > válido.
 * Os limites vêm de config/station-quality.php, combinados com os ajustes do provedor.
 */
final class QualityClassifier
{
    public function classify(Reading $reading, SeriesContext $context, ProviderProfile $profile): Classification
    {
        if ($reading->value === null) {
            return new Classification(QualityFlag::Missing, FlagReason::MissingValue);
        }

        if (! $reading->unitSupported) {
            return new Classification(QualityFlag::Invalid, FlagReason::UnsupportedUnit);
        }

        return match ($reading->type) {
            Reading::TEMPERATURE => $this->temperature($reading, $context, $profile),
            Reading::RADIATION => $this->radiation($reading, $context, $profile),
            default => Classification::valid(),
        };
    }

    private function temperature(Reading $r, SeriesContext $ctx, ProviderProfile $profile): Classification
    {
        $c = $profile->rules('temperature');
        $v = $r->value;
        $ts = $r->timestamp->timestamp;

        // 1. Validação física
        if ($v < $c['min'] || $v > $c['max']) {
            return new Classification(QualityFlag::Invalid, FlagReason::OutOfRange);
        }

        // 2. Variação brusca em relação à leitura imediatamente anterior (se válida)
        $previous = $ctx->previousValidValue($ts, $profile->stepSeconds());
        if ($previous !== null && abs($v - $previous) > $c['spike_delta']) {
            return new Classification(QualityFlag::Suspect, FlagReason::Spike);
        }

        // 3. Desvio da média móvel das últimas horas
        $stats = $ctx->windowStats($ts, (int) $c['window_hours'] * 3600);
        if ($stats !== null && $stats['n'] >= $c['min_samples']) {
            $deviation = abs($v - $stats['mean']);
            $beyondSigma = $deviation > $c['sigma_multiplier'] * $stats['sd'] && $deviation > $c['sigma_min_deviation'];

            if ($deviation > $c['max_deviation'] || $beyondSigma) {
                return new Classification(QualityFlag::Suspect, FlagReason::DeviationFromMean);
            }
        }

        return Classification::valid();
    }

    private function radiation(Reading $r, SeriesContext $ctx, ProviderProfile $profile): Classification
    {
        $c = $profile->rules('radiation');
        $v = $r->value;
        $ts = $r->timestamp->timestamp;

        // 1. Validação física
        if ($v > $c['max'] || $v < $c['min']) {
            return new Classification(QualityFlag::Invalid, FlagReason::OutOfRange);
        }

        // 2. Ruído negativo (entre o limite de calibragem e zero). Mantém o valor gravado;
        //    a camada de exibição pode mostrar 0 se preferir.
        if ($v < 0) {
            return new Classification(QualityFlag::Suspect, FlagReason::NegativeNoise);
        }

        // 3. Radiação anômala de madrugada
        if ($v > $c['night_max'] && $this->isNight($r, $c)) {
            return new Classification(QualityFlag::Suspect, FlagReason::NightRadiation);
        }

        // 4. Variação brusca em relação à leitura imediatamente anterior (se válida)
        $previous = $ctx->previousValidValue($ts, $profile->stepSeconds());
        if ($previous !== null && abs($v - $previous) > $c['spike_delta']) {
            return new Classification(QualityFlag::Suspect, FlagReason::Spike);
        }

        return Classification::valid();
    }

    private function isNight(Reading $r, array $rules): bool
    {
        $hour = (int) $r->timestamp->setTimezone(config('station-quality.local_timezone'))->format('G');

        return $hour >= $rules['night_start_hour'] || $hour < $rules['night_end_hour'];
    }
}
