<?php

namespace App\Enums;

enum QualityFlag: int
{
    case Valid = 0;
    case Invalid = 1;
    case Suspect = 2;
    case Missing = 3;

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Válido',
            self::Invalid => 'Inválido',
            self::Suspect => 'Suspeito',
            self::Missing => 'Lacuna',
        };
    }
}
