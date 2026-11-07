<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Cdr;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'UTC'));
        $this->customer = Account::factory()->create(['code' => 'TOR', 'timezone' => 'America/Toronto', 'billing_email' => 'ap@tor.example']);

        $ledger = app(Ledger::class);
        $ledger->post($this->customer, LedgerEntryType::Payment, '100', 'September payment', effectiveAt: $this->toronto('2026-09-20 10:00'));
        $ledger->post($this->customer, LedgerEntryType::Payment, '50', 'October payment', effectiveAt: $this->toronto('2026-10-05 10:00'));
    }

    private function toronto(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'America/Toronto')->utc();
    }

    private function callAt(string $localStart, string $cost, string $prefix = '44', string $destination = 'United Kingdom', int $billsec = 60): Cdr
    {
        return Cdr::factory()->create([
            'uuid' => (string) Str::uuid(), 'customer_account_id' => $this->customer->id, 'callee' => $prefix.'2079460000',
            'started_at' => $this->toronto($localStart), 'answered_at' => $this->toronto($localStart), 'billsec' => $billsec,
            'attempts' => 1, 'sell_prefix' => $prefix, 'sell_destination' => $destination, 'sell_rate' => '0.05', 'sell_cost' => $cost,
        ]);
    }

    private function october(): Invoice
    {
        [$start, $end] = InvoiceGenerator::monthPeriod($this->customer, CarbonImmutable::parse('2026-10-15'));

        return app(InvoiceGenerator::class)->generate($this->customer->fresh(), $start, $end);
    }

    public function test_october_statement_in_the_customers_time_zone(): void
    {
        $this->callAt('2026-10-15 09:00', '1.000000');
        $this->callAt('2026-10-20 09:00', '0.200000', '447', 'United Kingdom Mobile');
        $this->callAt('2026-10-31 23:50', '0.500000');   // = 1 Nov 03:50 UTC: still October in Toronto
        $this->callAt('2026-11-01 00:10', '0.250000');   // November in Toronto

        $invoice = $this->october();

        $this->assertSame('2026-10-01 04:00:00', $invoice->period_start->utc()->format('Y-m-d H:i:s')); // EDT midnight
        $this->assertSame('2026-11-01 04:00:00', $invoice->period_end->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('100.000000', $invoice->opening_balance);
        $this->assertSame('50.000000', $invoice->payments_total);
        $this->assertSame('1.700000', $invoice->usage_total);
        $this->assertSame('148.300000', $invoice->closing_balance);
        $this->assertSame('0.000000', $invoice->amount_due);    // prepaid
        $this->assertNull($invoice->due_at);
        $this->assertSame(3, $invoice->calls);
        $this->assertSame('ap@tor.example', $invoice->billing_email);
        $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice->number);

        $lines = $invoice->lines()->get();
        $this->assertSame(['44', '447'], $lines->pluck('prefix')->all());     // largest amount first
        $this->assertSame(['1.500000', '0.200000'], $lines->pluck('amount')->all());
        $this->assertSame('United Kingdom Mobile', $lines[1]->destination);

        // Generating posted the usage: November's call stays out of October.
        $this->assertSame(0, Cdr::query()->whereNull('posted_at')->count());
        $this->assertSame('148.050000', $this->customer->fresh()->balance);
    }

    public function test_usage_batches_never_cross_a_quarter_hour(): void
    {
        $this->callAt('2026-10-31 23:50', '0.500000');
        $this->callAt('2026-11-01 00:05', '0.250000');
        $this->artisan('softswitch:post-usage')->assertSuccessful();

        $usage = LedgerEntry::query()->where('type', 'usage')->orderBy('effective_at')->get();
        $this->assertCount(2, $usage);
        $this->assertSame('2026-11-01 03:50:00', $usage[0]->effective_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('-0.500000', $usage[0]->amount);
        $this->assertSame('-0.250000', $usage[1]->amount);
    }

    public function test_postpaid_invoices_have_an_amount_and_due_date(): void
    {
        $postpaid = Account::factory()->postpaid('1000')->create(['code' => 'POST', 'timezone' => 'UTC']);
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $postpaid->id, 'callee' => '44', 'started_at' => '2026-10-10 10:00:00+00',
            'answered_at' => '2026-10-10 10:00:00+00', 'billsec' => 600, 'attempts' => 1, 'sell_prefix' => '44', 'sell_rate' => '0.05', 'sell_cost' => '12.345678']);

        [$start, $end] = InvoiceGenerator::monthPeriod($postpaid, CarbonImmutable::parse('2026-10-15'));
        $invoice = app(InvoiceGenerator::class)->generate($postpaid, $start, $end);

        $this->assertSame('-12.345678', $invoice->closing_balance);
        $this->assertSame('12.345678', $invoice->amount_due);
        $this->assertSame('2026-11-18', $invoice->due_at->format('Y-m-d')); // issued 3 Nov + 15 days
    }

    public function test_an_invoice_is_refused_when_it_would_be_wrong(): void
    {
        $this->callAt('2026-10-15 09:00', '1.000000');
        $this->october();

        // Already issued.
        $this->expectExceptionMessageMatches('/already covers this period/');
        $this->october();
    }

    public function test_voiding_allows_reissue_with_a_new_number(): void
    {
        $first = $this->october();
        $first->update(['status' => 'void', 'void_reason' => 'wrong rates', 'voided_at' => now()]);

        $second = $this->october();
        $this->assertNotSame($first->number, $second->number);
        $this->assertSame('issued', $second->status);
    }

    public function test_ledger_and_call_records_must_agree(): void
    {
        // Usage on the ledger with no call records behind it (e.g. a manual mistake).
        app(Ledger::class)->post($this->customer, LedgerEntryType::Usage, '-5', 'Unexplained usage', effectiveAt: $this->toronto('2026-10-10 10:00'));

        try {
            $this->october();
            $this->fail('Expected the invoice to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not match the call records', $e->getMessage());
        }
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_current_month_cannot_be_invoiced(): void
    {
        [$start, $end] = InvoiceGenerator::monthPeriod($this->customer, now());

        $this->expectExceptionMessage('The period has not ended yet.');
        app(InvoiceGenerator::class)->generate($this->customer, $start, $end);
    }

    public function test_due_invoices_are_issued_per_time_zone(): void
    {
        $tokyo = Account::factory()->create(['code' => 'TYO', 'timezone' => 'Asia/Tokyo', 'balance' => 0]);
        $idle = Account::factory()->create(['code' => 'IDLE', 'timezone' => 'UTC']); // no activity, no balance
        $this->callAt('2026-10-15 09:00', '1.000000');

        // 1 Nov 05:00 UTC: Toronto's October ended 1h ago (grace is 2h); Tokyo's ended 20h ago but it had no activity.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 05:00', 'UTC'));
        $this->assertSame([], app(InvoiceGenerator::class)->issueDue());

        $this->travelTo(CarbonImmutable::parse('2026-11-01 06:30', 'UTC'));
        $results = app(InvoiceGenerator::class)->issueDue();
        $this->assertCount(1, $results);
        $this->assertSame('TOR', $results[0]['account']);
        $this->assertStringStartsWith('issued INV-', $results[0]['result']);

        // Idempotent.
        $this->assertSame([], app(InvoiceGenerator::class)->issueDue());
        $this->assertSame(0, Invoice::query()->whereIn('account_id', [$tokyo->id, $idle->id])->count());
    }

    public function test_invoice_pages_csv_and_void(): void
    {
        $this->callAt('2026-10-15 09:00', '1.000000');
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('accounts.invoices.store', $this->customer), ['month' => '2026-10'])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        $this->actingAs($admin)->post(route('accounts.invoices.store', $this->customer), ['month' => '2026-11'])->assertSessionHasErrors('month');

        $this->actingAs($admin)->get(route('invoices.index'))->assertInertia(fn (Assert $p) => $p->component('invoices/Index')->has('invoices.data', 1));
        $this->actingAs($admin)->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $p) => $p
            ->component('invoices/Show')->where('invoice.usage_total', '1.000000')->has('lines', 1));
        $this->actingAs($admin)->get(route('accounts.show', $this->customer))->assertInertia(fn (Assert $p) => $p->has('invoices', 1));

        $csv = $this->actingAs($admin)->get(route('invoices.csv', $invoice))->streamedContent();
        $this->assertStringContainsString("{$invoice->number},TOR,", $csv);
        $this->assertStringContainsString(',"United Kingdom",1,1,0.050000,1.000000,', $csv);

        $this->actingAs($admin)->post(route('invoices.void', $invoice), ['reason' => 'Test'])->assertRedirect();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->actingAs($admin)->post(route('invoices.void', $invoice), ['reason' => 'Again'])->assertSessionHasErrors('reason');

        auth()->logout();
        $this->get(route('invoices.index'))->assertRedirect(route('login')); // guests
    }
}
