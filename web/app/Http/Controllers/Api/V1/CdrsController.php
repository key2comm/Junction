<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\CdrController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAuth;
use App\Models\Cdr;
use App\Models\Invoice;
use App\Support\CdrFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Call records and invoices. Customer-scoped tokens only ever see their own,
 * and never vendor or cost fields.
 */
class CdrsController extends Controller
{
    use Paginates;

    /** Same filters as the admin call records page; newest first; cursor-paginated. */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $customer = ApiAuth::scopedAccountId($request) !== null;

        $page = $filters->apply(Cdr::query())->orderByDesc('id')->cursorPaginate($this->perPage($request));

        return $this->cursored($page->withQueryString(), fn (Cdr $c) => Present::cdr($c, $customer));
    }

    public function show(Request $request, Cdr $cdr): JsonResponse
    {
        $scope = ApiAuth::scopedAccountId($request);
        abort_if($scope !== null && $scope !== $cdr->customer_account_id, 404);

        return response()->json(['data' => [
            ...Present::cdr($cdr, $scope !== null),
            ...($scope === null ? ['attempt_log' => $cdr->attemptLog()->orderBy('attempt')->get()->map(fn ($a) => Present::attempt($a))] : []),
        ]]);
    }

    public function summary(Request $request): JsonResponse
    {
        $summary = CdrController::summary($this->filters($request));
        if (ApiAuth::scopedAccountId($request) !== null) {
            unset($summary['buy'], $summary['margin']);
        }

        return response()->json(['data' => $summary]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $scope = ApiAuth::scopedAccountId($request);
        $request->validate(['customer_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:issued,void']]);

        $page = Invoice::query()
            ->when($scope ?? $request->integer('customer_id') ?: null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paged($page, fn (Invoice $i) => Present::invoice($i));
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        $scope = ApiAuth::scopedAccountId($request);
        abort_if($scope !== null && $scope !== $invoice->account_id, 404);

        return response()->json(['data' => Present::invoice($invoice->load('lines'), true)]);
    }

    /** Customer tokens are forced to their own account and can't filter by vendor/trunk. */
    private function filters(Request $request): CdrFilters
    {
        if ($scope = ApiAuth::scopedAccountId($request)) {
            $request->merge(['customer' => $scope, 'vendor' => null, 'trunk' => null]);
        } else {
            $request->merge(array_filter([
                'customer' => $request->input('customer_id'),
                'vendor' => $request->input('vendor_id'),
                'trunk' => $request->input('trunk_id'),
            ], fn ($v) => $v !== null));
        }

        return CdrFilters::fromRequest($request);
    }
}
