<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Models\VendorInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Compares invoices received from vendors with our own CDRs (what we think we
 * owe), in total and per destination prefix, and settles them: accepting books
 * the difference as a payables adjustment so what we owe matches what we pay.
 */
class VendorReconciliation
{
    /** Header aliases for the vendor's breakdown CSV. */
    public const COLUMNS = [
        'prefix' => ['prefix', 'code', 'dial_code', 'dialcode', 'destination_code'],
        'destination' => ['destination', 'description', 'name', 'country'],
        'calls' => ['calls', 'attempts_answered', 'connected_calls', 'count'],
        'minutes' => ['minutes', 'mins', 'duration_min', 'billed_minutes', 'duration'],
        'amount' => ['amount', 'cost', 'total', 'charge', 'charges'],
    ];

    /** Our figures for a vendor and period: totals and per buy prefix. */
    public function ours(Account $vendor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = fn () => DB::table('cdrs')->where('vendor_account_id', $vendor->id)
            ->where('started_at', '>=', $start)->where('started_at', '<', $end)->whereNotNull('buy_cost');

        $t = $base()->selectRaw('count(*) AS calls, coalesce(sum(billsec), 0) AS billsec, coalesce(sum(buy_cost), 0) AS amount')->first();
        $lines = $base()->groupBy('buy_prefix')
            ->selectRaw('buy_prefix AS prefix, count(*) AS calls, sum(billsec) AS billsec, sum(buy_cost) AS amount')
            ->get()->keyBy('prefix');

        return [
            'calls' => (int) $t->calls,
            'minutes' => round((int) $t->billsec / 60, 2),
            'amount' => bcadd((string) $t->amount, '0', 6),
            'lines' => $lines,
        ];
    }

    /**
     * Record a vendor invoice. $breakdown is their optional per-destination CSV.
     *
     * @param  array{reference: string, period_start: CarbonImmutable, period_end: CarbonImmutable, timezone: string,
     *     amount: string, calls?: ?int, minutes?: ?string, notes?: ?string}  $data
     */
    public function record(Account $vendor, array $data, ?UploadedFile $breakdown = null, ?User $user = null): VendorInvoice
    {
        $lines = $breakdown ? $this->parse($breakdown->getRealPath()) : [];
        $ours = $this->ours($vendor, $data['period_start'], $data['period_end']);

        // Their call count: as entered, else summed from their breakdown if it has one.
        $theirCalls = $data['calls'] ?? null;
        if ($theirCalls === null && $lines !== [] && collect($lines)->every(fn ($l) => $l['calls'] !== null)) {
            $theirCalls = array_sum(array_column($lines, 'calls'));
        }

        return DB::transaction(function () use ($vendor, $data, $breakdown, $user, $lines, $ours, $theirCalls) {
            $invoice = VendorInvoice::create([
                'account_id' => $vendor->id,
                'reference' => $data['reference'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'timezone' => $data['timezone'],
                'currency' => $vendor->currency,
                'amount' => $data['amount'],
                'calls' => $theirCalls,
                'minutes' => $data['minutes'] ?? null,
                'our_amount' => $ours['amount'],
                'our_calls' => $ours['calls'],
                'our_minutes' => $ours['minutes'],
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
                'filename' => $breakdown ? mb_substr($breakdown->getClientOriginalName(), 0, 255) : null,
                'user_id' => $user?->id,
            ]);
            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice;
        });
    }

    /** Re-read our CDR figures (e.g. after late CDRs arrived). */
    public function refresh(VendorInvoice $invoice): VendorInvoice
    {
        $ours = $this->ours($invoice->account, CarbonImmutable::parse($invoice->period_start), CarbonImmutable::parse($invoice->period_end));
        $invoice->update(['our_amount' => $ours['amount'], 'our_calls' => $ours['calls'], 'our_minutes' => $ours['minutes']]);

        return $invoice;
    }

    /**
     * Side-by-side per prefix: theirs (if they sent a breakdown) vs ours,
     * largest absolute difference first.
     *
     * @return Collection<int, array{prefix: ?string, destination: ?string, their_calls: ?int, their_minutes: ?string,
     *     their_amount: ?string, our_calls: int, our_minutes: float, our_amount: string, difference: string}>
     */
    public function comparison(VendorInvoice $invoice): Collection
    {
        $ours = $this->ours($invoice->account, CarbonImmutable::parse($invoice->period_start), CarbonImmutable::parse($invoice->period_end))['lines'];
        $theirs = $invoice->lines()->get()->keyBy('prefix');

        $prefixes = $ours->keys()->merge($theirs->keys())->unique()->values();

        return $prefixes->map(function ($prefix) use ($ours, $theirs) {
            $o = $ours[$prefix] ?? null;
            $t = $theirs[$prefix] ?? null;
            $ourAmount = bcadd((string) ($o->amount ?? '0'), '0', 6);
            $theirAmount = $t ? bcadd((string) $t->amount, '0', 6) : null;

            return [
                // (string): PHP turns array keys like "44" into integers.
                'prefix' => $prefix === '' || $prefix === null ? null : (string) $prefix,
                'destination' => $t?->destination,
                'their_calls' => $t?->calls,
                'their_minutes' => $t?->minutes,
                'their_amount' => $theirAmount,
                'our_calls' => (int) ($o->calls ?? 0),
                'our_minutes' => round((int) ($o->billsec ?? 0) / 60, 2),
                'our_amount' => $ourAmount,
                'difference' => $theirAmount === null ? bcsub('0', $ourAmount, 6) : bcsub($theirAmount, $ourAmount, 6),
            ];
        })->sortByDesc(fn ($r) => abs((float) $r['difference']))->values();
    }

    /** Accept the vendor's figure: book the difference so what we owe matches it. */
    public function accept(VendorInvoice $invoice, VendorLedger $ledger, ?User $user = null): VendorInvoice
    {
        return DB::transaction(function () use ($invoice, $ledger, $user) {
            $invoice = VendorInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'accepted') {
                throw new RuntimeException('This vendor invoice is already accepted.');
            }
            $difference = $invoice->difference();
            if (bccomp($difference, '0', 6) !== 0) {
                $ledger->post($invoice->account, 'adjustment', $difference,
                    "Vendor invoice {$invoice->reference}: their total vs our records", $invoice, $user, $invoice->period_end);
            }
            $invoice->update(['status' => 'accepted', 'resolved_at' => now()]);

            return $invoice;
        });
    }

    public function dispute(VendorInvoice $invoice, string $note): VendorInvoice
    {
        if ($invoice->status === 'accepted') {
            throw new RuntimeException('An accepted invoice cannot be disputed.');
        }
        $stamp = now()->format('Y-m-d H:i');
        $invoice->update([
            'status' => 'disputed',
            'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '')."[{$stamp}] Disputed: {$note}"),
            'resolved_at' => null,
        ]);

        return $invoice;
    }

    /** @return list<array{prefix: string, destination: ?string, calls: ?int, minutes: ?string, amount: string}> */
    public function parse(string $file): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open the breakdown file.');
        }
        try {
            $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($handle));
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);

            $map = [];
            foreach (str_getcsv($first, $delimiter, '"', '') as $i => $name) {
                $name = strtolower(trim(preg_replace('/[\s\-]+/', '_', (string) $name)));
                foreach (self::COLUMNS as $column => $aliases) {
                    if (! isset($map[$column]) && in_array($name, $aliases, true)) {
                        $map[$column] = $i;
                    }
                }
            }
            foreach (['prefix', 'amount'] as $required) {
                if (! isset($map[$required])) {
                    throw new RuntimeException("The breakdown needs a \"{$required}\" column.");
                }
            }

            $lines = $errors = [];
            $line = 1;
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if ($cells === [null] || implode('', $cells) === '') {
                    continue;
                }
                $get = fn ($c) => isset($map[$c]) ? trim((string) ($cells[$map[$c]] ?? '')) : '';
                $prefix = preg_replace('/[\s+\-]/', '', $get('prefix'));
                if (! preg_match('/^[0-9]{1,32}$/', $prefix)) {
                    $errors[] = "Line {$line}: prefix \"{$get('prefix')}\" must be digits.";
                } elseif (! is_numeric($get('amount'))) {
                    $errors[] = "Line {$line}: amount \"{$get('amount')}\" is not a number.";
                } elseif ($get('minutes') !== '' && ! is_numeric($get('minutes'))) {
                    $errors[] = "Line {$line}: minutes \"{$get('minutes')}\" is not a number.";
                } elseif (isset($lines[$prefix])) {
                    // Some vendors split a prefix across rate periods: merge.
                    $lines[$prefix]['amount'] = bcadd($lines[$prefix]['amount'], $get('amount'), 6);
                    $lines[$prefix]['minutes'] = $get('minutes') === '' ? $lines[$prefix]['minutes'] : (string) round((float) $lines[$prefix]['minutes'] + (float) $get('minutes'), 2);
                    $lines[$prefix]['calls'] = $get('calls') === '' ? $lines[$prefix]['calls'] : (int) $lines[$prefix]['calls'] + (int) $get('calls');
                } else {
                    $lines[$prefix] = [
                        'prefix' => $prefix,
                        'destination' => $get('destination') === '' ? null : mb_substr($get('destination'), 0, 255),
                        'calls' => ctype_digit($get('calls')) ? (int) $get('calls') : null,
                        'minutes' => $get('minutes') === '' ? null : (string) round((float) $get('minutes'), 2),
                        'amount' => bcadd($get('amount'), '0', 6),
                    ];
                }
                if (count($errors) >= 20) {
                    break;
                }
            }
            if ($errors) {
                throw new RuntimeException(implode(' ', $errors));
            }

            return array_values($lines);
        } catch (Throwable $e) {
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        } finally {
            fclose($handle);
        }
    }
}
