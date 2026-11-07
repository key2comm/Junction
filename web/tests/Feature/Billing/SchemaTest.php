<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\AccountIp;
use App\Models\BlockedPrefix;
use App\Models\Gateway;
use App\Models\Rate;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database itself enforces the invariants the routing engine relies on,
 * because FreeSWITCH reads these tables without going through Laravel.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private function assertRejected(Closure $write, string $constraint): void
    {
        try {
            DB::transaction($write);
            $this->fail("Expected constraint {$constraint} to reject the write.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    public function test_accounts_must_be_a_customer_or_vendor(): void
    {
        $this->assertRejected(
            fn () => Account::factory()->create(['is_customer' => false, 'is_vendor' => false]),
            'accounts_has_role',
        );
        $this->assertRejected(fn () => Account::factory()->create(['code' => 'has space']), 'accounts_code_format');
    }

    public function test_prefixes_must_be_digits(): void
    {
        $this->assertRejected(fn () => Rate::factory()->create(['prefix' => '44x']), 'rates_prefix_digits');
        $this->assertRejected(fn () => Rate::factory()->create(['prefix' => '']), 'rates_prefix_digits');
        $this->assertRejected(fn () => BlockedPrefix::factory()->create(['prefix' => '+44']), 'blocked_prefixes_prefix_digits');

        // Routing plans may use '' as the catch-all prefix.
        $this->assertSame('', RoutingPlanRoute::factory()->create(['prefix' => ''])->prefix);
    }

    public function test_gateway_names_must_be_safe_for_freeswitch(): void
    {
        $this->assertRejected(fn () => Gateway::factory()->create(['name' => 'Vendor A']), 'gateways_name_format');
        // The model's enum cast already refuses bad transports; check the
        // database refuses them too (FreeSWITCH-side writers skip the model).
        $trunk = Trunk::factory()->create();
        $this->assertRejected(fn () => DB::table('gateways')->insert([
            'trunk_id' => $trunk->id, 'name' => 'gw1', 'host' => '192.0.2.1', 'transport' => 'sctp',
        ]), 'gateways_transport');
    }

    public function test_a_prefix_can_only_be_blocked_once_globally(): void
    {
        BlockedPrefix::factory()->create(['account_id' => null, 'prefix' => '882']);
        BlockedPrefix::factory()->create(['account_id' => Account::factory(), 'prefix' => '882']);

        $this->assertRejected(
            fn () => BlockedPrefix::factory()->create(['account_id' => null, 'prefix' => '882']),
            'blocked_prefixes_account_prefix_unique',
        );
    }

    public function test_customers_are_identified_by_the_most_specific_ip_range(): void
    {
        $wide = AccountIp::factory()->create(['ip' => '198.51.100.0/24']);
        $exact = AccountIp::factory()->create(['ip' => '198.51.100.20']);

        $match = fn (string $ip) => AccountIp::query()
            ->whereRaw('?::inet <<= ip', [$ip])
            ->orderByRaw('masklen(ip) desc')
            ->value('account_id');

        $this->assertSame($exact->account_id, $match('198.51.100.20'));
        $this->assertSame($wide->account_id, $match('198.51.100.21'));
        $this->assertNull($match('203.0.113.1'));
    }

    public function test_vendor_trunks_format_numbers_and_encrypt_gateway_passwords(): void
    {
        $trunk = Trunk::factory()->create(['tech_prefix' => '777#', 'strip_digits' => 1]);
        $this->assertSame('777#4420794600', $trunk->formatNumber('14420794600'));

        $gateway = Gateway::factory()->for($trunk)->create(['password' => 's3cret']);
        $this->assertSame('s3cret', $gateway->fresh()->password);
        $this->assertNotSame('s3cret', DB::table('gateways')->where('id', $gateway->id)->value('password'));
        $this->assertArrayNotHasKey('password', $gateway->toArray());
    }
}
