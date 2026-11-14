<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\BillingItem;
use App\Models\Cdr;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\Tax;
use App\Models\User;
use App\Services\BillingCharges;
use App\Services\InvoiceGenerator;
use App\Services\Ledger;
use App\Support\Billing;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Billing → Taxes: per province/state, on plan items and usage as they're posted. */
class TaxesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    private BillingItem $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 12:00:00');
        $this->admin = User::factory()->create();
        $this->customer = Account::factory()->create(['timezone' => 'UTC', 'tax_region' => 'CA-QC', 'billing_type' => 'postpaid']);
        $this->hosting = BillingItem::create(['name' => 'Hosting', 'unit' => 'ext', 'price' => '20', 'frequency' => 'monthly']);
    }

    private function balance(): string
    {
        return (string) $this->customer->fresh()->balance;
    }

    public function test_canada_comes_pre_filled_and_every_state_is_listed(): void
    {
        $this->assertSame(['HST' => '13.0000'], Tax::query()->where('region', 'CA-ON')->pluck('rate', 'name')->map(fn ($r) => (string) $r)->all());
        $this->assertSame(['GST', 'QST'], Tax::query()->where('region', 'CA-QC')->orderBy('position')->pluck('name')->all());
        $this->assertSame('14.0000', (string) Tax::query()->where('region', 'CA-NS')->value('rate'));
        $this->assertSame(51, Tax::query()->where('region', 'like', 'US-%')->where('rate', 0)->count());

        $as = $this->actingAs($this->admin);
        $as->get(route('taxes.index'))->assertInertia(fn (Assert $p) => $p->component('billing/Taxes')
            ->where('countries.0.regions.10.code', 'CA-QC')->where('countries.0.regions.10.customers', 1)->has('countries.1.regions', 51));
        $ny = Tax::query()->where('region', 'US-NY')->sole();
        $as->patch(route('taxes.update', $ny), ['name' => 'Sales tax', 'rate' => '4', 'active' => '1'])->assertRedirect();
        $this->assertSame('4.0000', (string) $ny->fresh()->rate);
        $as->post(route('taxes.store'), ['region' => 'US-NY', 'name' => '911 fee', 'rate' => '1.5'])->assertSessionHasNoErrors();
        $as->post(route('taxes.store'), ['region' => 'US-NY', 'name' => '911 fee', 'rate' => '2'])->assertSessionHasErrors('name');
        $as->post(route('taxes.store'), ['region' => 'XX-YY', 'name' => 'X', 'rate' => '2'])->assertSessionHasErrors('region');
    }

    public function test_plan_charges_and_their_credits_are_taxed_at_the_customers_rates(): void
    {
        $charges = app(BillingCharges::class);
        $line = $charges->add($this->customer, $this->hosting, '10'); // 200 × 15/30 = 100
        // GST 5% = 5, QST 9.975% = 9.975
        $this->assertSame('-114.975000', $this->balance());
        $this->assertSame(['GST' => '5.000000', 'QST' => '9.975000'], DB::table('tax_postings')->pluck('amount', 'name')->map(fn ($a) => (string) $a)->all());

        $charges->remove($line->fresh()); // the same 15 days back, with their tax
        $this->assertSame('0.000000', $this->balance());

        // Without a region, nothing is taxed.
        $other = Account::factory()->create(['timezone' => 'UTC']);
        $charges->add($other, $this->hosting, '1');
        $this->assertSame('-10.000000', (string) $other->fresh()->balance);
    }

    public function test_usage_is_taxed_when_posted_and_the_invoice_totals_each_tax(): void
    {
        Settings::put([Billing::TAX_NUMBERS => "GST/HST 123456789 RT0001\nQST 1234567890 TQ0001"]);
        $deck = RateDeck::factory()->create();
        $rate = Rate::factory()->for($deck)->create(['prefix' => '44', 'rate' => '0.60', 'initial_increment' => 60, 'increment' => 60]);
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $this->customer->id, 'started_at' => now()->subHour(),
            'answered_at' => now()->subHour(), 'billsec' => 600, 'sell_rate' => '0.60', 'sell_prefix' => '44', 'sell_cost' => $rate->costFor(600)]);
        app(BillingCharges::class)->add($this->customer, $this->hosting, '10');

        Artisan::call('softswitch:post-usage', ['--account' => $this->customer->id]);
        // Usage 6.00: GST 0.30, QST 0.5985.
        $this->assertSame(['charge' => 2, 'usage' => 2], DB::table('tax_postings')->groupBy('source')->selectRaw('source, count(*) AS n')->orderBy('source')->pluck('n', 'source')->map(fn ($n) => (int) $n)->all());

        $this->travelTo('2026-12-01 03:00:00');
        $invoice = app(InvoiceGenerator::class)->generate($this->customer->fresh(), ...InvoiceGenerator::monthPeriod($this->customer, CarbonImmutable::parse('2026-11-15')));

        // Charges: 100 prorated + 200 December = 300; usage 6. GST 5% of 306 = 15.30; QST 9.975% of 306 = 30.5235.
        $this->assertSame([['GST', '306.000000', '15.300000'], ['QST', '306.000000', '30.523500']],
            $invoice->taxes()->get()->map(fn ($t) => [$t->name, (string) $t->base, (string) $t->amount])->all());
        $this->assertSame('45.823500', (string) $invoice->taxes_total);
        $this->assertSame('-351.823500', (string) $invoice->closing_balance);
        $this->assertSame($this->balance(), (string) $invoice->closing_balance);
        $this->assertSame('351.823500', (string) $invoice->amount_due);
        $this->assertSame('CA-QC', $invoice->tax_region);
        $this->assertStringContainsString('RT0001', $invoice->tax_numbers);

        $this->actingAs($this->admin)->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $p) => $p
            ->has('taxes', 2)->where('invoice.taxes_total', '45.823500')->where('invoice.tax_numbers', $invoice->tax_numbers));
    }

    public function test_the_customer_form_and_settings_store_the_region_and_tax_numbers(): void
    {
        $as = $this->actingAs($this->admin);
        $as->put(route('accounts.update', $this->customer), [
            ...$this->customer->only(['name', 'code', 'currency', 'credit_limit', 'timezone']), 'status' => 'active',
            'role' => 'customer', 'billing_type' => 'prepaid', 'tax_region' => 'CA-ON',
        ])->assertSessionHasNoErrors()->assertRedirect(route('accounts.show', $this->customer));
        $this->assertSame('CA-ON', $this->customer->fresh()->tax_region);
        $as->get(route('accounts.show', $this->customer))->assertInertia(fn (Assert $p) => $p
            ->where('plan.tax', ['region' => 'Ontario', 'summary' => 'HST 13%']));

        $as->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->put(route('billing.update'), ['enabled' => '1', 'invoice_due_days' => 15, 'tax_numbers' => "GST/HST 123456789 RT0001\n"])->assertSessionHasNoErrors();
        $this->assertSame('GST/HST 123456789 RT0001', Billing::taxNumbers());
    }
}
