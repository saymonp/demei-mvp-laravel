<?php
use App\Models\Station;
use App\Jobs\FetchStationData;
use Carbon\Carbon;

// 1. Busca a estação e valida se ela existe
$station = Station::first();

if (!$station) {
    echo "❌ ERRO: Nenhuma estação cadastrada no banco de dados.\n";
    return;
}

$start = Carbon::parse('2026-10-18 00:00:00');$end   = Carbon::parse('2026-10-19 00:00:00');

echo "--------------------------------------------------\n";
echo "🚀 Iniciando coleta de dados...\n";
echo "📍 Estação: {$station->name} (Provedor: {$station->provider})\n";
echo "📅 Período: {$start->format('d/m/Y H:i')} até {$end->format('d/m/Y H:i')}\n";
echo "--------------------------------------------------\n";

// Contagem de registros no banco antes da execução
$rawBefore =$station->rawData()->count();

try {
    $timeStart = microtime(true);

    // Executa o Job sincronamente
    FetchStationData::dispatchSync($station, $start,$end);

    $elapsed = round(microtime(true) - $timeStart, 2);$rawAfter = $station->rawData()->count();$inserted = $rawAfter -$rawBefore;

    echo "✅ Sucesso! Requisição e processamento concluídos em {$elapsed}s.\n";
    echo "📦 Registros brutos inseridos em `raw_data`: {$inserted}\n";

    if ($inserted > 0) {
        $lastRecord =$station->rawData()->latest()->first();
        echo "🆔 ID da coleta gerada: {$lastRecord->id}\n";
        echo "⚙️ Job de classificação disparado para o ID [{$lastRecord->id}].\n";
    } else {
        echo "⚠️ Nenhum registro novo foi criado em `raw_data`.\n";
    }

} catch (\Throwable $e) {
    echo "❌ OCORREU UM ERRO DURANTE A EXECUÇÃO:\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "Local: " . $e->getFile() . " (linha " . $e->getLine() . ")\n";
}

echo "--------------------------------------------------\n";