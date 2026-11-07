<?php

namespace Tests\Feature\Billing;

use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tariffs built from Carrier Rates plus a markup (App\Services\TariffBuilder),
 * and single rates changed by hand afterwards.
 */
class TariffBuildTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private RateDeck $tariff;

    private RateDeck $carrierA;

    private RateDeck $carrierB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->tariff = RateDeck::factory()->tariff()->create(['name' => 'Retail', 'currency' => 'CAD']);
        $this->carrierA = RateDeck::factory()->create(['name' => 'Carrier A', 'currency' => 'CAD']);
        $this->carrierB = RateDeck::factory()->create(['name' => 'Carrier B', 'currency' => 'CAD']);

        // A covers all of 44; B is cheaper for 44 but dearer for London (4420), and adds France.
        $this->rate($this->carrierA, '44', '0.015000', 'United Kingdom');
        $this->rate($this->carrierB, '44', '0.010000', 'UK');
        $this->rate($this->carrierB, '4420', '0.020000', 'UK London', ['connect_fee' => '0.01', 'initial_increment' => 6, 'increment' => 6]);
        $this->rate($this->carrierB, '33', '0.030000', 'France');
    }

    private function rate(RateDeck $deck, string $prefix, string $rate, ?string $destination = null, array $extra = []): Rate
    {
        return Rate::factory()->for($deck)->create(['prefix' => $prefix, 'rate' => $rate, 'destination' => $destination,
            'connect_fee' => 0, 'initial_increment' => 60, 'increment' => 60, 'effective_from' => now()->subDay(), 'effective_to' => null, ...$extra]);
    }

    private function build(array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('rate-decks.build', $this->tariff), $form);
    }

    /** @return array<string, Rate> current tariff rates by prefix */
    private function tariffRates(): array
    {
        // Prefix keys like '44' become ints in PHP arrays; prefixes() compares them as strings.
        return $this->tariff->rates()->effectiveAt(now())->get()->keyBy('prefix')->all();
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        return $this->tariff->rates()->effectiveAt(now())->orderBy('prefix')->pluck('prefix')->map(fn ($p) => (string) $p)->all();
    }

    public function test_a_tariff_is_built_from_the_highest_carrier_rate_plus_a_percentage(): void
    {
        $this->build(['sources' => [$this->carrierA->id, $this->carrierB->id], 'markup_type' => 'percent', 'markup_value' => 15])
            ->assertRedirect(route('rate-decks.show', $this->tariff))->assertSessionHasNoErrors();

        $rates = $this->tariffRates();
        $this->assertSame(['33', '44', '4420'], $this->prefixes());
        $this->assertSame('0.017250', $rates['44']->rate);        // max(A 0.015, B 0.010) + 15%
        $this->assertSame('United Kingdom', $rates['44']->destination);
        $this->assertSame('0.023000', $rates['4420']->rate);      // max(A via 44: 0.015, B 0.020) + 15%
        $this->assertSame('UK London', $rates['4420']->destination);
        $this->assertSame('0.010000', $rates['4420']->connect_fee); // from the carrier rate that set the price
        $this->assertSame(6, $rates['4420']->increment);
        $this->assertSame('0.034500', $rates['33']->rate);

        $import = RateImport::query()->sole();
        $this->assertSame('build', $import->mode);
        $this->assertSame('done', $import->status);
        $this->assertStringContainsString('Carrier A, Carrier B + 15%', $import->filename);
        $this->assertNotNull($this->tariff->fresh()->built_at);
    }

    public function test_a_fixed_markup_is_added_per_minute_and_rebuilds_replace_the_rates(): void
    {
        $this->build(['sources' => [$this->carrierA->id], 'markup_type' => 'fixed', 'markup_value' => '0.10']);
        $rates = $this->tariffRates();
        $this->assertSame(['44'], $this->prefixes());
        $this->assertSame('0.115000', $rates['44']->rate);

        // The page remembers the sources and markup for a rebuild.
        $this->actingAs($this->admin)->get(route('rate-decks.show', $this->tariff))->assertInertia(fn (Assert $p) => $p
            ->where('build.sources', [$this->carrierA->id])->where('build.markup_type', 'fixed')->where('build.markup_value', '0.100000')
            ->has('build.carrier_rates', 2));

        // Rebuild with both carriers: France appears, London gets its own price.
        $this->travel(1)->minutes();
        $this->build(['sources' => [$this->carrierA->id, $this->carrierB->id], 'markup_type' => 'fixed', 'markup_value' => '0.10']);
        $rates = $this->tariffRates();
        $this->assertSame(['33', '44', '4420'], $this->prefixes());
        $this->assertSame('0.120000', $rates['4420']->rate);
        $this->assertSame('0.115000', $rates['44']->rate); // unchanged rows are left alone

        // Dropping a carrier ends the prefixes only it had.
        $this->travel(1)->minutes();
        $this->build(['sources' => [$this->carrierA->id], 'markup_type' => 'percent', 'markup_value' => 0]);
        $this->assertSame(['44'], $this->prefixes());
    }

    public function test_build_validation(): void
    {
        $this->build(['sources' => [], 'markup_type' => 'percent', 'markup_value' => 15])->assertSessionHasErrors('sources');
        $this->build(['sources' => [$this->tariff->id], 'markup_type' => 'percent', 'markup_value' => 15])->assertSessionHasErrors('sources.0');
        $this->build(['sources' => [$this->carrierA->id], 'markup_type' => 'percent', 'markup_value' => -1])->assertSessionHasErrors('markup_value');
        $usd = RateDeck::factory()->create(['currency' => 'USD']);
        $this->build(['sources' => [$usd->id], 'markup_type' => 'percent', 'markup_value' => 15])->assertSessionHasErrors('sources');

        // Only tariffs are built.
        $this->actingAs($this->admin)->post(route('rate-decks.build', $this->carrierA), ['sources' => [$this->carrierB->id], 'markup_type' => 'percent', 'markup_value' => 1])
            ->assertNotFound();
        $this->assertSame(0, RateImport::query()->count());
    }

    public function test_a_single_rate_can_be_changed_by_hand_keeping_history(): void
    {
        $this->build(['sources' => [$this->carrierA->id, $this->carrierB->id], 'markup_type' => 'percent', 'markup_value' => 15]);
        $old = $this->tariffRates()['44'];

        $this->travel(5)->minutes();
        $this->actingAs($this->admin)->patch(route('rate-decks.rates.update', [$this->tariff, $old]), ['rate' => '0.019'])->assertSessionHasNoErrors();

        $current = $this->tariffRates()['44'];
        $this->assertNotSame($old->id, $current->id);
        $this->assertSame('0.019000', $current->rate);
        $this->assertSame('United Kingdom', $current->destination);
        $this->assertNotNull($old->fresh()->effective_to); // the old price stays on record for calls already made

        // An ended rate can't be changed; a rate from another deck is not found.
        $this->actingAs($this->admin)->patch(route('rate-decks.rates.update', [$this->tariff, $old]), ['rate' => '1'])->assertSessionHasErrors('rate');
        $other = $this->carrierA->rates()->first();
        $this->actingAs($this->admin)->patch(route('rate-decks.rates.update', [$this->tariff, $other]), ['rate' => '1'])->assertNotFound();

        // A rate that hasn't started yet is corrected in place.
        $future = $this->rate($this->tariff, '49', '0.05', 'Germany', ['effective_from' => now()->addDay()]);
        $this->actingAs($this->admin)->patch(route('rate-decks.rates.update', [$this->tariff, $future]), ['rate' => '0.06'])->assertSessionHasNoErrors();
        $this->assertSame('0.060000', $future->fresh()->rate);
        $this->assertSame(1, $this->tariff->rates()->where('prefix', '49')->count());
    }
}
