<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\Cdr;
use App\Models\User;
use App\Services\CdrPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Call records split by month (migration 000042, CdrPartitions). */
class CdrPartitionsTest extends TestCase
{
    use RefreshDatabase;

    private function partitionOf(string $table, int $id): string
    {
        return (string) DB::scalar("SELECT tableoid::regclass::text FROM {$table} WHERE ".($table === 'cdrs' ? 'id' : 'cdr_id').' = ? LIMIT 1', [$id]);
    }

    private function cdr(CarbonImmutable $at): Cdr
    {
        $cdr = Cdr::factory()->create(['customer_account_id' => Account::factory()->create()->id, 'started_at' => $at, 'answered_at' => $at, 'ended_at' => $at->addMinute(), 'billsec' => 60]);
        $cdr->attemptLog()->create(['attempt' => 1, 'started_at' => $at, 'sip_code' => 503]);
        $cdr->attemptLog()->create(['attempt' => 2, 'started_at' => $at, 'sip_code' => 200]);

        return $cdr;
    }

    public function test_the_months_ahead_exist_and_a_call_and_its_attempts_land_in_their_month(): void
    {
        $now = CarbonImmutable::now('UTC');
        foreach ([0, 1, 2, 3] as $ahead) {
            $suffix = $now->startOfMonth()->addMonths($ahead)->format('\yY\mm');
            $this->assertTrue((bool) DB::scalar('SELECT to_regclass(?) IS NOT NULL', ["cdrs_{$suffix}"]), "cdrs_{$suffix}");
            $this->assertTrue((bool) DB::scalar('SELECT to_regclass(?) IS NOT NULL', ["cdr_attempts_{$suffix}"]), "cdr_attempts_{$suffix}");
        }

        $cdr = $this->cdr($now);
        $this->assertSame('cdrs_'.$now->format('\yY\mm'), $this->partitionOf('cdrs', $cdr->id));
        $this->assertSame('cdr_attempts_'.$now->format('\yY\mm'), $this->partitionOf('cdr_attempts', $cdr->id));
        $this->assertSame(2, $cdr->attemptLog()->count());
    }

    public function test_records_outside_the_months_made_are_kept_and_moved_into_their_month(): void
    {
        $old = CarbonImmutable::parse('2024-03-15 12:00:00', 'UTC');
        $cdr = $this->cdr($old);
        $this->assertSame('cdrs_default', $this->partitionOf('cdrs', $cdr->id));
        $this->assertSame('cdr_attempts_default', $this->partitionOf('cdr_attempts', $cdr->id));
        $before = [Cdr::query()->count(), DB::table('cdr_attempts')->count()];

        $this->assertGreaterThan(0, CdrPartitions::create());

        $this->assertSame('cdrs_y2024m03', $this->partitionOf('cdrs', $cdr->id));
        $this->assertSame('cdr_attempts_y2024m03', $this->partitionOf('cdr_attempts', $cdr->id));
        $this->assertSame($before, [Cdr::query()->count(), DB::table('cdr_attempts')->count()]);
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM cdrs_default'));
        $this->assertSame(2, $cdr->fresh()->attemptLog()->count());
        // Running it again changes nothing.
        $this->assertSame(0, CdrPartitions::create());
    }

    public function test_older_months_are_searched_like_any_other(): void
    {
        $old = CarbonImmutable::parse('2024-03-15 12:00:00', 'UTC');
        $cdr = $this->cdr($old);
        CdrPartitions::create();
        $this->cdr(CarbonImmutable::now('UTC'));

        $this->actingAs(User::factory()->create())->get(route('cdrs.index', ['from' => '2024-03-01T00:00:00Z', 'to' => '2024-04-01T00:00:00Z']))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('cdrs.data', 1)->where('cdrs.data.0.id', $cdr->id));
        $this->actingAs(User::factory()->create())->get(route('cdrs.show', $cdr->id))->assertOk();
    }

    public function test_a_resent_cdr_updates_the_same_record(): void
    {
        $cdr = $this->cdr(CarbonImmutable::now('UTC'));
        $cdr->update(['billsec' => 61]);
        $this->assertSame(1, Cdr::query()->where('uuid', $cdr->uuid)->count());
        $this->assertSame(61, Cdr::query()->whereKey($cdr->id)->value('billsec'));
    }
}
