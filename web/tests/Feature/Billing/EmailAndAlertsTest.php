<?php

namespace Tests\Feature\Billing;

use App\Jobs\EmailInvoice;
use App\Jobs\SendTestEmail;
use App\Mail\InvoiceMail;
use App\Mail\LowBalanceMail;
use App\Mail\TestMail;
use App\Models\Account;
use App\Models\Cdr;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\Ledger;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function configureMail(array $extra = []): void
    {
        Settings::put(['mail.host' => 'smtp.example.com', 'mail.port' => 587, 'mail.security' => 'starttls',
            'mail.username' => 'billing', 'mail.password' => 's3cret', 'mail.from_address' => 'billing@switch.example',
            'mail.admin_address' => 'noc@switch.example', ...$extra]);
    }

    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_smtp_settings_are_saved_with_an_encrypted_write_only_password(): void
    {
        $form = ['host' => 'SMTP.Office365.com', 'port' => 587, 'security' => 'starttls', 'username' => 'billing@key2.example',
            'password' => 'hunter2', 'from_address' => 'billing@key2.example', 'from_name' => 'Key2 Billing'];

        // Requires a recent password confirmation.
        $this->actingAs($this->admin)->put(route('email.update'), $form)->assertRedirect(route('password.confirm'));

        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('email.update'), $form)->assertSessionHasNoErrors();
        $this->assertSame('smtp.office365.com', Settings::get('mail.host'));
        $this->assertSame('hunter2', Settings::get('mail.password'));
        $this->assertStringNotContainsString('hunter2', (string) DB::table('app_settings')->where('key', 'mail.password')->value('value'));

        // The page never sends the password back; blank keeps it.
        $this->actingAs($this->admin)->get(route('email.edit'))->assertInertia(fn (Assert $p) => $p
            ->component('settings/Email')->where('mail.has_password', true)->missing('mail.password')->where('configured', true));
        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('email.update'), [...$form, 'password' => '']);
        $this->assertSame('hunter2', Settings::get('mail.password'));
        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('email.update'), [...$form, 'password' => '', 'clear_password' => '1']);
        $this->assertNull(Settings::get('mail.password'));

        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('email.update'), [...$form, 'host' => 'smtp; rm -rf'])
            ->assertSessionHasErrors('host');
    }

    public function test_settings_drive_the_mailer(): void
    {
        $this->configureMail(['mail.security' => 'tls', 'mail.port' => 465]);
        \App\Services\MailSettings::fresh();

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('s3cret', config('mail.mailers.smtp.password'));
        $this->assertSame('billing@switch.example', config('mail.from.address'));
    }

    public function test_test_email_is_queued_and_reports_its_result(): void
    {
        Queue::fake([SendTestEmail::class]);
        $this->configureMail();
        $this->actingAs($this->admin)->post(route('email.test'), ['to' => 'me@example.com'])->assertRedirect();
        Queue::assertPushed(SendTestEmail::class, fn ($job) => $job->to === 'me@example.com');
        $this->assertSame('queued', Cache::get(SendTestEmail::RESULT)['state']);

        Mail::fake();
        (new SendTestEmail('me@example.com'))->handle();
        Mail::assertSent(TestMail::class, fn ($m) => $m->hasTo('me@example.com'));
        $this->assertSame('sent', Cache::get(SendTestEmail::RESULT)['state']);
    }

    public function test_low_balance_alerts_fire_once_per_dip(): void
    {
        Mail::fake();
        $this->configureMail();
        $customer = Account::factory()->create(['code' => 'LOW', 'billing_email' => 'ap@low.example', 'low_balance_threshold' => '10']);
        $quiet = Account::factory()->create(['code' => 'OK', 'low_balance_threshold' => '10']);
        app(Ledger::class)->payment($quiet, '50', 'Funded');
        app(Ledger::class)->payment($customer, '12', 'Funded');

        $this->artisan('softswitch:check-balances')->assertSuccessful();
        Mail::assertNothingSent();

        // Unposted usage and calls in progress count, as in routing.
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $customer->id, 'callee' => '44', 'started_at' => now(), 'sell_cost' => '1.5']);
        DB::table('active_calls')->insert(['uuid' => (string) Str::uuid(), 'account_id' => $customer->id, 'reserved' => '1', 'max_duration' => 60]);
        $this->assertSame('9.500000', $customer->availableFundsNow());

        $this->artisan('softswitch:check-balances')->assertSuccessful();
        $this->artisan('softswitch:check-balances')->assertSuccessful();
        Mail::assertSent(LowBalanceMail::class, 1);
        Mail::assertSent(LowBalanceMail::class, fn ($m) => $m->hasTo('ap@low.example') && $m->hasBcc('noc@switch.example') && $m->available === '9.500000');
        $this->assertNotNull($customer->fresh()->low_balance_alerted_at);

        // Dashboard lists them.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
            ->component('Dashboard')->has('lowBalance', 1)->where('lowBalance.0.code', 'LOW'));

        // Recover above the threshold: the alert re-arms.
        app(Ledger::class)->payment($customer, '20', 'Top-up');
        $this->artisan('softswitch:check-balances');
        $this->assertNull($customer->fresh()->low_balance_alerted_at);
    }

    public function test_alerts_are_recorded_even_without_email(): void
    {
        Mail::fake();
        $customer = Account::factory()->create(['low_balance_threshold' => '10']);

        $this->artisan('softswitch:check-balances')->expectsOutputToContain('email not configured');
        $this->assertNotNull($customer->fresh()->low_balance_alerted_at);
        Mail::assertNothingSent();
    }

    public function test_invoices_can_be_emailed_with_the_csv_attached(): void
    {
        Mail::fake();
        $this->configureMail(['mail.auto_invoices' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'UTC'));
        $customer = Account::factory()->create(['code' => 'INV', 'timezone' => 'UTC', 'billing_email' => 'ap@inv.example']);
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $customer->id, 'callee' => '44', 'started_at' => '2026-10-10 10:00:00+00',
            'answered_at' => '2026-10-10 10:00:00+00', 'billsec' => 60, 'attempts' => 1, 'sell_prefix' => '44', 'sell_destination' => 'UK', 'sell_rate' => '0.05', 'sell_cost' => '0.05']);

        [$start, $end] = InvoiceGenerator::monthPeriod($customer, CarbonImmutable::parse('2026-10-15'));
        $invoice = app(InvoiceGenerator::class)->generate($customer, $start, $end); // auto-email is on

        Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo('ap@inv.example') && $m->hasBcc('noc@switch.example'));

        // The destination breakdown is attached as CSV.
        $attachment = (new InvoiceMail($invoice->fresh()))->attachments()[0];
        $this->assertSame("{$invoice->number}.csv", $attachment->as);
        $csv = '';
        $attachment->attachWith(fn () => null, function ($data) use (&$csv) { $csv = $data(); });
        $this->assertStringContainsString('44,UK,1,1,0.050000,0.050000,USD', $csv);
        $this->assertSame('ap@inv.example', $invoice->fresh()->emailed_to);

        $rendered = (new InvoiceMail($invoice->fresh()))->render();
        $this->assertStringContainsString($invoice->number, $rendered);
        $this->assertStringContainsString('October 2026', $rendered);

        // Resend to another address from the invoice page.
        Queue::fake([EmailInvoice::class]);
        $this->actingAs($this->admin)->post(route('invoices.email', $invoice), ['to' => 'cfo@inv.example'])->assertSessionHasNoErrors();
        Queue::assertPushed(EmailInvoice::class, fn ($job) => $job->to === 'cfo@inv.example');
    }

    public function test_invoice_email_requires_mail_settings(): void
    {
        $customer = Account::factory()->create(['billing_email' => 'ap@x.example']);
        $invoice = Invoice::create([
            'number' => 'INV-T-1', 'account_id' => $customer->id, 'status' => 'issued', 'period_start' => now()->subMonth(), 'period_end' => now(),
            'timezone' => 'UTC', 'currency' => 'USD', 'account_name' => 'X', 'account_code' => 'X', 'billing_type' => 'prepaid',
            'opening_balance' => 0, 'usage_total' => 0, 'payments_total' => 0, 'adjustments_total' => 0, 'closing_balance' => 0, 'amount_due' => 0, 'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('invoices.email', $invoice))->assertSessionHasErrors('to');
    }
}
