<?php

namespace App\Services\Quality;

use App\Enums\FlagReason;
use App\Enums\QualityFlag;

final class Classification
{
    public function __construct(
        public readonly QualityFlag $flag,
        public readonly ?FlagReason $reason = null,
    ) {
    }

    public static function valid(): self
    {
        return new self(QualityFlag::Valid);
    }
}
