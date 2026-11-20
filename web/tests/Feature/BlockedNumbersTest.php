<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BlockedNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Customers → a customer → Blocked numbers. */
class BlockedNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_can_be_blocked_changed_and_unblocked(): void
    {
        $admin = User::factory()->create();
        $customer = Account::factory()->create(['is_customer' => true]);

        $this->actingAs($admin)->post(route('accounts.blocked-numbers.store', $customer), ['number' => '(416) 555-0100', 'direction' => 'both', 'reason' => 'Retired forwarding number'])
            ->assertSessionHasNoErrors()->assertRedirect(route('accounts.show', $customer));
        $blocked = BlockedNumber::query()->sole();
        $this->assertSame(['14165550100', 'both', 'Retired forwarding number'], [$blocked->number, $blocked->direction, $blocked->reason]);

        // The same number twice, and nonsense, are refused.
        $this->actingAs($admin)->post(route('accounts.blocked-numbers.store', $customer), ['number' => '+1 416 555 0100', 'direction' => 'both'])->assertSessionHasErrors('number');
        $this->actingAs($admin)->post(route('accounts.blocked-numbers.store', $customer), ['number' => 'abc', 'direction' => 'both'])->assertSessionHasErrors('number');
        $this->actingAs($admin)->post(route('accounts.blocked-numbers.store', $customer), ['number' => '14165550102', 'direction' => 'sideways'])->assertSessionHasErrors('direction');

        $this->actingAs($admin)->get(route('accounts.show', $customer))->assertInertia(fn (Assert $p) => $p
            ->where('blockedNumbers.0.number', '14165550100')->has('blockedDirections.outbound'));

        $this->actingAs($admin)->patch(route('accounts.blocked-numbers.update', [$customer, $blocked]), ['direction' => 'outbound'])->assertSessionHasNoErrors();
        $this->assertSame('outbound', $blocked->fresh()->direction);

        $this->actingAs($admin)->delete(route('accounts.blocked-numbers.destroy', [$customer, $blocked]))->assertRedirect();
        $this->assertSame(0, BlockedNumber::query()->count());
    }

    public function test_a_blocked_number_belongs_to_its_customer(): void
    {
        $admin = User::factory()->create();
        $customer = Account::factory()->create(['is_customer' => true]);
        $other = Account::factory()->create(['is_customer' => true]);
        $blocked = BlockedNumber::create(['account_id' => $other->id, 'number' => '14165550100', 'direction' => 'both']);

        $this->actingAs($admin)->delete(route('accounts.blocked-numbers.destroy', [$customer, $blocked]))->assertNotFound();
        $this->assertSame(1, BlockedNumber::query()->count());
    }
}
