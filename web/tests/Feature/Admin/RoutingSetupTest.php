<?php

namespace Tests\Feature\Admin;

use App\Jobs\ImportRates;
use App\Models\Account;
use App\Models\Gateway;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Rate decks, trunks/gateways and routing plans admin pages.
 */
class RoutingSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_pages_require_login(): void
    {
        foreach (['tariffs.index', 'carrier-rates.index', 'trunks.index', 'routing-plans.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_index_and_form_pages_render(): void
    {
        $deck = RateDeck::factory()->create();
        $trunk = Trunk::factory()->create();
        $plan = RoutingPlan::factory()->create();

        $pages = [
            [route('tariffs.index'), 'rate-decks/Index'], [route('carrier-rates.index'), 'rate-decks/Index'], [route('rate-decks.create'), 'rate-decks/Form'],
            [route('rate-decks.show', $deck), 'rate-decks/Show'], [route('rate-decks.edit', $deck), 'rate-decks/Form'],
            [route('routing-plans.index'), 'routing-plans/Index'], [route('routing-plans.create'), 'routing-plans/Form'],
            [route('routing-plans.show', $plan), 'routing-plans/Show'], [route('routing-plans.edit', $plan), 'routing-plans/Form'],
        ];
        foreach ($pages as [$url, $component]) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
        }
        // Trunks are part of the carrier now: old links open the carrier.
        $this->actingAs($this->admin)->get(route('trunks.index'))->assertRedirect(route('carriers.index'));
        $this->actingAs($this->admin)->get(route('trunks.show', $trunk))->assertRedirect(route('accounts.show', $trunk->account_id));
    }

    public function test_rate_decks_can_be_created_and_files_queued_for_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->admin)->post(route('rate-decks.store'), ['name' => 'A-Z Wholesale', 'kind' => 'carrier', 'currency' => 'cad'])->assertSessionHasNoErrors();
        $deck = RateDeck::query()->sole();
        $this->assertSame('CAD', $deck->currency);
        $this->assertSame('carrier', $deck->kind->value);

        // Step 1: upload. The file is read and waits for its columns to be matched.
        $file = UploadedFile::fake()->createWithContent('az.csv', "Prefix,NPA,NXX,Interval,Rate,date_effective\n1,201,555,6/6,0.01,2026-12-01\n");
        $this->actingAs($this->admin)->post(route('rate-decks.imports.store', $deck), ['file' => $file])->assertSessionHasNoErrors();
        $import = RateImport::query()->sole();
        $this->assertSame('mapping', $import->status);
        Storage::disk('local')->assertExists($import->path);
        Queue::assertNothingPushed();
        // (jsonb doesn't keep key order, so compare without it.)
        $this->assertEquals(['prefix' => 0, 'npa' => 1, 'nxx' => 2, 'interval' => 3, 'increment' => null, 'rate' => 4, 'effective_from' => 5, 'destination' => null, 'connect_fee' => null],
            $import->preview['suggested']);
        $this->actingAs($this->admin)->get(route('rate-decks.show', $deck))->assertInertia(fn (Assert $p) => $p
            ->where('mapping.id', $import->id)->where('mapping.preview.header', ['Prefix', 'NPA', 'NXX', 'Interval', 'Rate', 'date_effective'])
            ->where('mapping.preview.rows.0', ['1', '201', '555', '6/6', '0.01', '2026-12-01'])->has('imports', 0));

        // Step 2: the admin's column choices; a rate column and a prefix (or NPA) are required.
        $columns = ['prefix' => 0, 'npa' => 1, 'nxx' => 2, 'interval' => 3, 'rate' => 4, 'effective_from' => 5];
        $start = fn (array $form) => $this->actingAs($this->admin)->post(route('rate-decks.imports.start', [$deck, $import]), $form);
        $start(['columns' => [...$columns, 'rate' => null], 'mode' => 'update', 'has_header' => true])->assertSessionHasErrors('columns.rate');
        $start(['columns' => [...$columns, 'prefix' => null, 'npa' => null], 'mode' => 'update', 'has_header' => true])->assertSessionHasErrors('columns.prefix');
        $start(['columns' => [...$columns, 'nxx' => 1], 'mode' => 'update', 'has_header' => true])->assertSessionHasErrors('columns');
        $start(['columns' => [...$columns, 'rate' => 9], 'mode' => 'update', 'has_header' => true])->assertSessionHasErrors('columns.rate');
        $start(['columns' => $columns, 'mode' => 'replace', 'has_header' => true, 'effective_from' => '2026-12-01T00:00'])->assertSessionHasNoErrors();

        $import->refresh();
        $this->assertSame('queued', $import->status);
        $this->assertSame('replace', $import->mode);
        $this->assertSame('2026-12-01 00:00:00', $import->effective_from->utc()->format('Y-m-d H:i:s'));
        $this->assertEquals(['has_header' => true, 'columns' => [...$columns, 'increment' => null, 'destination' => null, 'connect_fee' => null], 'npa_country' => '1'], $import->mapping);
        Queue::assertPushed(ImportRates::class);

        // Only one import per deck at a time.
        $this->actingAs($this->admin)->post(route('rate-decks.imports.store', $deck), ['file' => UploadedFile::fake()->createWithContent('b.csv', "prefix,rate\n1,0.02\n")]);
        $waiting = RateImport::query()->where('status', 'mapping')->sole();
        $start(['columns' => ['prefix' => 0, 'rate' => 1], 'mode' => 'update', 'has_header' => true]); // the earlier $import is no longer waiting
        $this->actingAs($this->admin)->post(route('rate-decks.imports.start', [$deck, $waiting]), ['columns' => ['prefix' => 0, 'rate' => 1], 'mode' => 'update', 'has_header' => true])
            ->assertSessionHasErrors('columns');

        // A waiting upload can be cancelled (its file is removed).
        $this->actingAs($this->admin)->delete(route('rate-decks.imports.cancel', [$deck, $waiting]))->assertRedirect(route('rate-decks.show', $deck));
        $this->assertNull($waiting->fresh());
        Storage::disk('local')->assertMissing($waiting->path);
    }

    public function test_only_running_or_failed_imports_are_listed(): void
    {
        $deck = RateDeck::factory()->create();
        $make = fn (string $status) => RateImport::create(['rate_deck_id' => $deck->id, 'filename' => "{$status}.csv", 'path' => 'x', 'mode' => 'update',
            'effective_from' => now(), 'status' => $status, 'stats' => ['added' => 1, 'changed' => 0, 'unchanged' => 0, 'ended' => 0]]);
        $page = fn () => $this->actingAs($this->admin)->get(route('rate-decks.show', $deck));

        $make('failed');
        $make('done');
        $page()->assertInertia(fn (Assert $p) => $p->has('imports', 0)->where('importing', false)->where('last_finished.status', 'done'));

        $running = $make('running');
        $page()->assertInertia(fn (Assert $p) => $p->has('imports', 1)->where('imports.0.id', $running->id)->where('importing', true)->where('last_finished', null));

        $running->update(['status' => 'failed', 'errors' => ['Line 2: bad']]);
        $page()->assertInertia(fn (Assert $p) => $p->has('imports', 1)->where('imports.0.errors', ['Line 2: bad'])->where('last_finished.status', 'failed'));
    }

    public function test_a_rate_can_be_added_by_hand(): void
    {
        $deck = RateDeck::factory()->create();
        $url = route('rate-decks.rates.store', $deck);
        $base = ['prefix' => '1201555', 'destination' => 'USA - NJ', 'rate' => '0.0053', 'initial_increment' => 6, 'increment' => 6];

        $this->actingAs($this->admin)->post($url, [...$base, 'prefix' => '1-201'])->assertSessionHasErrors('prefix');
        $this->actingAs($this->admin)->post($url, [...$base, 'increment' => 0])->assertSessionHasErrors('increment');

        // A new prefix.
        $this->actingAs($this->admin)->post($url, $base)->assertSessionHasNoErrors();
        $r = $deck->rates()->sole();
        $this->assertSame(['1201555', 'USA - NJ', '0.005300', 6, 6, null], [(string) $r->prefix, $r->destination, $r->rate, $r->initial_increment, $r->increment, $r->effective_to]);

        // A new rate for it next month: the current one ends then.
        $this->actingAs($this->admin)->post($url, [...$base, 'rate' => '0.0060', 'effective_from' => now()->addMonth()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame(2, $deck->rates()->count());
        $this->assertNotNull($r->fresh()->effective_to);
        $this->assertSame('0.006000', $deck->rates()->whereNull('effective_to')->sole()->rate);

        // Nothing while an import runs.
        RateImport::create(['rate_deck_id' => $deck->id, 'filename' => 'x.csv', 'path' => 'x', 'mode' => 'update', 'effective_from' => now(), 'status' => 'running']);
        $this->actingAs($this->admin)->post($url, [...$base, 'prefix' => '44'])->assertSessionHasErrors('prefix');
        $this->assertSame(0, $deck->rates()->where('prefix', '44')->count());
    }

    public function test_all_rates_can_be_deleted_after_typing_the_name(): void
    {
        $deck = RateDeck::factory()->create(['name' => 'Carrier A']);
        $other = RateDeck::factory()->create();
        foreach ([$deck, $deck, $other] as $n => $d) {
            $d->rates()->create(['prefix' => '44'.$n, 'rate' => '0.01', 'effective_from' => now()]);
        }
        $url = route('rate-decks.rates.clear', $deck);

        $this->actingAs($this->admin)->delete($url, ['confirm' => 'carrier a'])->assertSessionHasErrors('confirm');
        $this->assertSame(2, $deck->rates()->count());

        RateImport::create(['rate_deck_id' => $deck->id, 'filename' => 'x.csv', 'path' => 'x', 'mode' => 'update', 'effective_from' => now(), 'status' => 'running']);
        $this->actingAs($this->admin)->delete($url, ['confirm' => 'Carrier A'])->assertSessionHasErrors('confirm');
        RateImport::query()->update(['status' => 'done']);

        $this->actingAs($this->admin)->delete($url, ['confirm' => 'Carrier A'])->assertSessionHasNoErrors()->assertRedirect(route('rate-decks.show', $deck));
        $this->assertSame(0, $deck->rates()->count());
        $this->assertSame(1, $other->rates()->count());
        $this->assertNotNull($deck->fresh());
    }

    public function test_tariffs_and_carrier_rates_are_kept_apart(): void
    {
        $tariff = RateDeck::factory()->tariff()->create(['name' => 'Retail']);
        $carrier = RateDeck::factory()->create(['name' => 'Acme A-Z']);

        // Each list shows only its own kind.
        $this->actingAs($this->admin)->get(route('tariffs.index'))->assertInertia(fn (Assert $p) => $p->where('kind', 'tariff')->has('decks', 1)->where('decks.0.name', 'Retail'));
        $this->actingAs($this->admin)->get(route('carrier-rates.index'))->assertInertia(fn (Assert $p) => $p->where('kind', 'carrier')->has('decks', 1)->where('decks.0.name', 'Acme A-Z'));
        $this->actingAs($this->admin)->get('/rate-decks')->assertInertia(fn (Assert $p) => $p->where('kind', 'carrier'));
        // The create form posts to /rate-decks: nothing else may claim that (a catch-all redirect did, once routes were cached).
        $posts = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => $r->uri() === 'rate-decks' && in_array('POST', $r->methods()));
        $this->assertSame(['rate-decks.store'], $posts->map->getName()->values()->all());
        $this->assertSame('rate-decks.store', app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/rate-decks', 'POST'))->getName());
        $this->actingAs($this->admin)->post(route('rate-decks.store'), ['name' => 'No kind', 'currency' => 'CAD'])->assertSessionHasErrors('kind');
        $this->actingAs($this->admin)->post(route('rate-decks.store'), ['name' => 'Bad currency', 'kind' => 'tariff', 'currency' => 'XYZ'])->assertSessionHasErrors('currency');

        // Customers pick tariffs, carriers pick carrier rates.
        $this->actingAs($this->admin)->get(route('accounts.create'))->assertInertia(fn (Assert $p) => $p->has('options.rateDecks', 1)->where('options.rateDecks.0.id', $tariff->id)
            ->has('options.carrierRates', 1)->where('options.carrierRates.0.id', $carrier->id));
        $form = ['name' => 'Cust', 'code' => 'CUST', 'role' => 'customer', 'status' => 'active', 'currency' => 'CAD', 'billing_type' => 'prepaid',
            'credit_limit' => 0, 'timezone' => 'UTC'];
        $this->actingAs($this->admin)->post(route('accounts.store'), [...$form, 'sell_rate_deck_id' => $carrier->id])->assertSessionHasErrors('sell_rate_deck_id');
        $this->actingAs($this->admin)->post(route('accounts.store'), [...$form, 'sell_rate_deck_id' => $tariff->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('accounts.store'), [...$form, 'code' => 'CARR', 'role' => 'carrier', 'codecs' => ['PCMU'], 'carrier_rate_deck_id' => $tariff->id])
            ->assertSessionHasErrors('carrier_rate_deck_id');

        // A tariff in use can't become carrier rates; an unused deck can change.
        $this->actingAs($this->admin)->put(route('rate-decks.update', $tariff), ['name' => 'Retail', 'kind' => 'carrier', 'currency' => 'USD'])->assertSessionHasErrors('kind');
        $this->actingAs($this->admin)->put(route('rate-decks.update', $carrier), ['name' => 'Acme A-Z', 'kind' => 'tariff', 'currency' => 'USD'])->assertSessionHasNoErrors();
        $this->assertSame('tariff', $carrier->fresh()->kind->value);
    }

    public function test_a_carrier_holds_its_limits_codecs_and_rates(): void
    {
        $deck = RateDeck::factory()->create(['kind' => 'carrier']);
        $form = ['name' => 'Acme Telecom', 'code' => 'ACME', 'role' => 'carrier', 'status' => 'active', 'currency' => 'CAD', 'billing_type' => 'prepaid',
            'credit_limit' => 0, 'timezone' => 'UTC'];

        $this->actingAs($this->admin)->post(route('accounts.store'), $form)->assertSessionHasErrors('codecs');
        $this->actingAs($this->admin)->post(route('accounts.store'), [...$form, 'codecs' => ['G729', 'PCMU', 'NOPE']])->assertSessionHasErrors('codecs.2');
        $this->actingAs($this->admin)->post(route('accounts.store'), [...$form, 'codecs' => ['G729', 'PCMU'], 'max_inbound' => 20, 'max_outbound' => 50,
            'carrier_rate_deck_id' => $deck->id, 'tech_prefix' => ' 99# ', 'strip_digits' => 1])->assertSessionHasNoErrors();

        $carrier = Account::query()->where('code', 'ACME')->sole();
        $trunk = $carrier->carrierTrunk;
        $this->assertSame('Acme Telecom', $trunk->name);
        $this->assertSame([20, 50, $deck->id, '99#', 1], [$trunk->max_inbound, $trunk->max_channels, $trunk->rate_deck_id, $trunk->tech_prefix, $trunk->strip_digits]);
        $this->assertSame(['PCMU', 'G729'], $trunk->codecs); // offered in the list's order, PCMU first

        // Editing updates the same record (one per carrier).
        $this->actingAs($this->admin)->get(route('accounts.edit', $carrier))->assertInertia(fn (Assert $p) => $p->where('account.max_inbound', 20)->where('account.codecs', ['PCMU', 'G729']));
        $this->actingAs($this->admin)->put(route('accounts.update', $carrier), [...$form, 'name' => 'Acme', 'codecs' => ['PCMA'], 'max_outbound' => ''])->assertSessionHasNoErrors();
        $this->assertSame(1, $carrier->trunks()->count());
        $trunk->refresh();
        $this->assertSame(['Acme', ['PCMA'], null, null], [$trunk->name, $trunk->codecs, $trunk->max_channels, $trunk->max_inbound]);

        $this->actingAs($this->admin)->get(route('accounts.show', $carrier))->assertInertia(fn (Assert $p) => $p->component('accounts/Show')
            ->where('carrier.trunk_id', $trunk->id)->where('carrier.codecs', ['G.711 A-law'])->has('carrier.sip_trunks', 0));
    }

    public function test_sip_trunks_are_added_on_the_carrier_and_validated(): void
    {
        $carrier = Account::factory()->vendor()->create(['code' => 'ACME']);
        $base = ['label' => 'Primary', 'host' => 'sip.vendor.example', 'port' => 5060, 'transport' => 'udp', 'priority' => 1, 'weight' => 100, 'enabled' => '1', 'checkboxes' => '1', 'send_rpid' => '1'];
        $store = route('accounts.sip-trunks.store', $carrier);

        $this->actingAs($this->admin)->post($store, [...$base, 'label' => ''])->assertSessionHasErrors('label');
        $this->actingAs($this->admin)->post($store, [...$base, 'host' => 'not a host'])->assertSessionHasErrors('host');
        $this->actingAs($this->admin)->post($store, [...$base, 'transport' => 'sctp'])->assertSessionHasErrors('transport');
        $this->actingAs($this->admin)->post($store, [...$base, 'outbound_proxy' => 'bad proxy'])->assertSessionHasErrors('outbound_proxy');
        $this->actingAs($this->admin)->post($store, [...$base, 'from_user' => 'a b'])->assertSessionHasErrors('from_user');
        $this->actingAs($this->admin)->post($store, [...$base, 'username' => 'acme', 'password' => 'pw1', 'from_user' => '12345', 'from_domain' => 'acme.example',
            'outbound_proxy' => 'proxy.acme.example:5070', 'send_pai' => '1'])->assertSessionHasNoErrors()->assertRedirect(route('accounts.show', $carrier));

        $gateway = Gateway::query()->sole();
        $this->assertSame($carrier->carrierTrunk->id, $gateway->trunk_id); // the carrier's routing record was made for it
        $this->assertStringStartsWith('acme-primary-', $gateway->name); // FreeSWITCH name, generated
        $this->assertSame(['Primary', 'pw1', '12345', 'acme.example', 'proxy.acme.example:5070', true, true],
            [$gateway->label, $gateway->password, $gateway->from_user, $gateway->from_domain, $gateway->outbound_proxy, $gateway->send_rpid, $gateway->send_pai]);

        // Blank password keeps it; unticked boxes are off; the FreeSWITCH name stays.
        $name = $gateway->name;
        $update = route('accounts.sip-trunks.update', [$carrier, $gateway]);
        $this->actingAs($this->admin)->put($update, [...$base, 'label' => 'Failover', 'priority' => 2, 'enabled' => null, 'send_rpid' => null, 'password' => ''])->assertSessionHasNoErrors();
        $gateway->refresh();
        $this->assertSame(['Failover', $name, 'pw1', false, false, false, null], [$gateway->label, $gateway->name, $gateway->password, $gateway->enabled, $gateway->send_rpid, $gateway->send_pai, $gateway->from_user]);
        $this->actingAs($this->admin)->put($update, [...$base, 'clear_password' => '1']);
        $this->assertNull($gateway->fresh()->password);
        $this->actingAs($this->admin)->get(route('accounts.sip-trunks.edit', [$carrier, $gateway]))->assertInertia(fn (Assert $p) => $p->component('accounts/SipTrunkEdit'));

        // Only through its own carrier; customers have no SIP trunks.
        $other = Account::factory()->vendor()->create();
        $this->actingAs($this->admin)->delete(route('accounts.sip-trunks.destroy', [$other, $gateway]))->assertNotFound();
        $this->actingAs($this->admin)->post(route('accounts.sip-trunks.store', Account::factory()->create()), $base)->assertNotFound();
        $this->actingAs($this->admin)->delete(route('accounts.sip-trunks.destroy', [$carrier, $gateway]))->assertRedirect(route('accounts.show', $carrier));
        $this->assertNull($gateway->fresh());
    }

    public function test_sip_trunk_options_reach_the_gateway_file_and_call_path(): void
    {
        $trunk = Trunk::factory()->create(['codecs' => ['PCMU', 'G729']]);
        $gateway = Gateway::factory()->create(['trunk_id' => $trunk->id, 'from_user' => '12345', 'from_domain' => 'acme.example', 'outbound_proxy' => '198.51.100.9:5070',
            'username' => 'acme', 'send_pai' => true]);
        $xml = app(\App\Services\Freeswitch\GatewaySync::class)->render($gateway);
        foreach (['"from-user" value="12345"', '"caller-id-in-from" value="false"', '"from-domain" value="acme.example"', '"outbound-proxy" value="198.51.100.9:5070"', '"username" value="acme"'] as $param) {
            $this->assertStringContainsString($param, $xml);
        }
        $row = \Illuminate\Support\Facades\DB::selectOne('SELECT * FROM softswitch_gateway_options(ARRAY[?]::bigint[])', [$gateway->id]);
        $this->assertSame([true, true, 'acme.example', 'PCMU,G729'], [$row->send_rpid, $row->send_pai, $row->cid_host, $row->codecs]);
    }

    public function test_routing_plan_routes_can_be_added_toggled_and_removed(): void
    {
        $this->actingAs($this->admin)->post(route('routing-plans.store'), ['name' => 'Standard', 'mode' => 'lcr', 'max_attempts' => 3])->assertSessionHasNoErrors();
        $plan = RoutingPlan::query()->sole();
        $trunk = Trunk::factory()->create();

        $add = fn (array $data) => $this->actingAs($this->admin)->post(route('routing-plans.routes.store', $plan), ['trunk_id' => $trunk->id, 'priority' => 1, 'weight' => 100, ...$data]);
        $add(['prefix' => '+44 7'])->assertSessionHasNoErrors();
        $add(['prefix' => ''])->assertSessionHasNoErrors();
        $add(['prefix' => '447'])->assertSessionHasErrors('trunk_id'); // duplicate
        $add(['prefix' => '44a'])->assertSessionHasErrors('prefix');

        $this->assertEqualsCanonicalizing(['447', ''], $plan->routes()->pluck('prefix')->all());

        $route = RoutingPlanRoute::query()->where('prefix', '447')->sole();
        $this->actingAs($this->admin)->patch(route('routing-plans.routes.update', [$plan, $route]), ['enabled' => false]);
        $this->assertFalse($route->fresh()->enabled);

        $otherPlan = RoutingPlan::factory()->create();
        $this->actingAs($this->admin)->delete(route('routing-plans.routes.destroy', [$otherPlan, $route]))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('routing-plans.routes.destroy', [$plan, $route]));
        $this->assertModelMissing($route);

        $this->actingAs($this->admin)->post(route('routing-plans.store'), ['name' => 'Bad', 'mode' => 'random', 'max_attempts' => 3])->assertSessionHasErrors('mode');
        $this->assertFalse(DB::table('routing_plans')->where('name', 'Bad')->exists());
    }
}
