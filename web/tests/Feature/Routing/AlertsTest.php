<?php

namespace Tests\Feature\Routing;

use App\Mail\AlertMail;
use App\Models\Account;
use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\Cdr;
use App\Models\Gateway;
use App\Models\Trunk;
use App\Models\User;
use App\Services\AlertEngine;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private Account $customer;

    private Trunk $trunk;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->customer = Account::factory()->create(['code' => 'CUST']);
        $vendor = Account::factory()->vendor()->create(['code' => 'VEND']);
        $this->trunk = Trunk::factory()->for($vendor, 'account')->create(['name' => 'Main']);
        $this->gateway = Gateway::factory()->for($this->trunk)->create(['name' => 'main-gw']);
    }

    private function cdr(array $attrs = []): Cdr
    {
        return Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $this->customer->id, 'callee' => '44',
            'destination' => '442079460000', 'started_at' => now()->subMinutes(5), ...$attrs]);
    }

    private function attempts(int $n, array $attrs = []): void
    {
        foreach (range(1, $n) as $i) {
            $this->cdr()->attemptLog()->create(['attempt' => 1, 'trunk_id' => $this->trunk->id, 'gateway_id' => $this->gateway->id,
                'started_at' => now()->subMinutes(5)->addSeconds($i), ...$attrs]);
        }
    }

    private function run_(): array
    {
        return app(AlertEngine::class)->run();
    }

    private function rule(string $type, array $set): void
    {
        DB::table('alert_rules')->where('type', $type)->update($set);
    }

    public function test_spend_spike_compares_with_the_customers_usual_spend(): void
    {
        // Usual: 1.68/day for a week = 0.01/hour.
        foreach (range(1, 7) as $d) {
            $this->cdr(['started_at' => now()->subDays($d), 'sell_cost' => '1.68']);
        }
        $this->cdr(['sell_cost' => '49']);
        $this->run_();
        $this->assertSame(0, Alert::query()->count()); // under the 50 minimum

        $this->cdr(['sell_cost' => '2']);
        $this->run_();
        $alert = Alert::query()->sole();
        $this->assertSame('spend_spike', $alert->type);
        $this->assertSame($this->customer->id, $alert->account_id);
        $this->assertEquals(51, $alert->details['last_hour']);
    }

    public function test_fraud_patterns(): void
    {
        // High-cost burst: 20 calls at 1.50/min in 15 minutes.
        foreach (range(1, 20) as $i) {
            $this->cdr(['sell_rate' => '1.50', 'destination' => '882'.$i]);
        }
        // Short-call pattern: 30 answered 5-second calls to one number.
        foreach (range(1, 30) as $i) {
            $this->cdr(['answered_at' => now()->subMinutes(5), 'billsec' => 5, 'destination' => '37491000001']);
        }
        $this->run_();

        $this->assertEqualsCanonicalizing(['high_cost_burst', 'short_call_pattern'], Alert::query()->pluck('type')->all());
        $this->assertSame('37491000001', Alert::query()->where('type', 'short_call_pattern')->value('details')['destination']);
    }

    public function test_new_destinations_need_history(): void
    {
        foreach (range(10, 21) as $p) {
            $this->cdr(['sell_prefix' => (string) $p]);
        }
        $this->run_();
        $this->assertSame(0, Alert::query()->where('type', 'new_destinations')->count()); // brand-new customer: no baseline

        $this->cdr(['sell_prefix' => '44', 'started_at' => now()->subDays(3)]);
        $this->run_();
        $alert = Alert::query()->where('type', 'new_destinations')->sole();
        $this->assertSame(12, $alert->details['count']);
    }

    public function test_alerts_are_announced_once_and_can_suspend_the_customer(): void
    {
        Settings::put(['mail.host' => 'smtp.example.com', 'mail.from_address' => 'alerts@switch.example', 'mail.admin_address' => 'noc@switch.example']);
        $this->rule('spend_spike', ['auto_suspend' => true]);
        $this->cdr(['sell_cost' => '100']);

        $this->run_();
        $this->run_();

        $alert = Alert::query()->sole();
        $this->assertSame('Customer suspended automatically', $alert->action_taken);
        $this->assertSame('suspended', $this->customer->fresh()->status->value);
        Mail::assertSent(AlertMail::class, 1);
        Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('noc@switch.example'));

        // Suspended customers are refused by the routing engine.
        $route = DB::selectOne('SELECT reason FROM softswitch_route(?, ?)', [$this->customer->id, '44']);
        $this->assertSame('account_inactive', $route->reason);
    }

    public function test_vendor_alerts_open_and_resolve_themselves(): void
    {
        $this->attempts(20, ['sip_code' => 503, 'hangup_cause' => 'NORMAL_TEMPORARY_FAILURE']);
        $this->rule('vendor_asr_low', ['params' => json_encode(['asr' => 15, 'min_attempts' => 20])]);
        $this->run_();
        $this->assertEqualsCanonicalizing(['gateway_failing', 'vendor_asr_low'], Alert::query()->pluck('type')->all());

        // The gateway recovers.
        $this->attempts(30, ['answered_at' => now(), 'sip_code' => 200]);
        $r = $this->run_();
        $this->assertSame(2, $r['resolved']);
        $this->assertSame(0, Alert::query()->where('status', 'open')->count());
    }

    public function test_fraud_alerts_stay_until_handled_and_can_recur(): void
    {
        $this->cdr(['sell_cost' => '100']);
        $this->run_();
        DB::table('cdrs')->delete();
        $this->run_();
        $alert = Alert::query()->sole();
        $this->assertSame('open', $alert->status); // condition cleared, but someone should look

        $admin = User::factory()->create();
        $this->actingAs($admin)->post(route('alerts.acknowledge', $alert));
        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->actingAs($admin)->post(route('alerts.resolve', $alert));
        $this->assertSame('resolved', $alert->fresh()->status);

        $this->cdr(['sell_cost' => '100']);
        $this->run_();
        $this->assertSame(2, Alert::query()->count()); // a new occurrence opens a new alert
    }

    public function test_self_test_accounts_are_ignored(): void
    {
        $self = Account::factory()->create(['code' => 'SELFTEST-C']);
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $self->id, 'callee' => '44', 'started_at' => now(), 'sell_cost' => '500']);

        $this->run_();
        $this->assertSame(0, Alert::query()->count());
    }

    public function test_rules_page_and_api(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->put(route('alerts.rules.update', 'spend_spike'), ['enabled' => '1', 'auto_suspend' => '1', 'params' => ['multiplier' => '3', 'min_amount' => '25']])
            ->assertSessionHasNoErrors();
        $rule = DB::table('alert_rules')->where('type', 'spend_spike')->first();
        $this->assertTrue((bool) $rule->auto_suspend);
        $this->assertEquals(['multiplier' => 3, 'min_amount' => 25], json_decode($rule->params, true));

        // Operational rules can't suspend; unknown rules 404; bad numbers rejected.
        $this->actingAs($admin)->put(route('alerts.rules.update', 'gateway_failing'), ['enabled' => '1', 'auto_suspend' => '1', 'params' => ['attempts' => '10']]);
        $this->assertFalse((bool) DB::table('alert_rules')->where('type', 'gateway_failing')->value('auto_suspend'));
        $this->actingAs($admin)->put(route('alerts.rules.update', 'nope'), [])->assertNotFound();
        $this->actingAs($admin)->put(route('alerts.rules.update', 'spend_spike'), ['params' => ['multiplier' => 'x', 'min_amount' => '1']])->assertSessionHasErrors('params.multiplier');

        $this->cdr(['sell_cost' => '100']);
        $this->run_();
        $this->actingAs($admin)->get(route('alerts.index'))->assertInertia(fn (Assert $p) => $p->component('alerts/Index')->has('alerts', 1)->has('rules', 7)->where('openAlerts', 1));

        [, $plain] = ApiToken::issue('a', ['alerts:read']);
        $this->withHeaders(['Authorization' => "Bearer {$plain}"])->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('data.0.type', 'spend_spike');
    }
}
