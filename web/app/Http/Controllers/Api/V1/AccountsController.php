<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Enums\LedgerEntryType;
use App\Http\Controllers\Admin\AccountIpController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAuth;
use App\Http\Requests\Admin\AccountRequest;
use App\Models\Account;
use App\Models\AccountIp;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AccountsController extends Controller
{
    use Paginates;

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:customer,vendor'],
            'status' => ['nullable', 'in:'.implode(',', AccountStatus::values())],
        ]);
        $scope = ApiAuth::scopedAccountId($request);

        $page = Account::query()
            ->when($scope, fn ($q) => $q->whereKey($scope))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->whereLike('name', "%{$t}%", caseSensitive: false)->orWhereLike('code', "%{$t}%", caseSensitive: false)))
            ->when(($f['role'] ?? null) === 'customer', fn ($q) => $q->customers())
            ->when(($f['role'] ?? null) === 'vendor', fn ($q) => $q->vendors())
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paged($page, fn (Account $a) => Present::account($a, $scope !== null));
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        return response()->json(['data' => Present::account($account, ApiAuth::scopedAccountId($request) !== null)]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = Account::create(AccountRequest::validateData($request->all()));

        return response()->json(['data' => Present::account($account->fresh())], 201);
    }

    /** Partial update: omitted fields keep their current values. */
    public function update(Request $request, Account $account): JsonResponse
    {
        $current = [
            ...$account->only(['name', 'code', 'is_customer', 'is_vendor', 'currency', 'credit_limit', 'low_balance_threshold', 'timezone',
                'billing_email', 'noc_email', 'sell_rate_deck_id', 'routing_plan_id', 'max_channels', 'max_cps', 'notes']),
            'status' => $account->status->value,
            'billing_type' => $account->billing_type->value,
        ];
        $account->update(AccountRequest::validateData([...$current, ...$request->all()], $account));

        return response()->json(['data' => Present::account($account->fresh())]);
    }

    public function ips(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        return response()->json(['data' => $account->ips()->orderBy('ip')->get()->map(fn ($ip) => Present::ip($ip))]);
    }

    public function storeIp(Request $request, Account $account): JsonResponse
    {
        $ip = AccountIpController::createFor($account, $request->all());

        return response()->json(['data' => Present::ip($ip)], 201);
    }

    public function destroyIp(Account $account, AccountIp $ip): Response
    {
        $ip->delete();

        return response()->noContent();
    }

    public function ledger(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        return $this->paged($account->ledgerEntries()->orderByDesc('id')->paginate($this->perPage($request)), fn ($e) => Present::ledgerEntry($e));
    }

    /** Record a payment (positive) or an adjustment (±). Usage is posted by the switch, never via the API. */
    public function storeLedgerEntry(Request $request, Account $account, Ledger $ledger): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:payment,adjustment'],
            'amount' => ['required', 'numeric', 'decimal:0,6', 'not_in:0', 'min:-10000000', 'max:10000000'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        try {
            $entry = $ledger->post($account, LedgerEntryType::from($data['type']), (string) $data['amount'], $data['description'], user: $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return response()->json(['data' => Present::ledgerEntry($entry), 'balance' => $account->fresh()->balance], 201);
    }

    private function authorizeAccount(Request $request, Account $account): void
    {
        $scope = ApiAuth::scopedAccountId($request);
        abort_if($scope !== null && $scope !== $account->id, 404);
    }
}
