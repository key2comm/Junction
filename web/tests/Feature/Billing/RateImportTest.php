<?php

namespace Tests\Feature\Billing;

use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Services\RateImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RateImportTest extends TestCase
{
    use RefreshDatabase;

    private RateDeck $deck;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->deck = RateDeck::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'UTC'));
    }

    private function import(string $csv, string $mode = 'update', ?string $effective = null): RateImport
    {
        $path = 'rate-imports/'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);
        $import = RateImport::create([
            'rate_deck_id' => $this->deck->id,
            'filename' => 'deck.csv',
            'path' => $path,
            'mode' => $mode,
            'effective_from' => $effective ? CarbonImmutable::parse($effective, 'UTC') : now(),
        ]);
        app(RateImporter::class)->run($import);

        return $import->fresh();
    }

    /** @return array<string, string> prefix => rate, for rates in effect at $at */
    private function ratesAt(string $at): array
    {
        return Rate::query()->where('rate_deck_id', $this->deck->id)
            ->effectiveAt(CarbonImmutable::parse($at, 'UTC'))
            ->orderBy('prefix')->pluck('rate', 'prefix')->all();
    }

    public function test_a_new_deck_is_imported(): void
    {
        $import = $this->import("prefix,destination,rate,initial_increment,increment\n44,UK,0.01,60,60\n447,UK Mobile,0.05,6,6\n");

        $this->assertSame('done', $import->status, json_encode($import->errors));
        $this->assertSame(2, $import->stats['added']);
        $this->assertSame(['44' => '0.010000', '447' => '0.050000'], $this->ratesAt('2026-11-07 12:00:01'));
        $this->assertSame(6, Rate::query()->where('prefix', '447')->value('increment'));
    }

    public function test_header_aliases_semicolons_bom_and_plus_signs_are_accepted(): void
    {
        $import = $this->import("\xEF\xBB\xBFCode;Description;Price;Connection Fee\n+33;France;0,02;0\n+33 6;France Mobile;0.07;0.001\n");

        // "0,02" is not a valid number: the whole import must be rejected.
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('Line 2', $import->errors[0]);

        $import = $this->import("\xEF\xBB\xBFCode;Description;Price;Connection Fee\n+33;France;0.02;0\n+33 6;France Mobile;0.07;0.001\n");
        $this->assertSame('done', $import->status, json_encode($import->errors));
        $this->assertSame(['33' => '0.020000', '336' => '0.070000'], $this->ratesAt('2026-11-07 12:00:01'));
        $this->assertSame('0.001000', Rate::query()->where('prefix', '336')->value('connect_fee'));
    }

    public function test_any_error_aborts_the_whole_import(): void
    {
        $import = $this->import("prefix,rate\n1,0.01\n1x,0.02\n44,-1\n1,0.03\n");

        $this->assertSame('failed', $import->status);
        $this->assertCount(3, $import->errors); // bad prefix, negative rate, duplicate prefix
        $this->assertSame(0, Rate::query()->count());
    }

    public function test_missing_required_columns_are_reported(): void
    {
        $import = $this->import("destination,price_per_call\nUK,1\n");

        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('"prefix" column', $import->errors[0]);
    }

    public function test_changed_rates_end_date_the_old_rate_and_unchanged_rows_are_left_alone(): void
    {
        $this->import("prefix,rate\n44,0.01\n33,0.02\n");
        $before = Rate::query()->where('prefix', '33')->value('id');

        $this->travelTo(CarbonImmutable::parse('2026-11-08 00:00:00', 'UTC'));
        $import = $this->import("prefix,rate\n44,0.012\n33,0.02\n");

        $this->assertSame(['added' => 0, 'changed' => 1, 'unchanged' => 1], array_intersect_key($import->stats, array_flip(['added', 'changed', 'unchanged'])));
        $this->assertSame(1, $import->stats['increases_within_notice']);
        $this->assertSame($before, Rate::query()->where('prefix', '33')->value('id')); // untouched
        $this->assertSame('0.010000', $this->ratesAt('2026-11-07 23:59:59')['44']);
        $this->assertSame('0.012000', $this->ratesAt('2026-11-08 00:00:01')['44']);
        $this->assertSame(3, Rate::query()->count());
    }

    public function test_future_dated_import_schedules_the_change_and_a_later_upload_supersedes_it(): void
    {
        $this->import("prefix,rate\n1,0.005\n");

        $this->import("prefix,rate\n1,0.006\n", effective: '2026-12-01');
        $this->assertSame('0.005000', $this->ratesAt('2026-11-30 23:59:59')['1']);
        $this->assertSame('0.006000', $this->ratesAt('2026-12-01 00:00:00')['1']);

        // Re-upload with a different future price: the scheduled 0.006 is replaced, not stacked.
        $this->import("prefix,rate\n1,0.0055\n", effective: '2026-12-01');
        $this->assertSame('0.005500', $this->ratesAt('2026-12-15')['1']);
        $this->assertSame(2, Rate::query()->count());
    }

    public function test_replace_mode_ends_prefixes_missing_from_the_file(): void
    {
        $this->import("prefix,rate\n44,0.01\n33,0.02\n49,0.03\n");

        $this->travelTo(CarbonImmutable::parse('2026-11-09 00:00:00', 'UTC'));
        $import = $this->import("prefix,rate\n44,0.01\n", mode: 'replace');

        $this->assertSame(2, $import->stats['ended']);
        $this->assertSame(['44' => '0.010000'], $this->ratesAt('2026-11-09 00:00:01'));
        $this->assertSame(['33' => '0.020000', '44' => '0.010000', '49' => '0.030000'], $this->ratesAt('2026-11-08 12:00:00'));
    }

    public function test_update_mode_keeps_prefixes_missing_from_the_file(): void
    {
        $this->import("prefix,rate\n44,0.01\n33,0.02\n");
        $this->import("prefix,rate\n44,0.011\n", mode: 'update');

        $this->assertSame(['33' => '0.020000', '44' => '0.011000'], $this->ratesAt('2026-11-07 12:00:01'));
    }

    public function test_per_row_effective_dates_override_the_upload_date(): void
    {
        $this->import("prefix,rate,effective_from\n44,0.01,2026-11-01\n33,0.02,\n");

        $this->assertSame(['44' => '0.010000'], $this->ratesAt('2026-11-05'));
        $this->assertSame(['33' => '0.020000', '44' => '0.010000'], $this->ratesAt('2026-11-07 12:00:01'));
    }
}
