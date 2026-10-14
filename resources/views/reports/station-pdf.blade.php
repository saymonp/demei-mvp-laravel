@php
    $n = fn ($value, $decimals = 1) => $value === null ? '—' : number_format($value, $decimals, ',', '.');
    $station = $data['station'];
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório {{ $station->name }} {{ $data['date'] }}</title>
    <style>
        @page { margin: 28px 32px 48px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2a37; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #d5dde6; padding-bottom: 3px; }
        .muted { color: #6b7684; }
        .header { border-bottom: 2px solid #2f6fb3; padding-bottom: 8px; }
        .kpis td { width: 33%; border: 1px solid #d5dde6; padding: 8px; vertical-align: top; }
        .kpi { font-size: 16px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { border: 1px solid #d5dde6; padding: 4px 5px; text-align: left; }
        .grid th { background: #eef2f6; }
        .num { text-align: right !important; }
        .f1 { color: #b42318; }
        .f2 { color: #b54708; }
        .f3 { color: #475467; }
        .notes { border: 1px solid #d5dde6; min-height: 90px; padding: 8px; white-space: pre-line; }
        footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #6b7684; }
    </style>
</head>
<body>
<footer>Gerado em {{ $data['generated_at']->format('d/m/Y H:i') }} (GMT−3) · {{ $station->provider }} · {{ $station->name }}</footer>

<div class="header">
    <h1>Relatório diário — {{ $station->name }}</h1>
    <div class="muted">
        Provedor {{ $station->provider }} ·
        Lat {{ $n($station->latitude, 4) }}°, Lon {{ $n($station->longitude, 4) }}° ·
        Altitude {{ $station->altitude !== null ? $n($station->altitude, 0).' m' : '—' }} ·
        Data de referência {{ \Carbon\Carbon::parse($data['date'])->format('d/m/Y') }} (00:00–23:59, GMT−3)
    </div>
</div>

<h2>Indicadores principais</h2>
<table class="kpis"><tr>
    <td>Pico de irradiação<div class="kpi">{{ $data['peak_radiation'] ? $n($data['peak_radiation']['value'], 0).' W/m²' : '—' }}</div>
        <span class="muted">{{ $data['peak_radiation'] ? 'às '.$data['peak_radiation']['time'].', leitura válida' : 'sem leitura válida' }}</span></td>
    <td>Temperatura máxima<div class="kpi">{{ $data['max_temperature'] ? $n($data['max_temperature']['value']).' °C' : '—' }}</div>
        <span class="muted">{{ $data['max_temperature'] ? 'às '.$data['max_temperature']['time'].', leitura válida' : 'sem leitura válida' }}</span></td>
    <td>Índice de confiabilidade<div class="kpi">{{ $data['reliability'] === null ? '—' : $n($data['reliability']).'%' }}</div>
        <span class="muted">{{ $data['qc'][0]['count'] }} de {{ $data['total'] }} leituras válidas</span></td>
</tr></table>

<h2>Sumário de controle de qualidade</h2>
<table class="grid">
    <tr><th>Flag</th><th>Status</th><th class="num">Leituras</th><th class="num">Percentual</th></tr>
    @foreach ($data['qc'] as $quality)
        <tr>
            <td>{{ $quality['flag'] }}</td>
            <td class="f{{ $quality['flag'] }}">{{ $quality['label'] }}</td>
            <td class="num">{{ $quality['count'] }}</td>
            <td class="num">{{ $n($quality['percent']) }}%</td>
        </tr>
    @endforeach
    <tr><th colspan="2">Total</th><th class="num">{{ $data['total'] }}</th><th class="num">100%</th></tr>
</table>

<h2>Trilha de auditoria de anomalias ({{ $data['anomalies']->count() }})</h2>
@if ($data['anomalies']->isEmpty())
    <p class="muted">Nenhuma leitura com alerta no período.</p>
@else
    <table class="grid">
        <tr><th>Horário</th><th>Sensor</th><th class="num">Valor</th><th>Status</th><th>Motivo técnico</th></tr>
        @foreach ($data['anomalies'] as $anomaly)
            <tr>
                <td>{{ $anomaly['time'] }}</td>
                <td>{{ $anomaly['sensor_label'] }}</td>
                <td class="num">{{ $anomaly['value'] === null ? '—' : $n($anomaly['value']).' '.$anomaly['unit'] }}</td>
                <td class="f{{ $anomaly['flag'] }}">{{ $anomaly['status'] }}</td>
                <td>{{ $anomaly['reason'] ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>Observações / notas do operador</h2>
<div class="notes">{{ $data['notes'] ?: 'Sem observações registradas.' }}</div>
</body>
</html>
