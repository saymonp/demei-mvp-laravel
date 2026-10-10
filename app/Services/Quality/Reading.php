<?php

namespace App\Services\Quality;

use Carbon\CarbonImmutable;

/** Uma leitura já filtrada e com unidade normalizada, extraída do payload bruto. */
final class Reading
{
    public const TEMPERATURE = 'Air Temperature';
    public const RADIATION = 'Solar Radiation';

    public function __construct(
        public readonly string $type,
        public readonly string $dataType,
        public readonly string $sensorSn,
        public readonly string $loggerSn,
        public readonly CarbonImmutable $timestamp,
        public readonly ?float $value,
        public readonly string $unit,
        public readonly bool $unitSupported = true,
    ) {
    }

    public function seriesKey(): string
    {
        return $this->sensorSn.'|'.$this->dataType;
    }
}
