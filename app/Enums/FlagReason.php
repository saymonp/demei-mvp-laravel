<?php

namespace App\Enums;

enum FlagReason: string
{
    case OutOfRange = 'out_of_range';
    case NegativeNoise = 'negative_noise';
    case NightRadiation = 'night_radiation';
    case Spike = 'spike';
    case DeviationFromMean = 'deviation_from_mean';
    case Gap = 'gap';
    case MissingValue = 'missing_value';
    case UnsupportedUnit = 'unsupported_unit';

    public function label(): string
    {
        return match ($this) {
            self::OutOfRange => 'Fora de faixa',
            self::NegativeNoise => 'Valor negativo',
            self::NightRadiation => 'Radiação noturna anômala',
            self::Spike => 'Variação brusca',
            self::DeviationFromMean => 'Desvio da média recente',
            self::Gap => 'Lacuna de comunicação',
            self::MissingValue => 'Valor ausente na resposta',
            self::UnsupportedUnit => 'Unidade não reconhecida',
        };
    }
}
