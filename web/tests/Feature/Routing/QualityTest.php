<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Cdr;
use App\Models\Trunk;
use App\Models\User;
use App\Services\CdrIngestor;
use App\Services\QualityReport;
use App\Services\QualityRollup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QualityTest extends TestCase
{
    use RefreshDatabase;

    private Account $vendor;

    private Trunk $trunk;

    private Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:30', 'UTC'));
        $this->vendor = Account::factory()->vendor()->create(['code' => 'VEND']);
        $this->trunk = Trunk::factory()->for($this->vendor, 'account')->create(['name' => 'Main']);
        $this->customer = Account::factory()->create();
    }

    private function xml(array $vars): string
    {
        $v = implode('', array_map(fn ($k, $val) => "<{$k}>".rawurlencode((string) $val)."</{$k}>", array_keys($vars), $vars));

        return "<?xml version=\"1.0\"?><cdr><variables>{$v}</variables><callflow><caller_profile><caller_id_number>1555</caller_id_number>"
            .'<destination_number>442079460000</destination_number><network_addr>198.51.100.7</network_addr></caller_profile></callflow></cdr>';
    }

    private function us(string $at): int
    {
        return (int) (CarbonImmutable::parse($at, 'UTC')->getPreciseTimestamp(6));
    }

    private function aLeg(string $uuid): string
    {
        return $this->xml([
            'uuid' => $uuid, 'start_uepoch' => $this->us('2026-11-10 12:00:00.000'), 'progress_media_uepoch' => $this->us('2026-11-10 12:00:02.500'),
            'answer_uepoch' => $this->us('2026-11-10 12:00:05'), 'end_uepoch' => $this->us('2026-11-10 12:01:05'), 'billsec' => 60,
            'softswitch_account_id' => $this->customer->id, 'softswitch_trunk_id' => $this->trunk->id,
            'softswitch_attempts' => json_encode([
                ['trunk' => $this->trunk->id, 'start' => 1, 'end' => 2, 'sip' => 503, 'cause' => 'NORMAL_TEMPORARY_FAILURE', 'answered' => false, 'buy_prefix' => '44'],
                ['trunk' => $this->trunk->id, 'start' => 2, 'end' => 3, 'sip' => 200, 'cause' => 'ANSWERED', 'answered' => true, 'buy_prefix' => '44'],
            ]),
        ]);
    }

    private function bLeg(string $parent, int $attempt, ?string $progress, ?string $answer, int $sip): string
    {
        return $this->xml(array_filter([
            'uuid' => (string) Str::uuid(), 'softswitch_parent_uuid' => $parent, 'softswitch_attempt_no' => $attempt,
            'start_uepoch' => $this->us('2026-11-10 12:00:00.100'),
            'progress_uepoch' => $progress ? $this->us($progress) : null, 'answer_uepoch' => $answer ? $this->us($answer) : null,
            'end_uepoch' => $this->us('2026-11-10 12:01:05'), 'sip_term_status' => $sip,
        ], fn ($v) => $v !== null));
    }

    public function test_vendor_leg_timings_give_per_attempt_pdd_whichever_arrives_first(): void
    {
        $ingest = app(CdrIngestor::class);

        // Vendor legs before the customer leg (the usual order).
        $a = (string) Str::uuid();
        $this->assertNull($ingest->ingest($this->bLeg($a, 1, null, null, 503)));
        $ingest->ingest($this->bLeg($a, 2, '2026-11-10 12:00:02.350', '2026-11-10 12:00:05', 200));
        $ingest->ingest($this->aLeg($a));

        $cdr = Cdr::query()->where('uuid', $a)->sole();
        $this->assertSame(2500, $cdr->pdd_ms);                         // customer: INVITE -> early media
        $this->assertSame([null, 2250], $cdr->attemptLog()->orderBy('attempt')->pluck('pdd_ms')->all()); // vendor: our INVITE -> ringing
        $this->assertSame(1, Cdr::query()->count());                    // vendor legs are not calls

        // And the other way round.
        $b = (string) Str::uuid();
        $ingest->ingest($this->aLeg($b));
        $ingest->ingest($this->bLeg($b, 2, '2026-11-10 12:00:01.100', null, 200));
        $this->assertSame(1000, Cdr::query()->where('uuid', $b)->sole()->attemptLog()->where('attempt', 2)->value('pdd_ms'));

        // A re-posted customer CDR keeps the timings.
        $ingest->ingest($this->aLeg($b));
        $this->assertSame(1000, Cdr::query()->where('uuid', $b)->sole()->attemptLog()->where('attempt', 2)->value('pdd_ms'));
    }

    private function attempt(array $attrs, int $billsec = 0): void
    {
        $cdr = Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $this->customer->id, 'callee' => '44',
            'started_at' => $attrs['started_at'] ?? now()->subMinutes(10), 'billsec' => $billsec]);
        $cdr->attemptLog()->create(['attempt' => 1, 'trunk_id' => $this->trunk->id, 'buy_prefix' => '44', 'started_at' => now()->subMinutes(10), ...$attrs]);
    }

    public function test_rollup_and_report(): void
    {
        $this->attempt(['answered_at' => now(), 'sip_code' => 200, 'pdd_ms' => 2000], billsec: 120);
        $this->attempt(['answered_at' => now(), 'sip_code' => 200, 'pdd_ms' => 4000], billsec: 60);
        $this->attempt(['sip_code' => 486]);
        $this->attempt(['sip_code' => 503, 'hangup_cause' => 'NORMAL_TEMPORARY_FAILURE']);
        $this->attempt(['sip_code' => 404]);
        $this->attempt(['hangup_cause' => 'NO_ANSWER']);
        $this->attempt(['buy_prefix' => '33', 'sip_code' => 503, 'started_at' => now()->subHours(2)]);

        $rollup = app(QualityRollup::class);
        $rollup->rebuild(now()->subHours(3), now());
        $rollup->rebuild(now()->subHours(3), now()); // idempotent
        $this->assertSame(2, DB::table('quality_hourly')->count());

        $rows = app(QualityReport::class)->rows(now()->subDay(), now(), 'trunk');
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertSame(7, $r['attempts']);
        $this->assertSame(28.6, $r['asr']);     // 2 / 7
        $this->assertSame(90, $r['acd']);       // (120 + 60) / 2
        $this->assertSame(3000, $r['pdd_ms']);  // (2000 + 4000) / 2
        $this->assertSame(14.3, $r['busy_pct']);
        $this->assertSame(14.3, $r['no_answer_pct']);
        $this->assertSame(28.6, $r['congestion_pct']); // two 503s
        $this->assertSame(14.3, $r['rejected_pct']);   // 404

        $byPrefix = app(QualityReport::class)->rows(now()->subDay(), now(), 'prefix');
        $this->assertSame(['44', '33'], $byPrefix->pluck('prefix')->all());

        $series = app(QualityReport::class)->hourlyByTrunk(now()->subDay(), now(), [$this->trunk->id]);
        $this->assertCount(2, $series[$this->trunk->id]);
    }

    public function test_page_and_api(): void
    {
        $this->attempt(['answered_at' => now(), 'sip_code' => 200], billsec: 30);
        $this->artisan('softswitch:quality-rollup')->assertSuccessful();

        $this->actingAs(User::factory()->create())->get(route('quality.index'))
            ->assertInertia(fn (Assert $p) => $p->component('quality/Index')->has('rows', 1)->where('rows.0.asr', 100)->has("series.{$this->trunk->id}", 1));

        [, $plain] = ApiToken::issue('q', ['routing:read']);
        $this->withHeaders(['Authorization' => "Bearer {$plain}"])->getJson('/api/v1/quality?group=vendor')
            ->assertOk()->assertJsonPath('data.0.vendor_code', 'VEND')->assertJsonPath('meta.group', 'vendor');
        [, $cdrOnly] = ApiToken::issue('c', ['cdrs:read']);
        $this->withHeaders(['Authorization' => "Bearer {$cdrOnly}"])->getJson('/api/v1/quality')->assertForbidden();
    }
}
