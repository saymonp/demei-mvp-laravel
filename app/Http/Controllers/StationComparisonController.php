<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StationComparisonController extends Controller
{
    public function index(Request $request): Response
    {
        $v = $request->validate([
            'stations' => ['nullable', 'array', 'min:2', 'max:4'],
            'stations.*' => ['integer', 'exists:stations,id'],
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'compare_start' => ['nullable', 'date_format:Y-m-d'],
            'compare_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:compare_start'],
        ]);

        $stations = Station::query()->orderBy('name')->get(['id', 'name', 'provider']);
        $ids = collect($v['stations'] ?? $stations->take(3)->pluck('id'))->map(fn ($id) => (int) $id)->values();
        $today = CarbonImmutable::now('America/Sao_Paulo')->format('Y-m-d');
        $periodA = ['start' => $v['start'] ?? $today, 'end' => $v['end'] ?? ($v['start'] ?? $today)];
        $periodB = isset($v['compare_start']) ? ['start' => $v['compare_start'], 'end' => $v['compare_end'] ?? $v['compare_start']] : null;

        return Inertia::render('StationComparison', [
            'stations' => $stations,
            'selectedStationIds' => $ids,
            'periodA' => $periodA,
            'periodB' => $periodB,
            'series' => fn () => $this->series($ids->all(), $periodA),
            'seriesB' => fn () => $periodB ? $this->series($ids->all(), $periodB) : (object) [],
            'overrides' => fn () => $this->overrides($ids->all(), $periodB ? [$periodA, $periodB] : [$periodA]),
            'canAudit' => $request->user() !== null,
        ]);
    }

    public function override(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'measurement_id' => ['required', 'integer', 'exists:measurements,id'],
            'quality_flag' => ['required', 'integer', 'in:0,1,2,3'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $measurement = Measurement::query()->findOrFail($v['measurement_id']);

        $now = now();

        DB::table('measurement_overrides')->upsert(
            [[
                'measurement_id' => $measurement->id,
                'station_id' => $measurement->station_id,
                'sensor_measurement_type' => $measurement->sensor_measurement_type,
                'timestamp' => $measurement->timestamp,
                'quality_flag' => $v['quality_flag'],
                'original_flag' => $measurement->quality_flag instanceof \BackedEnum
                    ? $measurement->quality_flag->value
                    : $measurement->quality_flag,
                'user_id' => $request->user()?->id,
                'note' => $v['note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['measurement_id'],
            ['quality_flag', 'original_flag', 'user_id', 'note', 'updated_at'],
        );

        return back();
    }

    public function destroyOverride(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'measurement_id' => ['required', 'integer', 'exists:measurements,id'],
        ]);
        DB::table('measurement_overrides')->where('measurement_id', $v['measurement_id'])->delete();

        return back();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(array $p): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $p['start'], 'America/Sao_Paulo');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $p['end'], 'America/Sao_Paulo')->addDay();
        // Limita a 31 dias para manter o payload leve.
        if ($start->diffInDays($end) > 31) {
            $end = $start->addDays(31);
        }

        return [$start->utc(), $end->utc()];
    }

    private function series(array $ids, array $period): array
    {
        [$start, $end] = $this->range($period);

        return Measurement::query()
            ->whereIn('station_id', $ids)
            ->where('timestamp', '>=', $start)->where('timestamp', '<', $end)
            ->orderBy('timestamp')
            ->get(['id', 'station_id', 'timestamp', 'sensor_measurement_type', 'value', 'quality_flag', 'flag_reason'])
            ->groupBy('station_id')
            ->map(fn ($rows) => $rows->map(fn ($m) => [
                'measurement_id' => $m->id,
                'timestamp' => CarbonImmutable::parse($m->timestamp, 'UTC')->utc()->toIso8601String(),
                'sensor_measurement_type' => $m->sensor_measurement_type,
                'value' => $m->value === null ? null : (float) $m->value,
                'quality_flag' => $m->quality_flag->value,
                'flag_reason' => $m->flag_reason,
            ])->values())
            ->all();
    }

    /** Chave igual a overrideKey() no front: "station|measurement|id". */
    private function overrides(array $ids, array $periods): array
    {
        $q = DB::table('measurement_overrides')->whereIn('station_id', $ids)
            ->whereNotNull('measurement_id')->where(function ($w) use ($periods) {
                foreach ($periods as $p) {
                    [$s, $e] = $this->range($p);
                    $w->orWhere(fn ($x) => $x->where('timestamp', '>=', $s)->where('timestamp', '<', $e));
                }
            });

        return $q->get()->mapWithKeys(fn ($o) => [
            $o->station_id.'|measurement|'.$o->measurement_id => (int) $o->quality_flag,
        ])->all();
    }
}
