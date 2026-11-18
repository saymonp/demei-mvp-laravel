<?php

namespace App\Console\Commands;

use App\Events\MeasurementsClassified;
use App\Jobs\ClassifyStationData;
use App\Models\Measurement;
use App\Models\Station;
use App\Services\Quality\SeriesResampler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Popula o histórico de uma ou mais estações com dados da Open-Meteo, passando pela pipeline normal:
 *
 *   API de arquivo -> reamostragem -> station_raw_data (com "_source_provider": "open_meteo") -> ClassifyStationData -> measurements
 *
 * Como o PayloadReader e o ProviderProfile usam sourceProvider(), funciona para qualquer estação
 * (licor, wunderground...) sem mudar o provider dela e sem parser novo.
 *
 * ATENÇÃO: o que entra em measurements é modelo meteorológico (e, nos pontos intermediários, interpolação
 * com ruído sintético), não medição do sensor da estação. A origem fica registrada em payload._backfill
 * e nos sensores "open-meteo-*".
 */
final class BackfillOpenMeteoHistory extends Command
{
    private const TIMEZONE = 'America/Sao_Paulo';

    private const DEFAULT_ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const VARIABLES = 'temperature_2m,shortwave_radiation';

    protected $signature = 'weather:backfill-open-meteo
        {--station=* : ID ou nome da estação (repita a opção ou separe por vírgula)}
        {--all : Todas as estações cadastradas}
        {--from=2026-05-16 : Primeiro dia (America/Sao_Paulo)}
        {--to=2026-11-16 : Último dia (America/Sao_Paulo)}
        {--request-days=31 : Dias por requisição à API}
        {--raw-days=7 : Dias por registro em station_raw_data}
        {--resolution=5 : Intervalo final em minutos (1, 5 ou 15)}
        {--noise=1 : Intensidade do ruído nos pontos interpolados (0 desliga)}
        {--source=auto : auto, minutely_15 ou hourly}
        {--cooldown=2 : Segundos de pausa entre requisições}
        {--max-retries=4 : Tentativas por requisição}
        {--allow-overlap : Não limita o período aos dias anteriores aos dados reais da estação}
        {--no-notify : Não avisa o dashboard no final}
        {--dry-run : Busca e reamostra, mas não grava nada}';

    protected $description = 'Popula o histórico das estações com dados da Open-Meteo e classifica pela pipeline existente';

    /** @var array<string, array{times: list<int>, temperature: list<float|null>, radiation: list<float|null>}> */
    private array $anchorCache = [];

    /** Fonte escolhida no modo "auto" (a decisão vale para o resto da execução). */
    private ?string $resolvedSource = null;

    private string $sourceMode = 'auto';

    private float $cooldown = 2.0;

    private int $maxRetries = 4;

    private int $requestDays = 31;

    public function handle(SeriesResampler $resampler): int
    {
        try {
            $opts = $this->readOptions();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $stations = $this->resolveStations();

        if ($stations->isEmpty()) {
            return self::FAILURE;
        }

        $lock = Cache::lock('weather:backfill-open-meteo', 6 * 3600);

        if (! $lock->get()) {
            $this->error('Já existe um backfill em andamento.');

            return self::FAILURE;
        }

        try {
            return $this->backfill($stations, $opts, $resampler);
        } finally {
            $lock->release();
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Opções e seleção de estações
    // ---------------------------------------------------------------------------------------------

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, rawDays: int, resolution: int, noise: float, allowOverlap: bool, notify: bool, dryRun: bool} */
    private function readOptions(): array
    {
        $from = $this->parseDate((string) $this->option('from'), 'from');
        $to = $this->parseDate((string) $this->option('to'), 'to');

        if ($to->lt($from)) {
            throw new InvalidArgumentException('--to não pode ser anterior a --from.');
        }

        $resolution = (int) $this->option('resolution');

        if (! in_array($resolution, [1, 5, 15], true)) {
            throw new InvalidArgumentException('--resolution deve ser 1, 5 ou 15.');
        }

        $source = (string) $this->option('source');

        if (! in_array($source, ['auto', 'minutely_15', 'hourly'], true)) {
            throw new InvalidArgumentException('--source deve ser auto, minutely_15 ou hourly.');
        }

        $this->sourceMode = $source;
        $this->requestDays = max(1, min(92, (int) $this->option('request-days')));
        $this->cooldown = max(0.0, (float) $this->option('cooldown'));
        $this->maxRetries = max(1, (int) $this->option('max-retries'));

        return [
            'from' => $from,
            'to' => $to,
            'rawDays' => max(1, min(31, (int) $this->option('raw-days'))),
            'resolution' => $resolution,
            'noise' => max(0.0, (float) $this->option('noise')),
            'allowOverlap' => (bool) $this->option('allow-overlap'),
            'notify' => ! $this->option('no-notify'),
            'dryRun' => (bool) $this->option('dry-run'),
        ];
    }

    private function parseDate(string $value, string $option): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$option} deve estar no formato AAAA-MM-DD.");
        }

        return $date;
    }

    /** @return Collection<int, Station> */
    private function resolveStations(): Collection
    {
        $all = Station::query()->orderBy('id')->get();

        if ($all->isEmpty()) {
            $this->error('Nenhuma estação cadastrada. Rode o StationSeeder primeiro.');

            return collect();
        }

        if ($this->option('all')) {
            return $all;
        }

        $requested = collect((array) $this->option('station'))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim($value))
            ->filter()
            ->unique()
            ->values();

        if ($requested->isNotEmpty()) {
            $picked = collect();
            $unknown = [];

            foreach ($requested as $reference) {
                $station = ctype_digit($reference)
                    ? $all->firstWhere('id', (int) $reference)
                    : $all->firstWhere('name', $reference);

                if ($station === null) {
                    $unknown[] = $reference;
                } else {
                    $picked->push($station);
                }
            }

            if ($unknown !== []) {
                $this->error('Estação não encontrada: '.implode(', ', $unknown).'. Disponíveis: '.$all->pluck('name')->implode(', '));

                return collect();
            }

            return $picked->unique('id')->values();
        }

        if (! $this->input->isInteractive()) {
            $this->error('Informe --station=ID_OU_NOME ou --all.');

            return collect();
        }

        $allLabel = 'Todas as estações';
        $labels = $all->mapWithKeys(fn (Station $s) => [$s->id => "#{$s->id} {$s->name} ({$s->provider})"]);
        $choice = $this->choice('Qual estação deseja popular?', [$allLabel, ...$labels->values()->all()], 0);

        if ($choice === $allLabel) {
            return $all;
        }

        return $all->where('id', $labels->search($choice))->values();
    }

    // ---------------------------------------------------------------------------------------------
    // Fluxo principal
    // ---------------------------------------------------------------------------------------------

    /** @param Collection<int, Station> $stations */
    private function backfill(Collection $stations, array $opts, SeriesResampler $resampler): int
    {
        $failures = 0;
        $processed = [];

        foreach ($stations as $station) {
            $this->newLine();
            $this->info("== {$station->name} (#{$station->id}, {$station->provider}) ==");

            try {
                if ($this->backfillStation($station, $opts, $resampler)) {
                    $processed[] = $station->id;
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("{$station->name}: {$e->getMessage()}");
                report($e);
            }
        }

        if ($processed !== [] && ! $opts['dryRun'] && $opts['notify']) {
            $this->notifyDashboard($processed, $opts['to']);
        }

        $this->newLine();

        if ($failures === 0) {
            $this->info($opts['dryRun'] ? 'Simulação concluída (nada foi gravado).' : 'Concluído.');

            return self::SUCCESS;
        }

        $this->warn("Concluído com erro em {$failures} estação(ões).");

        return self::FAILURE;
    }

    private function backfillStation(Station $station, array $opts, SeriesResampler $resampler): bool
    {
        if (! is_numeric($station->latitude) || ! is_numeric($station->longitude)) {
            $this->warn('Sem latitude/longitude cadastradas. Pulando.');

            return false;
        }

        $to = $opts['allowOverlap'] ? $opts['to'] : $this->limitByLiveData($station, $opts['from'], $opts['to']);

        if ($to === null) {
            return false;
        }

        $anchors = $this->fetchAnchors((float) $station->latitude, (float) $station->longitude, $opts['from'], $to);

        if ($anchors['times'] === []) {
            $this->warn('A Open-Meteo não devolveu dados para o período.');

            return false;
        }

        $series = $this->resample($anchors, $station, $opts, $resampler);
        $windows = $this->windows($opts['from'], $to, $opts['rawDays']);

        $bar = $this->output->createProgressBar(count($windows));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('iniciando');
        $bar->start();

        $written = 0;

        foreach ($windows as [$start, $end]) {
            $bar->setMessage($start->toDateString().' a '.$end->subDay()->toDateString());

            $payload = $this->buildPayload($station, $start, $end, $series, $anchors, $opts);

            if ($payload !== null) {
                if (! $opts['dryRun']) {
                    // Idempotente: reexecutar atualiza o mesmo registro (mesma janela) e a classificação faz upsert.
                    $raw = $station->rawData()->updateOrCreate(
                        ['fetched_start_at' => $start->utc(), 'fetched_end_at' => $end->utc()],
                        ['payload' => $payload],
                    );
                    $raw->setRelation('station', $station);

                    // Em ordem cronológica e sem fila: cada janela encontra a anterior já gravada.
                    ClassifyStationData::dispatchSync($raw);
                }

                $written++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($opts['dryRun']) {
            $this->line("{$written} janela(s) seriam gravadas.");

            return false;
        }

        $this->summarize($station, $opts['from'], $to);
        $this->warnAboutFetchCursor($station, end($windows)[1]);

        return $written > 0;
    }

    /**
     * Estações com dados reais (que não são "open-meteo-*") ficam só com o histórico anterior a eles.
     * Senão cada horário teria duas séries (a medida e a do modelo) e os gráficos misturariam as duas.
     */
    private function limitByLiveData(Station $station, CarbonImmutable $from, CarbonImmutable $to): ?CarbonImmutable
    {
        $firstLive = Measurement::query()
            ->where('station_id', $station->id)
            ->where('sensor_sn', 'not like', 'open-meteo-%')
            ->min('timestamp');

        if ($firstLive === null) {
            return $to;
        }

        $limit = CarbonImmutable::parse((string) $firstLive, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()->subDay();

        if ($limit->lt($from)) {
            $this->warn("Já há dados reais desde {$limit->addDay()->toDateString()}; nada a preencher no período. Use --allow-overlap para ignorar.");

            return null;
        }

        if ($limit->lt($to)) {
            $this->warn("Há dados reais desde {$limit->addDay()->toDateString()}: o histórico vai só até {$limit->toDateString()} (use --allow-overlap para ignorar).");

            return $limit;
        }

        return $to;
    }

    // ---------------------------------------------------------------------------------------------
    // Open-Meteo
    // ---------------------------------------------------------------------------------------------

    /**
     * Busca as âncoras (leituras originais da API) do período, em blocos de --request-days.
     * Estações com as mesmas coordenadas reaproveitam a consulta.
     *
     * @return array{times: list<int>, temperature: list<float|null>, radiation: list<float|null>}
     */
    private function fetchAnchors(float $lat, float $lon, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = sprintf('%.5f|%.5f|%s|%s|%s', $lat, $lon, $from->toDateString(), $to->toDateString(), $this->sourceMode);

        if (isset($this->anchorCache[$key])) {
            $this->line('  Reaproveitando a consulta de uma estação com as mesmas coordenadas.');

            return $this->anchorCache[$key];
        }

        $rows = [];
        $blocks = $this->windows($from, $to, $this->requestDays);

        foreach ($blocks as $index => [$blockStart, $blockEnd]) {
            $this->line(sprintf('  Open-Meteo: %s a %s (%d/%d)', $blockStart->toDateString(), $blockEnd->subDay()->toDateString(), $index + 1, count($blocks)));

            // end_date = dia seguinte: o primeiro ponto dele é a âncora que fecha a interpolação do último dia do bloco.
            $block = $this->fetchBlock($lat, $lon, $blockStart, $blockEnd);

            foreach ($block['times'] as $i => $ts) {
                $rows[$ts] = [$block['temperature'][$i], $block['radiation'][$i]];
            }

            if ($index < count($blocks) - 1) {
                $this->cooldown();
            }
        }

        ksort($rows);
        $times = array_keys($rows);

        // Tira as pontas sem nenhum valor (dias que a API ainda não tem).
        $first = 0;
        $last = count($times) - 1;
        while ($first <= $last && $rows[$times[$first]] === [null, null]) {
            $first++;
        }
        while ($last >= $first && $rows[$times[$last]] === [null, null]) {
            $last--;
        }
        $times = array_slice($times, $first, max(0, $last - $first + 1));

        return $this->anchorCache[$key] = [
            'times' => $times,
            'temperature' => array_map(fn (int $ts) => $rows[$ts][0], $times),
            'radiation' => array_map(fn (int $ts) => $rows[$ts][1], $times),
        ];
    }

    /** @return array{times: list<int>, temperature: list<float|null>, radiation: list<float|null>, usable: bool} */
    private function fetchBlock(float $lat, float $lon, CarbonImmutable $start, CarbonImmutable $endDate): array
    {
        $source = $this->resolvedSource ?? ($this->sourceMode === 'auto' ? 'minutely_15' : $this->sourceMode);
        $clamped = false;

        while (true) {
            try {
                $block = $this->requestBlock($source, $lat, $lon, $start, $endDate);
            } catch (RuntimeException $e) {
                $lastAllowed = $this->lastAllowedDate($e);

                // A API só tem dados até certo dia (os últimos dias costumam atrasar): corta e tenta de novo.
                if ($e->getCode() === 400 && $lastAllowed !== null && ! $clamped) {
                    $clamped = true;

                    if ($lastAllowed->lt($start)) {
                        $this->warn("  A Open-Meteo ainda não tem dados a partir de {$start->toDateString()}.");

                        return ['times' => [], 'temperature' => [], 'radiation' => [], 'usable' => false];
                    }

                    $this->warn("  A Open-Meteo só tem dados até {$lastAllowed->toDateString()}; ajustando o bloco.");
                    $endDate = $lastAllowed;

                    continue;
                }

                if ($e->getCode() === 400 && $source === 'minutely_15' && $this->sourceMode === 'auto') {
                    $this->warn("  A API recusou minutely_15 ({$e->getMessage()}). Usando dados horários e interpolando.");
                    $source = $this->resolvedSource = 'hourly';

                    continue;
                }

                throw $e;
            }

            if (! $block['usable'] && $source === 'minutely_15' && $this->sourceMode === 'auto') {
                $this->warn('  minutely_15 veio sem valores. Usando dados horários e interpolando.');
                $source = $this->resolvedSource = 'hourly';

                continue;
            }

            if ($this->resolvedSource === null && $this->sourceMode === 'auto') {
                $this->resolvedSource = $source;
            }

            return $block;
        }
    }

    /** @return array{times: list<int>, temperature: list<float|null>, radiation: list<float|null>, usable: bool} */
    private function requestBlock(string $source, float $lat, float $lon, CarbonImmutable $start, CarbonImmutable $endDate): array
    {
        $key = $source === 'hourly' ? 'hourly' : 'minutely_15';

        $json = $this->send((string) config('services.open_meteo.archive_url', self::DEFAULT_ARCHIVE_URL), [
            'latitude' => $lat,
            'longitude' => $lon,
            'start_date' => $start->toDateString(),
            'end_date' => $endDate->toDateString(),
            'timezone' => self::TIMEZONE,
            $key => self::VARIABLES,
        ])->json();

        $data = is_array($json) && is_array($json[$key] ?? null) ? $json[$key] : [];
        $times = [];
        $temperature = [];
        $radiation = [];
        $usable = false;

        foreach ($data['time'] ?? [] as $i => $time) {
            try {
                $ts = CarbonImmutable::parse((string) $time, self::TIMEZONE)->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            $t = $this->numberOrNull($data['temperature_2m'][$i] ?? null);
            $r = $this->numberOrNull($data['shortwave_radiation'][$i] ?? null);

            $usable = $usable || $t !== null || $r !== null;
            $times[] = $ts;
            $temperature[] = $t;
            $radiation[] = $r;
        }

        return compact('times', 'temperature', 'radiation', 'usable');
    }

    /**
     * Uma requisição, com nova tentativa em 429, 5xx e falha de conexão.
     * Limite por hora ou por dia não adianta esperar: falha na hora com uma mensagem clara.
     *
     * @param  array<string, mixed>  $query
     */
    private function send(string $url, array $query): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $retry = $attempt < $this->maxRetries;

            try {
                $response = Http::acceptJson()->connectTimeout(10)->timeout(60)->get($url, $query);
            } catch (ConnectionException $e) {
                if (! $retry) {
                    throw new RuntimeException("Falha de conexão com a Open-Meteo: {$e->getMessage()}", 0, $e);
                }

                $this->backoff($attempt, null, 5.0);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $reason = (string) ($response->json('reason') ?? Str::limit($response->body(), 200));

            if ($status === 429 && preg_match('/hourly|daily/i', $reason) === 1) {
                throw new RuntimeException("Limite da Open-Meteo atingido: {$reason}. Tente de novo mais tarde.", 429);
            }

            if (($status === 429 || $status >= 500) && $retry) {
                $this->backoff($attempt, $response, $status === 429 ? 20.0 : 5.0);

                continue;
            }

            throw new RuntimeException($reason !== '' ? $reason : "HTTP {$status}", $status);
        }
    }

    private function backoff(int $attempt, ?Response $response, float $base): void
    {
        $retryAfter = $response?->header('Retry-After');
        $seconds = is_numeric($retryAfter) ? (float) $retryAfter : min(90.0, $base * (2 ** ($attempt - 1)));

        $this->warn(sprintf('  Limite ou erro temporário da API. Nova tentativa em %.0f s (%d/%d).', $seconds, $attempt, $this->maxRetries - 1));
        Sleep::for($seconds)->seconds();
    }

    private function cooldown(): void
    {
        if ($this->cooldown > 0) {
            Sleep::for($this->cooldown)->seconds();
        }
    }

    /** Ex.: "Parameter 'end_date' is out of allowed range from 1940-01-01 to 2026-11-12". */
    private function lastAllowedDate(RuntimeException $e): ?CarbonImmutable
    {
        if (preg_match('/out of allowed range from \d{4}-\d{2}-\d{2} to (\d{4}-\d{2}-\d{2})/', $e->getMessage(), $m) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $m[1], self::TIMEZONE) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    // ---------------------------------------------------------------------------------------------
    // Reamostragem, payload e relatório
    // ---------------------------------------------------------------------------------------------

    /**
     * 15 min (ou 1 h) -> --resolution minutos, com interpolação monotônica e ruído leve só nos pontos intermediários.
     *
     * @param  array{times: list<int>, temperature: list<float|null>, radiation: list<float|null>}  $anchors
     * @return array{temperature: array<int, float|null>, radiation: array<int, float|null>}
     */
    private function resample(array $anchors, Station $station, array $opts, SeriesResampler $resampler): array
    {
        $step = $opts['resolution'] * 60;
        $temperature = $resampler->interpolate($anchors['times'], $anchors['temperature'], $step);
        $radiation = $resampler->interpolate($anchors['times'], $anchors['radiation'], $step);

        if ($opts['noise'] > 0) {
            $isAnchor = array_fill_keys($anchors['times'], true);

            // temperatura: até ±0,12 °C; irradiação: até ±2,5% e nunca abaixo de 0 nem acima do limite físico.
            // Abaixo de 5 W/m² (noite) não há ruído: não nasce irradiação onde o modelo diz que é noite.
            $temperature = $resampler->withNoise($temperature, $isAnchor, "{$station->id}|temperature", absolute: 0.12 * $opts['noise']);
            $radiation = $resampler->withNoise(
                $radiation,
                $isAnchor,
                "{$station->id}|radiation",
                relative: 0.025 * $opts['noise'],
                min: 0.0,
                max: (float) config('station-quality.radiation.max', 1400),
                floor: 5.0,
            );
        }

        return [
            'temperature' => array_map(fn (?float $v) => $v === null ? null : round($v, 2), $temperature),
            'radiation' => array_map(fn (?float $v) => $v === null ? null : max(0.0, round($v, 1)), $radiation),
        ];
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> pares [início, fim exclusivo] em dias locais */
    private function windows(CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        $windows = [];
        $limit = $to->addDay();

        for ($cursor = $from; $cursor->lt($limit); $cursor = $cursor->addDays($days)) {
            $end = $cursor->addDays($days);
            $windows[] = [$cursor, $end->gt($limit) ? $limit : $end];
        }

        return $windows;
    }

    /**
     * Payload no formato que o PayloadReader::parseOpenMeteo() já lê. A chave continua "minutely_15"
     * mesmo com outro intervalo, porque o parser não confere o passo.
     *
     * @param  array{temperature: array<int, float|null>, radiation: array<int, float|null>}  $series
     * @param  array{times: list<int>, temperature: list<float|null>, radiation: list<float|null>}  $anchors
     * @return array<string, mixed>|null
     */
    private function buildPayload(Station $station, CarbonImmutable $start, CarbonImmutable $end, array $series, array $anchors, array $opts): ?array
    {
        $startTs = $start->getTimestamp();
        $endTs = $end->getTimestamp();
        $time = $temperature = $radiation = [];

        foreach ($series['temperature'] as $ts => $value) {
            if ($ts < $startTs) {
                continue;
            }
            if ($ts >= $endTs) {
                break;
            }

            $time[] = $this->localIso($ts);
            $temperature[] = $value;
            $radiation[] = $series['radiation'][$ts] ?? null;
        }

        if ($time === []) {
            return null;
        }

        $original = ['time' => [], 'temperature_2m' => [], 'shortwave_radiation' => []];
        foreach ($anchors['times'] as $i => $ts) {
            if ($ts >= $startTs && $ts < $endTs) {
                $original['time'][] = $this->localIso($ts);
                $original['temperature_2m'][] = $anchors['temperature'][$i];
                $original['shortwave_radiation'][] = $anchors['radiation'][$i];
            }
        }

        return [
            'latitude' => (float) $station->latitude,
            'longitude' => (float) $station->longitude,
            'timezone' => self::TIMEZONE,
            'utc_offset_seconds' => $start->getOffset(),
            'minutely_15' => ['time' => $time, 'temperature_2m' => $temperature, 'shortwave_radiation' => $radiation],
            'minutely_15_units' => ['time' => 'iso8601', 'temperature_2m' => '°C', 'shortwave_radiation' => 'W/m²'],
            '_source_provider' => 'open_meteo',
            '_backfill' => [
                'source' => 'open-meteo-archive',
                'api_source' => $this->resolvedSource ?? $this->sourceMode,
                'resolution_minutes' => $opts['resolution'],
                'interpolated' => true,
                'noise' => $opts['noise'],
                'window_start' => $start->toDateString(),
                'window_end' => $end->subDay()->toDateString(),
                'generated_at' => now()->toIso8601String(),
            ],
            '_original' => $original,
        ];
    }

    private function localIso(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, self::TIMEZONE)->format('Y-m-d\TH:i');
    }

    private function summarize(Station $station, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $counts = DB::table('measurements')
            ->where('station_id', $station->id)
            ->where('sensor_sn', 'like', 'open-meteo-%')
            ->where('timestamp', '>=', $from->utc()->format('Y-m-d H:i:s'))
            ->where('timestamp', '<', $to->addDay()->utc()->format('Y-m-d H:i:s'))
            ->selectRaw('quality_flag, count(*) as total')
            ->groupBy('quality_flag')
            ->pluck('total', 'quality_flag');

        $total = (int) $counts->sum();

        if ($total === 0) {
            $this->warn('Nenhuma medição foi gravada. Veja o log (a classificação pode ter falhado).');

            return;
        }

        $this->table(
            ['Medições', 'Válidas', 'Suspeitas', 'Inválidas', 'Lacunas'],
            [[$total, $counts[0] ?? 0, $counts[2] ?? 0, $counts[1] ?? 0, $counts[3] ?? 0]],
        );
    }

    /**
     * O FetchStationData começa cada coleta no maior fetched_end_at da estação. Se o histórico terminar
     * depois da última coleta real, a próxima coleta começa dele.
     */
    private function warnAboutFetchCursor(Station $station, CarbonImmutable $lastWindowEnd): void
    {
        $cursor = $station->rawData()->max('fetched_end_at');

        if ($cursor !== null && (string) $cursor === $lastWindowEnd->utc()->format('Y-m-d H:i:s')) {
            $this->warn("O histórico agora é o último registro bruto de {$station->name}: o próximo FetchStationData começa em {$lastWindowEnd->utc()->toDateTimeString()} UTC.");
        }
    }

    /** @param list<int> $stationIds */
    private function notifyDashboard(array $stationIds, CarbonImmutable $to): void
    {
        $date = $to->toDateString();
        $batchId = 'backfill-'.Str::uuid()->toString();

        foreach ($stationIds as $id) {
            Cache::put("weather.classified.{$id}.{$date}", $batchId, now()->addDays(7));
        }

        try {
            MeasurementsClassified::dispatch($stationIds, $date, $batchId);
        } catch (Throwable $e) {
            $this->warn("Não foi possível avisar o dashboard: {$e->getMessage()}");
        }
    }
}
