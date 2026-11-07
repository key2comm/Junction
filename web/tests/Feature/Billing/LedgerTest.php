<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Cdr;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    public function test_entries_move_the_balance_and_record_running_totals(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create();

        $this->ledger()->payment($account, '100', 'Wire transfer', $user);
        $usage = $this->ledger()->post($account, LedgerEntryType::Usage, '-12.3456789', 'Usage 2026-11-07', Cdr::factory()->create());
        $this->ledger()->adjust($account, '-0.5', 'Goodwill reversal');

        $this->assertSame('87.154321', $account->balance);
        $this->assertSame('87.154321', $account->fresh()->balance);
        $this->assertSame('-12.345679', $usage->amount);
        $this->assertSame('87.654321', $usage->balance_after);
        $this->assertInstanceOf(Cdr::class, $usage->reference);
        $this->assertSame($user->id, LedgerEntry::query()->first()->user_id);

        $sum = DB::table('ledger_entries')->where('account_id', $account->id)->sum('amount');
        $this->assertSame(0, bccomp((string) $sum, (string) $account->fresh()->balance, 6));
    }

    public function test_available_funds_include_credit_limit(): void
    {
        $account = Account::factory()->postpaid('500')->create();
        $this->ledger()->adjust($account, '-120', 'Opening usage');

        $this->assertSame('380.000000', $account->availableFunds());
    }

    public function test_invalid_entries_are_rejected(): void
    {
        $account = Account::factory()->create();

        foreach ([
            fn () => $this->ledger()->payment($account, '-5', 'Negative payment'),
            fn () => $this->ledger()->post($account, LedgerEntryType::Usage, '5', 'Positive usage'),
            fn () => $this->ledger()->adjust($account, '0.0000001', 'Rounds to zero'),
            fn () => $this->ledger()->adjust($account, 'ten', 'Not a number'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the ledger to reject the entry.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame('0.000000', $account->fresh()->balance);
    }

    public function test_ledger_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = $this->ledger()->payment(Account::factory()->create(), '10', 'Payment');

        foreach ([
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => 1000]),
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete(),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('Expected the database to reject the change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame('10.000000', $entry->fresh()->amount);
    }
}
