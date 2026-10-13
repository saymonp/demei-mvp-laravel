<?php

namespace App\Services\Quality;

use App\Services\Quality\UnsupportedProviderException;

/** Tudo o que muda de um provedor para outro: parser, intervalo entre leituras e ajustes nas regras. */
final class ProviderProfile
{
    /** @param array<string, array<string, mixed>> $rules */
    private function __construct(
        public readonly string $provider,
        public readonly string $parser,
        public readonly int $stepMinutes,
        private readonly array $rules,
    ) {
    }

    public static function for(?string $provider): self
    {
        $config = config('station-quality.providers.'.(string) $provider);

        if (! is_array($config) || empty($config['parser'])) {
            throw new UnsupportedProviderException($provider);
        }

        $defaults = [
            'temperature' => config('station-quality.temperature'),
            'radiation' => config('station-quality.radiation'),
        ];

        return new self(
            $provider,
            $config['parser'],
            (int) ($config['step_minutes'] ?? config('station-quality.step_minutes')),
            array_replace_recursive($defaults, $config['rules'] ?? []),
        );
    }

    public function stepSeconds(): int
    {
        return $this->stepMinutes * 60;
    }

    /** @return array<string, mixed> */
    public function rules(string $section): array
    {
        return $this->rules[$section];
    }
}
