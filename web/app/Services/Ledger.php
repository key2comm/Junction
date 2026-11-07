<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way money moves. Each post locks the account row, appends an
 * immutable ledger entry and updates accounts.balance in one transaction, so
 * balance always equals the sum of the account's entries.
 *
 * Sign convention: positive amounts credit the account (payments, refunds),
 * negative amounts debit it (usage charges).
 */
class Ledger
{
    public function post(
        Account $account,
        LedgerEntryType $type,
        string $amount,
        string $description,
        ?Model $reference = null,
        ?User $user = null,
    ): LedgerEntry {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Amount must be numeric, got [{$amount}].");
        }
        $amount = Decimal::round($amount, 6);
        if (bccomp($amount, '0', 6) === 0) {
            throw new InvalidArgumentException('Ledger entries cannot be zero.');
        }
        if ($type === LedgerEntryType::Payment && bccomp($amount, '0', 6) < 0) {
            throw new InvalidArgumentException('Payments must be positive; use an adjustment to take money off an account.');
        }
        if ($type === LedgerEntryType::Usage && bccomp($amount, '0', 6) > 0) {
            throw new InvalidArgumentException('Usage charges must be negative.');
        }

        return DB::transaction(function () use ($account, $type, $amount, $description, $reference, $user) {
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $balance = bcadd((string) $locked->balance, $amount, 6);

            $entry = new LedgerEntry([
                'account_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balance,
                'currency' => $locked->currency,
                'description' => $description,
                'user_id' => $user?->id,
            ]);
            if ($reference) {
                $entry->reference()->associate($reference);
            }
            $entry->save();

            $locked->forceFill(['balance' => $balance])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }

    public function payment(Account $account, string $amount, string $description, ?User $user = null): LedgerEntry
    {
        return $this->post($account, LedgerEntryType::Payment, $amount, $description, user: $user);
    }

    public function adjust(Account $account, string $amount, string $description, ?User $user = null): LedgerEntry
    {
        return $this->post($account, LedgerEntryType::Adjustment, $amount, $description, user: $user);
    }
}
