<?php

namespace App\Services\Quality;

/** Converte para a unidade padrão. Devolve null quando a unidade não é reconhecida. */
final class UnitNormalizer
{
    public function normalize(string $type, float $value, string $unit): ?float
    {
        $u = mb_strtolower(trim($unit));

        return match ($type) {
            Reading::TEMPERATURE => match ($u) {
                '°c', 'c', 'degc' => $value,
                '°f', 'f', 'degf' => ($value - 32) * 5 / 9,
                'k', 'kelvin' => $value - 273.15,
                default => null,
            },
            Reading::RADIATION => match ($u) {
                'w/m²', 'w/m2', 'w/m^2' => $value,
                'kw/m²', 'kw/m2', 'kw/m^2' => $value * 1000,
                default => null,
            },
            default => null,
        };
    }
}
