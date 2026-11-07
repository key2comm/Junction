<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\QualityReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Vendor quality: ASR, ACD, PDD and failure mix per vendor, trunk or destination. */
class QualityController extends Controller
{
    public function index(Request $request, QualityReport $report): Response
    {
        [$from, $to, $group, $vendor, $min] = self::params($request);
        $rows = $report->rows($from, $to, $group, $vendor, $min);

        return Inertia::render('quality/Index', [
            'rows' => $rows,
            'series' => $group === 'trunk' ? $report->hourlyByTrunk($from, $to, $rows->pluck('trunk_id')->filter()->all()) : (object) [],
            'filters' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'group' => $group, 'vendor' => $vendor, 'min' => $min],
            'vendors' => Account::query()->vendors()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /** API: GET /api/v1/quality */
    public function api(Request $request, QualityReport $report): JsonResponse
    {
        [$from, $to, $group, $vendor, $min] = self::params($request);

        return response()->json([
            'data' => $report->rows($from, $to, $group, $vendor, $min),
            'meta' => ['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z'), 'group' => $group],
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: ?int, 4: int} */
    private static function params(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'group' => ['nullable', 'in:'.implode(',', QualityReport::GROUPS)],
            'vendor' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'min' => ['nullable', 'integer', 'between:1,100000'],
        ]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : CarbonImmutable::now('UTC');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $to->subDay();
        if ($from->gte($to) || $from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['from' => 'Choose a range of up to 31 days, start before end.']);
        }

        return [$from, $to, $data['group'] ?? 'trunk', $data['vendor'] ?? $data['vendor_id'] ?? null, $data['min'] ?? 1];
    }
}
