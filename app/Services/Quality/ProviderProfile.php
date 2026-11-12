<?php

namespace App\Services\Quality;

/** Tudo o que muda de um provedor para outro: intervalo entre leituras e regras específicas. */
final class ProviderProfile
{
    /** @param array<string, array<string, mixed>> $rules */
    private function __construct(
        public readonly string $provider,
        public readonly int $stepMinutes,
        private readonly array $rules,
    ) {}

    public static function for(?string $provider): self
    {
        $config = config('station-quality.providers.'.(string) $provider);

        if ($provider === null || $provider === '' || ! is_array($config)) {
            throw new UnsupportedProviderException($provider);
        }

        $defaults = [
            'temperature' => config('station-quality.temperature'),
            'radiation' => config('station-quality.radiation'),
        ];

        return new self(
            $provider,
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
