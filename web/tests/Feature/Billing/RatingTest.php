<?php

namespace Tests\Feature\Billing;

use App\Models\Rate;
use App\Models\RateDeck;
use App\Support\Decimal;
use App\Support\Prefixes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefixes_are_listed_longest_first_and_ignore_non_digits(): void
    {
        $this->assertSame(['4420', '442', '44', '4'], Prefixes::of('+44 20'));
        $this->assertSame([], Prefixes::of('abc'));
    }

    public static function increments(): array
    {
        // [initial, increment, seconds, expected billable seconds]
        return [
            'unanswered' => [60, 60, 0, 0],
            '60/60 short call' => [60, 60, 1, 60],
            '60/60 rounds up' => [60, 60, 61, 120],
            '6/6' => [6, 6, 13, 18],
            '60/6 under initial' => [60, 6, 30, 60],
            '60/6 over initial' => [60, 6, 61, 66],
            '1/1 exact' => [1, 1, 37, 37],
            '30/6' => [30, 6, 31, 36],
        ];
    }

    #[DataProvider('increments')]
    public function test_billable_seconds_follow_increments(int $initial, int $increment, int $seconds, int $expected): void
    {
        $rate = new Rate(['rate' => '0.01', 'initial_increment' => $initial, 'increment' => $increment]);

        $this->assertSame($expected, $rate->billableSeconds($seconds));
    }

    public function test_cost_includes_connect_fee_only_for_answered_calls(): void
    {
        $rate = new Rate(['rate' => '0.012', 'connect_fee' => '0.005', 'initial_increment' => 6, 'increment' => 6]);

        $this->assertSame('0.000000', $rate->costFor(0));
        // 13s -> 18s billable; 0.012/min * 18/60 = 0.0036, + 0.005 fee
        $this->assertSame('0.008600', $rate->costFor(13));
    }

    public function test_cost_is_rounded_not_truncated(): void
    {
        // 0.0123457/min for 60s = 0.0123457 -> 0.012346 (half up), not 0.012345
        $rate = new Rate(['rate' => '0.0123457', 'initial_increment' => 60, 'increment' => 60]);
        $this->assertSame('0.012346', $rate->costFor(60));

        $this->assertSame('-0.000001', Decimal::round('-0.0000005'));
        $this->assertSame('0.000000', Decimal::round('-0.0000004'));
    }

    public function test_rate_lookup_uses_longest_matching_prefix(): void
    {
        $deck = RateDeck::factory()->create();
        Rate::factory()->for($deck)->create(['prefix' => '44', 'rate' => '0.010000']);
        Rate::factory()->for($deck)->create(['prefix' => '447', 'rate' => '0.050000']);
        Rate::factory()->for($deck)->create(['prefix' => '4477', 'rate' => '0.080000']);

        $this->assertSame('0.080000', $deck->rateFor('447700900123')->rate);
        $this->assertSame('0.050000', $deck->rateFor('447900900123')->rate);
        $this->assertSame('0.010000', $deck->rateFor('442079460000')->rate);
        $this->assertNull($deck->rateFor('33123456789'));
    }

    public function test_rate_lookup_respects_effective_dates(): void
    {
        $deck = RateDeck::factory()->create();
        Rate::factory()->for($deck)->create([
            'prefix' => '1', 'rate' => '0.005000',
            'effective_from' => now()->subMonth(), 'effective_to' => now()->addWeek(),
        ]);
        Rate::factory()->for($deck)->create([
            'prefix' => '1', 'rate' => '0.006000', 'effective_from' => now()->addWeek(),
        ]);
        // A longer prefix that is not yet effective must not win.
        Rate::factory()->for($deck)->create([
            'prefix' => '1212', 'rate' => '0.009000', 'effective_from' => now()->addDay(),
        ]);

        $this->assertSame('0.005000', $deck->rateFor('12125550100')->rate);
        $this->assertSame('0.009000', $deck->rateFor('12125550100', now()->addDays(2))->rate);
        $this->assertSame('0.006000', $deck->rateFor('13105550100', now()->addWeeks(2))->rate);
    }
}
