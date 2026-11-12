<?php

return [
    // Intervalo padrão entre leituras, em minutos (usado se o provedor não definir o seu).
    'step_minutes' => 5,

    // Interrupção máxima preenchida com lacunas; o limite em pontos depende do intervalo.
    'max_gap_days' => 7,

    // Fuso usado para decidir o que é "noite". O banco guarda tudo em UTC.
    'local_timezone' => 'America/Sao_Paulo',

    // sensor_measurement_type => seção de regras abaixo. O que não estiver aqui é ignorado.
    'measurement_types' => [
        'Air Temperature' => 'temperature',
        'Solar Radiation' => 'radiation',
    ],

    'temperature' => [
        'unit' => '°C',
        'min' => -10.0,               // abaixo disso: inválido
        'max' => 50.0,                // acima disso: inválido
        'spike_delta' => 5.0,         // variação entre leituras consecutivas: suspeito
        'window_hours' => 3,          // janela da média móvel
        'min_samples' => 12,          // amostras mínimas para avaliar o desvio
        'max_deviation' => 8.0,       // desvio absoluto da média da janela: suspeito
        'sigma_multiplier' => 3.0,    // desvio em múltiplos do desvio padrão da janela...
        'sigma_min_deviation' => 2.0, // ...mas só conta se também passar deste piso (°C)
    ],

    'radiation' => [
        'unit' => 'W/m²',
        'min' => -15.0,               // abaixo disso: inválido (erro de calibragem)
        'max' => 1400.0,              // acima disso: inválido (fisicamente impossível)
        'spike_delta' => 600.0,       // variação entre leituras consecutivas: suspeito
        'night_start_hour' => 21,     // hora local em que a noite começa
        'night_end_hour' => 5,        // hora local em que a noite termina
        'night_max' => 10.0,          // acima disso à noite: suspeito
    ],

    // Um item por valor de stations.provider. "rules" sobrescreve as regras padrão.
    'providers' => [
        'licor' => [
            'step_minutes' => 5,
            'rules' => [],
        ],
        'open_meteo' => [
            'step_minutes' => 15,
            'rules' => [
                'temperature' => ['min_samples' => 8],
            ],
        ],
        'wunderground' => [
            'step_minutes' => 30,
            'rules' => [
                'temperature' => ['min_samples' => 4],
            ],
        ],
    ],
];
