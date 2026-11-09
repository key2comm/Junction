<?php

namespace Tests\Feature\Portal;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Cdr;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private Account $customer;

    private Account $other;

    private User $portalUser;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $deck = RateDeck::factory()->create();
        Rate::factory()->for($deck)->create(['prefix' => '44', 'destination' => 'UK', 'rate' => '0.05']);
        Rate::factory()->for($deck)->create(['prefix' => '33', 'destination' => 'France', 'rate' => '0.04', 'effective_from' => now()->addWeek()]);
        $this->customer = Account::factory()->create(['code' => 'MINE', 'sell_rate_deck_id' => $deck->id]);
        $this->other = Account::factory()->create(['code' => 'OTHER']);
        $this->portalUser = User::factory()->create(['account_id' => $this->customer->id]);
        $this->admin = User::factory()->create();
    }

    private function cdr(Account $account, array $attrs = []): Cdr
    {
        return Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $account->id, 'callee' => '442079460000',
            'destination' => '442079460000', 'started_at' => now()->subHour(), 'answered_at' => now()->subHour(), 'billsec' => 60, 'attempts' => 1,
            'sell_prefix' => '44', 'sell_destination' => 'UK', 'sell_rate' => '0.05', 'sell_cost' => '0.050000', 'buy_cost' => '0.020000', ...$attrs]);
    }

    private function invoice(Account $account, string $status = 'issued'): Invoice
    {
        return Invoice::create(['number' => 'INV-P-'.Str::random(4), 'account_id' => $account->id, 'status' => $status, 'period_start' => now()->subMonth(),
            'period_end' => now()->subDay(), 'timezone' => 'UTC', 'currency' => 'USD', 'account_name' => $account->name, 'account_code' => $account->code,
            'billing_type' => 'prepaid', 'opening_balance' => 0, 'usage_total' => 1, 'payments_total' => 0, 'adjustments_total' => 0,
            'closing_balance' => -1, 'amount_due' => 0, 'issued_at' => now()]);
    }

    public function test_customers_are_kept_out_of_the_admin_area(): void
    {
        $as = $this->actingAs($this->portalUser);

        foreach (['/dashboard', '/accounts', "/accounts/{$this->customer->id}", '/cdrs', '/invoices', '/rate-decks', '/carriers', '/routing-plans',
            '/settings/email', '/settings/api-tokens', '/settings/certificate'] as $url) {
            $as->get($url)->assertRedirect(route('portal.home'));
        }
        $as->post('/accounts', ['name' => 'x'])->assertForbidden();
        $as->post("/accounts/{$this->customer->id}/ledger", ['type' => 'payment', 'amount' => 1000, 'description' => 'free money'])->assertForbidden();
        $this->assertSame('0.000000', $this->customer->fresh()->balance);

        // Their own profile settings still work.
        $as->get('/settings/profile')->assertOk();
    }

    public function test_admins_are_sent_from_the_portal_to_the_dashboard(): void
    {
        $this->actingAs($this->admin)->get('/portal')->assertRedirect(route('dashboard'));
    }

    public function test_overview_and_calls_only_show_their_own_data_without_costs(): void
    {
        $this->cdr($this->customer);
        $this->cdr($this->other);

        $this->actingAs($this->portalUser)->get('/portal')->assertInertia(fn (Assert $p) => $p
            ->component('portal/Overview')->where('account.code', 'MINE')->where('last24h.calls', 1)->missing('last24h.margin'));

        $this->actingAs($this->portalUser)->get("/portal/calls?customer={$this->other->id}&vendor=1")->assertInertia(fn (Assert $p) => $p
            ->component('portal/Calls')->has('calls.data', 1)->where('summary.calls', 1)->missing('summary.buy')
            ->missing('calls.data.0.buy_cost')->missing('calls.data.0.vendor_account_id'));

        $csv = $this->actingAs($this->portalUser)->get('/portal/calls/export')->streamedContent();
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(2, $lines);
        $this->assertStringNotContainsString('buy', $lines[0]);
        $this->assertStringNotContainsString('vendor', $lines[0]);
    }

    public function test_invoices_are_limited_to_their_own_issued_ones(): void
    {
        $mine = $this->invoice($this->customer);
        $void = $this->invoice($this->customer, 'void');
        $theirs = $this->invoice($this->other);

        $this->actingAs($this->portalUser)->get('/portal/invoices')->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1));
        $this->actingAs($this->portalUser)->get("/portal/invoices/{$mine->id}")->assertOk();
        $this->actingAs($this->portalUser)->get("/portal/invoices/{$mine->id}/csv")->assertOk();
        $this->actingAs($this->portalUser)->get("/portal/invoices/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->portalUser)->get("/portal/invoices/{$theirs->id}/csv")->assertNotFound();
        $this->actingAs($this->portalUser)->get("/portal/invoices/{$void->id}")->assertNotFound();
    }

    public function test_rates_show_current_prices_and_the_csv_includes_scheduled_ones(): void
    {
        $this->actingAs($this->portalUser)->get('/portal/rates')->assertInertia(fn (Assert $p) => $p
            ->has('rates.data', 1)->where('rates.data.0.prefix', '44')->where('scheduled', 1));

        $csv = $this->actingAs($this->portalUser)->get('/portal/rates/csv')->streamedContent();
        $this->assertStringContainsString('44,UK,0.050000', $csv);
        $this->assertStringContainsString('33,France,0.040000', $csv);

        $noDeck = User::factory()->create(['account_id' => $this->other->id]);
        $this->actingAs($noDeck)->get('/portal/rates')->assertInertia(fn (Assert $p) => $p->where('rates', null));
    }

    public function test_customers_manage_their_own_scoped_api_tokens(): void
    {
        $confirmed = ['auth.password_confirmed_at' => time()];
        $this->actingAs($this->portalUser)->withSession($confirmed)
            ->post('/portal/api-tokens', ['name' => 'Billing', 'abilities' => ['cdrs:read', 'billing:read']])->assertRedirect();
        $this->actingAs($this->portalUser)->withSession($confirmed)
            ->post('/portal/api-tokens', ['name' => 'Sneaky', 'abilities' => ['routing:write']])->assertSessionHasErrors('abilities.0');

        $token = ApiToken::query()->sole();
        $this->assertSame($this->customer->id, $token->account_id);

        [$othersToken] = ApiToken::issue('theirs', ['cdrs:read'], account: $this->other);
        $this->actingAs($this->portalUser)->get('/portal/api-tokens')->assertInertia(fn (Assert $p) => $p->has('tokens', 1));
        $this->actingAs($this->portalUser)->withSession($confirmed)->delete("/portal/api-tokens/{$othersToken->id}")->assertNotFound();
        $this->actingAs($this->portalUser)->withSession($confirmed)->delete("/portal/api-tokens/{$token->id}");
        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_admins_create_portal_logins(): void
    {
        Notification::fake();

        // Without email: a one-time password is shown to the admin.
        $this->actingAs($this->admin)->post(route('accounts.portal-users.store', $this->customer), ['name' => 'Ann', 'email' => 'ANN@mine.example'])
            ->assertRedirect();
        $ann = User::query()->where('email', 'ann@mine.example')->sole();
        $this->assertSame($this->customer->id, $ann->account_id);
        Notification::assertNothingSent();

        // With email: they get a link to set their own password.
        Settings::put(['mail.host' => 'smtp.example.com', 'mail.from_address' => 'billing@switch.example']);
        $this->actingAs($this->admin)->post(route('accounts.portal-users.store', $this->customer), ['name' => 'Bob', 'email' => 'bob@mine.example']);
        Notification::assertSentTo(User::query()->where('email', 'bob@mine.example')->sole(), ResetPassword::class);

        $this->actingAs($this->admin)->post(route('accounts.portal-users.store', $this->customer), ['name' => 'Dup', 'email' => 'ann@mine.example'])
            ->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->get(route('accounts.show', $this->customer))->assertInertia(fn (Assert $p) => $p->has('portalUsers', 3));

        // Removal only through the user's own account.
        $this->actingAs($this->admin)->delete(route('accounts.portal-users.destroy', [$this->other, $ann]))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('accounts.portal-users.destroy', [$this->customer, $ann]))->assertRedirect();
        $this->assertModelMissing($ann);
    }

    public function test_deleting_an_account_removes_its_logins_rather_than_making_them_admins(): void
    {
        $this->customer->delete();

        $this->assertModelMissing($this->portalUser);
    }
}
