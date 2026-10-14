<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateStationReport;
use App\Models\StationReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StationReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $reports = StationReport::query()
            ->where('user_id', $request->user()->id)
            ->where('station_id', $validated['station_id'])
            ->where('reference_date', '>=', $validated['date'])
            ->where(
                'reference_date',
                '<',
                CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'])->addDay()->toDateString(),
            )
            ->with('station:id,name,provider')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (StationReport $report): array => $report->toClient());

        return response()->json($reports);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'format' => ['required', Rule::in(StationReport::FORMATS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = StationReport::query()->create([
            'station_id' => $validated['station_id'],
            'user_id' => $request->user()->id,
            'reference_date' => $validated['date'],
            'format' => $validated['format'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        GenerateStationReport::dispatch($report);

        return response()->json($report->toClient(), 202);
    }

    public function show(Request $request, StationReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        return response()->json(['report' => $report->toClient()]);
    }

    public function download(Request $request, StationReport $report): StreamedResponse
    {
        $this->authorizeReport($request, $report);

        abort_unless(
            $report->status === 'ready'
                && $report->path
                && Storage::disk('local')->exists($report->path),
            404,
        );

        return Storage::disk('local')->download($report->path, basename($report->path));
    }

    private function authorizeReport(Request $request, StationReport $report): void
    {
        abort_unless($report->user_id === $request->user()->id, 404);
    }
}
