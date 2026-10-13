<?php

namespace App\Services\Quality;

use RuntimeException;

final class UnsupportedProviderException extends RuntimeException
{
    public function __construct(?string $provider)
    {
        parent::__construct('Provedor de estação não suportado: '.($provider ?? 'não informado'));
    }
}
