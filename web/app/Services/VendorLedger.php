<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Models\VendorLedgerEntry;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way vendor payables move. Mirrors App\Services\Ledger, but tracks
 * what WE owe: usage increases it, payments we make reduce it.
 *
 * Sign convention: positive = we owe more, negative = we owe less.
 */
class VendorLedger
{
    public const TYPES = ['usage', 'payment', 'adjustment'];

    public function post(
        Account $vendor,
        string $type,
        string $amount,
        string $description,
        ?Model $reference = null,
        ?User $user = null,
        ?DateTimeInterface $effectiveAt = null,
    ): VendorLedgerEntry {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown vendor ledger type [{$type}].");
        }
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Amount must be numeric, got [{$amount}].");
        }
        $amount = Decimal::round($amount, 6);
        if (bccomp($amount, '0', 6) === 0) {
            throw new InvalidArgumentException('Entries cannot be zero.');
        }
        if ($type === 'usage' && bccomp($amount, '0', 6) < 0) {
            throw new InvalidArgumentException('Vendor usage must be positive (it increases what we owe).');
        }
        if ($type === 'payment' && bccomp($amount, '0', 6) > 0) {
            throw new InvalidArgumentException('Payments to a vendor must be negative (they reduce what we owe).');
        }
        if (! $vendor->is_vendor) {
            throw new InvalidArgumentException("{$vendor->code} is not a vendor account.");
        }

        return DB::transaction(function () use ($vendor, $type, $amount, $description, $reference, $user, $effectiveAt) {
            $locked = Account::query()->whereKey($vendor->getKey())->lockForUpdate()->firstOrFail();
            $owed = bcadd((string) $locked->vendor_balance, $amount, 6);

            $entry = new VendorLedgerEntry([
                'account_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $owed,
                'currency' => $locked->currency,
                'description' => $description,
                'user_id' => $user?->id,
                'effective_at' => $effectiveAt ?? now(),
            ]);
            if ($reference) {
                $entry->reference()->associate($reference);
            }
            $entry->save();

            $locked->forceFill(['vendor_balance' => $owed])->save();
            $vendor->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }
}
