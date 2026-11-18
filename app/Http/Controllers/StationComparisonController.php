<?php

namespace App\Http\Controllers;

use App\Models\Measurement;
use App\Models\Station;
use App\Services\StationComparisonData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StationComparisonController extends Controller
{
    public function index(Request $request, StationComparisonData $comparisonData): Response
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

        return Inertia::render('StationComparison', array_merge([
            'stations' => $stations,
            'canAudit' => $request->user() !== null,
        ], $comparisonData->props($ids->all(), $periodA, $periodB)));
    }

    public function series(Request $request, StationComparisonData $comparisonData): JsonResponse
    {
        $v = $request->validate([
            'stations' => ['required', 'array', 'min:2', 'max:4'],
            'stations.*' => ['integer', 'exists:stations,id'],
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);
        $period = ['start' => $v['start'], 'end' => $v['end']];

        if ($comparisonData->requiresChunking($period)) {
            throw ValidationException::withMessages([
                'end' => 'Cada consulta pode abranger no máximo '.StationComparisonData::MAX_CHUNK_DAYS.' dias.',
            ]);
        }

        return response()->json($comparisonData->chunk(
            array_map('intval', $v['stations']),
            $period,
        ));
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
}
