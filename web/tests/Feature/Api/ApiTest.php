<?php

namespace Tests\Feature\Api;

use App\Jobs\ImportRates;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Cdr;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\Trunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function token(array $abilities = null, ?Account $account = null, array $attrs = []): string
    {
        [$token, $plain] = ApiToken::issue('test', $abilities ?? array_keys(ApiToken::ABILITIES), $this->admin, $account);
        if ($attrs) {
            $token->update($attrs);
        }

        return $plain;
    }

    private function api(string $plain): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json']);
    }

    public function test_authentication_and_abilities(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer ss_nope'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->api($this->token(attrs: ['revoked_at' => now()]))->getJson('/api/v1/me')->assertUnauthorized();
        $this->api($this->token(attrs: ['expires_at' => now()->subMinute()]))->getJson('/api/v1/me')->assertUnauthorized();

        $plain = $this->token(['cdrs:read']);
        $this->api($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.token.abilities', ['cdrs:read']);
        $this->api($plain)->getJson('/api/v1/accounts')->assertForbidden()->assertJsonPath('message', 'This token does not have the accounts:read ability.');

        $this->assertNotNull(ApiToken::query()->latest('id')->first()->last_used_at);
        $this->assertSame(0, ApiToken::query()->where('token_hash', 'like', 'ss_%')->count()); // only hashes stored
    }

    public function test_accounts_crud_with_partial_updates(): void
    {
        $plain = $this->token();
        $this->api($plain)->postJson('/api/v1/accounts', ['name' => 'Acme', 'code' => 'acme', 'is_customer' => true,
            'status' => 'active', 'currency' => 'usd', 'billing_type' => 'postpaid', 'credit_limit' => 500, 'timezone' => 'UTC'])
            ->assertCreated()->assertJsonPath('data.code', 'ACME')->assertJsonPath('data.available_funds', '500.000000');
        $id = Account::query()->where('code', 'ACME')->value('id');

        // Partial update: only the fields sent change (the customer flag survives).
        $this->api($plain)->patchJson("/api/v1/accounts/{$id}", ['credit_limit' => 750])
            ->assertOk()->assertJsonPath('data.credit_limit', '750.0000')->assertJsonPath('data.is_customer', true);
        // Customer or carrier, never both.
        $this->api($plain)->patchJson("/api/v1/accounts/{$id}", ['is_vendor' => true])->assertJsonValidationErrors('is_customer');

        $this->api($plain)->postJson('/api/v1/accounts', ['name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'status']);
        $this->api($plain)->patchJson("/api/v1/accounts/{$id}", ['is_customer' => false, 'is_vendor' => false])->assertJsonValidationErrors('is_customer');
        $this->api($plain)->getJson('/api/v1/accounts/999999')->assertNotFound();
        $this->api($plain)->getJson('/api/v1/accounts?role=customer')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

        // IPs and payments.
        $this->api($plain)->postJson("/api/v1/accounts/{$id}/ips", ['ip' => '198.51.100.0/24'])->assertCreated();
        $this->api($plain)->postJson("/api/v1/accounts/{$id}/ips", ['ip' => '198.51.100.7/24'])->assertJsonValidationErrors('ip');
        $ipId = $this->api($plain)->getJson("/api/v1/accounts/{$id}/ips")->json('data.0.id');
        $this->api($plain)->deleteJson("/api/v1/accounts/{$id}/ips/{$ipId}")->assertNoContent();

        $this->api($plain)->postJson("/api/v1/accounts/{$id}/ledger", ['type' => 'payment', 'amount' => 100, 'description' => 'Wire'])
            ->assertCreated()->assertJsonPath('balance', '100.000000');
        $this->api($plain)->postJson("/api/v1/accounts/{$id}/ledger", ['type' => 'usage', 'amount' => -1, 'description' => 'No'])
            ->assertJsonValidationErrors('type');
        $this->api($plain)->getJson("/api/v1/accounts/{$id}/ledger")->assertJsonPath('data.0.type', 'payment');
    }

    public function test_customer_tokens_only_see_their_own_data(): void
    {
        $mine = Account::factory()->create(['code' => 'MINE']);
        $other = Account::factory()->create(['code' => 'OTHER']);
        $vendor = Account::factory()->vendor()->create();
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $mine->id, 'vendor_account_id' => $vendor->id,
            'callee' => '44', 'started_at' => now()->subHour(), 'sell_cost' => '1.000000', 'buy_cost' => '0.600000']);
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $other->id, 'callee' => '44', 'started_at' => now()->subHour()]);

        $this->expectsNoInvalidAbilities();
        $plain = $this->token(ApiToken::CUSTOMER_ABILITIES, $mine);

        $this->api($plain)->getJson('/api/v1/accounts')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MINE')
            ->assertJsonMissingPath('data.0.notes');
        $this->api($plain)->getJson("/api/v1/accounts/{$other->id}")->assertNotFound();
        $this->api($plain)->getJson('/api/v1/me')->assertJsonPath('data.customer.code', 'MINE');

        $cdrs = $this->api($plain)->getJson("/api/v1/cdrs?vendor_id={$vendor->id}")->assertOk();
        $cdrs->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_id', $mine->id)->assertJsonMissingPath('data.0.buy_cost')
            ->assertJsonMissingPath('data.0.vendor_id');
        $otherCdr = Cdr::query()->where('customer_account_id', $other->id)->value('id');
        $this->api($plain)->getJson("/api/v1/cdrs/{$otherCdr}")->assertNotFound();
        $this->api($plain)->getJson('/api/v1/cdrs/summary')->assertJsonPath('data.calls', 1)->assertJsonMissingPath('data.margin');

        $this->api($plain)->getJson('/api/v1/rate-decks')->assertForbidden();
        $this->api($plain)->patchJson("/api/v1/accounts/{$mine->id}", ['credit_limit' => 1000000])->assertForbidden();
    }

    private function expectsNoInvalidAbilities(): void
    {
        try {
            ApiToken::issue('bad', ['routing:write'], account: Account::factory()->create());
            $this->fail('Customer tokens must not get admin abilities.');
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    public function test_rate_decks_and_imports(): void
    {
        Queue::fake([ImportRates::class]);
        Storage::fake('local');
        $plain = $this->token();

        $id = $this->api($plain)->postJson('/api/v1/rate-decks', ['name' => 'A-Z', 'currency' => 'USD'])->assertCreated()->json('data.id');
        Rate::factory()->for(RateDeck::query()->find($id))->create(['prefix' => '44', 'rate' => '0.01']);
        Rate::factory()->for(RateDeck::query()->find($id))->create(['prefix' => '33', 'rate' => '0.02', 'effective_from' => now()->addWeek()]);

        $this->api($plain)->getJson("/api/v1/rate-decks/{$id}/rates")->assertJsonCount(1, 'data')->assertJsonPath('data.0.prefix', '44');
        $this->api($plain)->getJson("/api/v1/rate-decks/{$id}/rates?all=1")->assertJsonCount(2, 'data');

        $import = $this->api($plain)->post("/api/v1/rate-decks/{$id}/imports", [
            'file' => UploadedFile::fake()->createWithContent('az.csv', "prefix,rate\n1,0.01\n"), 'mode' => 'update',
        ])->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(ImportRates::class);
        $this->api($plain)->getJson("/api/v1/rate-decks/{$id}/imports/{$import}")->assertOk();

        $this->api($plain)->patchJson("/api/v1/rate-decks/{$id}", ['description' => 'Wholesale'])->assertJsonPath('data.name', 'A-Z');
    }

    public function test_trunks_gateways_routing_and_route_test(): void
    {
        $plain = $this->token();
        $vendor = Account::factory()->vendor()->create();
        $buy = RateDeck::factory()->create();
        Rate::factory()->for($buy)->create(['prefix' => '44', 'rate' => '0.01']);

        $trunk = $this->api($plain)->postJson('/api/v1/trunks', ['vendor_id' => $vendor->id, 'name' => 'Main', 'rate_deck_id' => $buy->id])
            ->assertCreated()->assertJsonPath('data.enabled', true)->json('data.id');
        $gw = $this->api($plain)->postJson("/api/v1/trunks/{$trunk}/gateways", ['name' => 'main-gw', 'host' => '203.0.113.10', 'password' => 'pw'])
            ->assertCreated()->assertJsonPath('data.has_password', true)->assertJsonMissingPath('data.password')->json('data.id');

        // Partial update keeps the password; null removes it.
        $this->api($plain)->patchJson("/api/v1/trunks/{$trunk}/gateways/{$gw}", ['port' => 5070])->assertJsonPath('data.port', 5070)->assertJsonPath('data.enabled', true);
        $this->assertSame('pw', Gateway::query()->find($gw)->password);
        $this->api($plain)->patchJson("/api/v1/trunks/{$trunk}/gateways/{$gw}", ['password' => null])->assertJsonPath('data.has_password', false);
        $this->api($plain)->patchJson("/api/v1/trunks/{$trunk}", ['max_channels' => 30])->assertJsonPath('data.max_channels', 30)->assertJsonPath('data.enabled', true);

        $plan = $this->api($plain)->postJson('/api/v1/routing-plans', ['name' => 'Std'])->assertCreated()->assertJsonPath('data.mode', 'lcr')->json('data.id');
        $route = $this->api($plain)->postJson("/api/v1/routing-plans/{$plan}/routes", ['prefix' => '44', 'trunk_id' => $trunk])->assertCreated()->json('data.id');
        $this->api($plain)->postJson("/api/v1/routing-plans/{$plan}/routes", ['prefix' => '44', 'trunk_id' => $trunk])->assertJsonValidationErrors('trunk_id');
        $this->api($plain)->getJson("/api/v1/routing-plans/{$plan}")->assertJsonCount(1, 'data.routes');

        $sell = RateDeck::factory()->create();
        Rate::factory()->for($sell)->create(['prefix' => '44', 'rate' => '0.05']);
        $customer = Account::factory()->create(['sell_rate_deck_id' => $sell->id, 'routing_plan_id' => $plan, 'credit_limit' => 10]);
        $this->api($plain)->postJson('/api/v1/route-test', ['customer_id' => $customer->id, 'number' => '442079460000'])
            ->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.attempts.0.gateways.0.name', 'main-gw');

        $this->api($plain)->patchJson("/api/v1/routing-plans/{$plan}/routes/{$route}", ['enabled' => false])->assertJsonPath('data.enabled', false);
        $this->api($plain)->deleteJson("/api/v1/routing-plans/{$plan}/routes/{$route}")->assertNoContent();
        $this->api($plain)->deleteJson("/api/v1/trunks/{$trunk}/gateways/{$gw}")->assertNoContent();

        $other = Trunk::factory()->create();
        $gw2 = Gateway::factory()->for($other)->create();
        $this->api($plain)->deleteJson("/api/v1/trunks/{$trunk}/gateways/{$gw2->id}")->assertNotFound(); // scoped
    }

    public function test_cdrs_paginate_by_cursor_and_invoices(): void
    {
        $plain = $this->token();
        $customer = Account::factory()->create();
        foreach (range(1, 3) as $i) {
            $cdr = Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $customer->id, 'callee' => "44{$i}", 'started_at' => now()->subMinutes($i)]);
        }
        $cdr->attemptLog()->create(['attempt' => 1, 'started_at' => now(), 'sip_code' => 503]);

        $first = $this->api($plain)->getJson('/api/v1/cdrs?per_page=2')->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $this->api($plain)->getJson('/api/v1/cdrs?per_page=2&cursor='.urlencode($cursor))->assertJsonCount(1, 'data');
        $this->api($plain)->getJson("/api/v1/cdrs/{$cdr->id}")->assertJsonPath('data.attempt_log.0.sip_code', 503);

        $invoice = Invoice::create(['number' => 'INV-API-1', 'account_id' => $customer->id, 'status' => 'issued', 'period_start' => now()->subMonth(),
            'period_end' => now(), 'timezone' => 'UTC', 'currency' => 'USD', 'account_name' => 'X', 'account_code' => 'X', 'billing_type' => 'prepaid',
            'opening_balance' => 0, 'usage_total' => '1.5', 'payments_total' => 0, 'adjustments_total' => 0, 'closing_balance' => '-1.5', 'amount_due' => 0, 'issued_at' => now()]);
        $invoice->lines()->create(['position' => 1, 'prefix' => '44', 'calls' => 1, 'billsec' => 60, 'amount' => '1.5']);
        $this->api($plain)->getJson('/api/v1/invoices')->assertJsonPath('data.0.number', 'INV-API-1');
        $this->api($plain)->getJson("/api/v1/invoices/{$invoice->id}")->assertJsonPath('data.lines.0.amount', '1.500000');
    }

    public function test_every_api_route_is_documented(): void
    {
        $spec = json_decode(file_get_contents(resource_path('api/openapi.json')), true);
        $this->assertSame('3.1.0', $spec['openapi']);

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/openapi.json') {
                continue;
            }
            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (! isset($spec['paths'][$path][strtolower($method)])) {
                    $missing[] = "{$method} {$path}";
                }
            }
        }
        $this->assertSame([], $missing, 'Undocumented API routes: '.implode(', ', $missing));

        $this->get('/api/v1/openapi.json')->assertOk()->assertJsonPath('info.title', 'Softswitch API');
    }

    public function test_tokens_are_managed_in_settings(): void
    {
        $customer = Account::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($this->admin)->post(route('api-tokens.store'), ['name' => 'Portal', 'abilities' => ['cdrs:read']])
            ->assertRedirect(route('password.confirm'));

        $response = $this->actingAs($this->admin)->withSession($confirmed)
            ->post(route('api-tokens.store'), ['name' => 'Portal', 'abilities' => ['cdrs:read', 'billing:read'], 'account_id' => $customer->id, 'expires_in_days' => 30]);
        $response->assertRedirect(route('api-tokens.index'));
        $token = ApiToken::query()->sole();
        $this->assertSame($customer->id, $token->account_id);
        $this->assertTrue($token->expires_at->isFuture());

        $this->actingAs($this->admin)->withSession($confirmed)
            ->post(route('api-tokens.store'), ['name' => 'Bad', 'abilities' => ['routing:write'], 'account_id' => $customer->id])
            ->assertSessionHasErrors('abilities');

        $this->actingAs($this->admin)->withSession($confirmed)->delete(route('api-tokens.destroy', $token));
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertFalse($token->fresh()->usable());
    }
}
