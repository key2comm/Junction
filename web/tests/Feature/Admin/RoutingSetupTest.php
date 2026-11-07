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
        foreach (['rate-decks.index', 'trunks.index', 'routing-plans.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_index_and_form_pages_render(): void
    {
        $deck = RateDeck::factory()->create();
        $trunk = Trunk::factory()->create();
        $plan = RoutingPlan::factory()->create();

        $pages = [
            [route('rate-decks.index'), 'rate-decks/Index'], [route('rate-decks.create'), 'rate-decks/Form'],
            [route('rate-decks.show', $deck), 'rate-decks/Show'], [route('rate-decks.edit', $deck), 'rate-decks/Form'],
            [route('trunks.index'), 'trunks/Index'], [route('trunks.create'), 'trunks/Form'],
            [route('trunks.show', $trunk), 'trunks/Show'], [route('trunks.edit', $trunk), 'trunks/Form'],
            [route('routing-plans.index'), 'routing-plans/Index'], [route('routing-plans.create'), 'routing-plans/Form'],
            [route('routing-plans.show', $plan), 'routing-plans/Show'], [route('routing-plans.edit', $plan), 'routing-plans/Form'],
        ];
        foreach ($pages as [$url, $component]) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
        }
    }

    public function test_rate_decks_can_be_created_and_files_queued_for_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->admin)->post(route('rate-decks.store'), ['name' => 'A-Z Wholesale', 'currency' => 'cad'])->assertSessionHasNoErrors();
        $deck = RateDeck::query()->sole();
        $this->assertSame('CAD', $deck->currency);

        $file = UploadedFile::fake()->createWithContent('az.csv', "prefix,rate\n1,0.01\n");
        $this->actingAs($this->admin)->post(route('rate-decks.imports.store', $deck), ['file' => $file, 'mode' => 'replace', 'effective_from' => '2026-12-01T00:00'])
            ->assertSessionHasNoErrors();

        $import = RateImport::query()->sole();
        $this->assertSame('replace', $import->mode);
        $this->assertSame('2026-12-01 00:00:00', $import->effective_from->utc()->format('Y-m-d H:i:s'));
        Storage::disk('local')->assertExists($import->path);
        Queue::assertPushed(ImportRates::class);

        // Only one import per deck at a time.
        $this->actingAs($this->admin)->post(route('rate-decks.imports.store', $deck), ['file' => UploadedFile::fake()->createWithContent('b.csv', "prefix,rate\n"), 'mode' => 'update'])
            ->assertSessionHasErrors('file');
    }

    public function test_trunks_require_a_vendor_account(): void
    {
        $customer = Account::factory()->create();
        $vendor = Account::factory()->vendor()->create();

        $this->actingAs($this->admin)->post(route('trunks.store'), ['account_id' => $customer->id, 'name' => 'Main'])
            ->assertSessionHasErrors('account_id');
        $this->actingAs($this->admin)->post(route('trunks.store'), ['account_id' => $vendor->id, 'name' => 'Main', 'enabled' => '1', 'tech_prefix' => '99#', 'strip_digits' => '1'])
            ->assertSessionHasNoErrors();

        $trunk = Trunk::query()->sole();
        $this->assertTrue($trunk->enabled);
        $this->assertSame('99#', $trunk->tech_prefix);

        // Names are unique per vendor.
        $this->actingAs($this->admin)->post(route('trunks.store'), ['account_id' => $vendor->id, 'name' => 'Main'])
            ->assertSessionHasErrors('name');
    }

    public function test_gateways_are_validated_and_passwords_kept_unless_changed(): void
    {
        $trunk = Trunk::factory()->create();
        $base = ['host' => 'sip.vendor.example', 'port' => 5060, 'transport' => 'udp', 'priority' => 1, 'weight' => 100, 'enabled' => '1'];

        $this->actingAs($this->admin)->post(route('trunks.gateways.store', $trunk), [...$base, 'host' => 'not a host'])->assertSessionHasErrors('host');
        $this->actingAs($this->admin)->post(route('trunks.gateways.store', $trunk), [...$base, 'transport' => 'sctp'])->assertSessionHasErrors('transport');
        $this->actingAs($this->admin)->post(route('trunks.gateways.store', $trunk), [...$base, 'name' => 'Vendor GW 1', 'username' => 'acme', 'password' => 'pw1'])
            ->assertSessionHasNoErrors();

        $gateway = Gateway::query()->sole();
        $this->assertSame('vendor-gw-1', $gateway->name); // slugged for FreeSWITCH
        $this->assertSame('pw1', $gateway->password);

        // Blank password keeps it; unticked "enabled" disables.
        $this->actingAs($this->admin)->put(route('trunks.gateways.update', [$trunk, $gateway]), [...$base, 'name' => 'vendor-gw-1', 'enabled' => null, 'password' => ''])
            ->assertSessionHasNoErrors();
        $gateway->refresh();
        $this->assertSame('pw1', $gateway->password);
        $this->assertFalse($gateway->enabled);

        $this->actingAs($this->admin)->put(route('trunks.gateways.update', [$trunk, $gateway]), [...$base, 'name' => 'vendor-gw-1', 'clear_password' => '1']);
        $this->assertNull($gateway->fresh()->password);

        // Gateways can only be reached through their own trunk.
        $other = Trunk::factory()->create();
        $this->actingAs($this->admin)->delete(route('trunks.gateways.destroy', [$other, $gateway]))->assertNotFound();
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
