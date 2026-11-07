<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\Cdr;
use App\Models\User;
use App\Models\VendorInvoice;
use App\Models\VendorLedgerEntry;
use App\Services\VendorLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class VendorBillingTest extends TestCase
{
    use RefreshDatabase;

    private Account $vendor;

    private Account $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'UTC'));
        $this->vendor = Account::factory()->vendor()->create(['code' => 'VEND']);
        $this->customer = Account::factory()->create(['code' => 'CUST']);
        $this->admin = User::factory()->create();
    }

    private function callVia(string $startedUtc, string $buyCost, string $prefix = '44', int $billsec = 60): Cdr
    {
        return Cdr::factory()->create([
            'uuid' => (string) Str::uuid(), 'customer_account_id' => $this->customer->id, 'vendor_account_id' => $this->vendor->id,
            'callee' => $prefix.'123', 'started_at' => $startedUtc, 'answered_at' => $startedUtc, 'billsec' => $billsec, 'attempts' => 1,
            'sell_cost' => bcmul($buyCost, '2', 6), 'buy_prefix' => $prefix, 'buy_rate' => '0.01', 'buy_cost' => $buyCost,
        ]);
    }

    public function test_vendor_usage_is_posted_from_buy_costs(): void
    {
        $this->callVia('2026-10-31 23:55:00+00', '0.300000');
        $this->callVia('2026-11-01 00:05:00+00', '0.200000');
        $this->callVia('2026-11-01 00:10:00+00', '0.100000');

        $this->artisan('softswitch:post-usage')->assertSuccessful();
        $this->artisan('softswitch:post-usage')->assertSuccessful(); // idempotent

        $entries = VendorLedgerEntry::query()->orderBy('effective_at')->get();
        $this->assertSame(['0.300000', '0.300000'], $entries->pluck('amount')->all()); // two 15-minute batches
        $this->assertSame('2026-10-31 23:55:00', $entries[0]->effective_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('0.600000', $this->vendor->fresh()->vendor_balance);
        $this->assertSame(0, Cdr::query()->whereNull('vendor_posted_at')->count());
        // The customer side is posted too, independently.
        $this->assertSame('-1.200000', $this->customer->fresh()->balance);
    }

    public function test_payments_and_adjustments(): void
    {
        $ledger = app(VendorLedger::class);
        $ledger->post($this->vendor, 'usage', '100', 'Usage');

        $this->actingAs($this->admin)->post(route('accounts.vendor-ledger.store', $this->vendor), ['type' => 'payment', 'amount' => '60', 'description' => 'Wire 123'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('accounts.vendor-ledger.store', $this->vendor), ['type' => 'adjustment', 'amount' => '-5', 'description' => 'Credit note'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('accounts.vendor-ledger.store', $this->vendor), ['type' => 'payment', 'amount' => '-10', 'description' => 'Wrong sign'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post(route('accounts.vendor-ledger.store', $this->customer), ['type' => 'payment', 'amount' => '10', 'description' => 'Not a vendor'])
            ->assertSessionHasErrors('amount');

        $this->assertSame('35.000000', $this->vendor->fresh()->vendor_balance);
        $this->assertSame('-60.000000', VendorLedgerEntry::query()->where('type', 'payment')->value('amount'));

        $this->expectException(InvalidArgumentException::class);
        $ledger->post($this->vendor, 'usage', '-1', 'Negative usage');
    }

    public function test_the_vendor_ledger_is_append_only(): void
    {
        $entry = app(VendorLedger::class)->post($this->vendor, 'usage', '10', 'Usage');

        $this->expectException(QueryException::class);
        DB::table('vendor_ledger_entries')->where('id', $entry->id)->update(['amount' => 1]);
    }

    public function test_a_vendor_invoice_is_compared_with_our_records(): void
    {
        $this->callVia('2026-10-05 10:00:00+00', '10.000000', '44');
        $this->callVia('2026-10-06 10:00:00+00', '5.000000', '447');
        $this->callVia('2026-10-31 23:30:00+00', '1.000000', '44'); // 1 Nov in UTC+1: outside a Europe/Paris October
        $this->callVia('2026-11-02 10:00:00+00', '9.000000', '44'); // November

        $csv = "Code;Description;Minutes;Cost\n44;UK;20;10.50\n447;UK Mobile;5;5.00\n33;France;1;0.25\n44;UK (peak);1;0.25\n";
        $this->actingAs($this->admin)->post(route('accounts.vendor-invoices.store', $this->vendor), [
            'reference' => 'V-1001', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'timezone' => 'Europe/Paris',
            'amount' => '16.00', 'breakdown' => UploadedFile::fake()->createWithContent('v1001.csv', $csv),
        ])->assertSessionHasNoErrors();

        $invoice = VendorInvoice::query()->sole();
        $this->assertSame('15.000000', $invoice->our_amount);       // the 23:30 UTC call is November in Paris
        $this->assertSame(2, $invoice->our_calls);
        $this->assertSame('1.000000', $invoice->difference());
        $this->assertSame(3, $invoice->lines()->count());           // 44 merged across two rows
        $this->assertSame('10.750000', $invoice->lines()->where('prefix', '44')->value('amount'));

        $this->actingAs($this->admin)->get(route('vendor-invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('vendor-invoices/Show')
                ->where('has_breakdown', true)
                ->where('invoice.difference', '1.000000')
                ->where('comparison.0.prefix', '44')          // biggest difference first (0.75)
                ->where('comparison.0.difference', '0.750000')
                ->where('comparison.1.prefix', '33')          // they bill a destination we never sent
                ->where('comparison.1.our_amount', '0.000000'));

        // Duplicate reference for the same vendor is refused.
        $this->actingAs($this->admin)->post(route('accounts.vendor-invoices.store', $this->vendor), [
            'reference' => 'V-1001', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'timezone' => 'UTC', 'amount' => '1',
        ])->assertSessionHasErrors('reference');
    }

    public function test_a_bad_breakdown_is_rejected_whole(): void
    {
        $this->actingAs($this->admin)->post(route('accounts.vendor-invoices.store', $this->vendor), [
            'reference' => 'V-BAD', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'timezone' => 'UTC', 'amount' => '1',
            'breakdown' => UploadedFile::fake()->createWithContent('bad.csv', "prefix,amount\n44,ten\nUK,1\n"),
        ])->assertSessionHasErrors('breakdown');

        $this->assertSame(0, VendorInvoice::query()->count());
    }

    public function test_accepting_books_the_difference_and_disputes_keep_notes(): void
    {
        $this->callVia('2026-10-05 10:00:00+00', '10.000000');
        $this->artisan('softswitch:post-usage');
        $store = fn (string $ref, string $amount) => $this->actingAs($this->admin)->post(route('accounts.vendor-invoices.store', $this->vendor), [
            'reference' => $ref, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'timezone' => 'UTC', 'amount' => $amount,
        ]);

        $store('V-1', '10.40');
        $first = VendorInvoice::query()->where('reference', 'V-1')->sole();
        $this->actingAs($this->admin)->post(route('vendor-invoices.dispute', $first), ['note' => 'Minutes look inflated'])->assertSessionHasNoErrors();
        $this->assertSame('disputed', $first->fresh()->status);
        $this->assertStringContainsString('Minutes look inflated', $first->fresh()->notes);

        $this->actingAs($this->admin)->post(route('vendor-invoices.accept', $first))->assertSessionHasNoErrors();
        $this->assertSame('accepted', $first->fresh()->status);
        // Owed: 10.00 from our CDRs + 0.40 accepted difference.
        $this->assertSame('10.400000', $this->vendor->fresh()->vendor_balance);
        $adjustment = VendorLedgerEntry::query()->where('type', 'adjustment')->sole();
        $this->assertSame('0.400000', $adjustment->amount);
        $this->assertSame(VendorInvoice::class, $adjustment->reference_type);

        $this->actingAs($this->admin)->post(route('vendor-invoices.accept', $first))->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->post(route('vendor-invoices.dispute', $first), ['note' => 'Too late'])->assertSessionHasErrors('note');

        // A matching invoice books nothing extra.
        $store('V-2', '0');
        DB::table('cdrs')->delete();
        $second = VendorInvoice::query()->where('reference', 'V-2')->sole();
        $this->actingAs($this->admin)->post(route('vendor-invoices.refresh', $second));
        $this->actingAs($this->admin)->post(route('vendor-invoices.accept', $second));
        $this->assertSame(1, VendorLedgerEntry::query()->where('type', 'adjustment')->count());
    }

    public function test_vendor_account_page_shows_payables(): void
    {
        app(VendorLedger::class)->post($this->vendor, 'usage', '12.5', 'Usage');

        $this->actingAs($this->admin)->get(route('accounts.show', $this->vendor))
            ->assertInertia(fn (Assert $p) => $p
                ->where('account.vendor_balance', '12.500000')
                ->has('vendorLedger', 1)
                ->has('vendorInvoices', 0));
    }
}
