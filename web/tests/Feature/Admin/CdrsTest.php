<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\CdrController;
use App\Models\Account;
use App\Models\Cdr;
use App\Models\Trunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CdrsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    private Account $vendor;

    private Trunk $trunk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->customer = Account::factory()->create(['code' => 'CUST']);
        $this->vendor = Account::factory()->vendor()->create(['code' => 'VEND']);
        $this->trunk = Trunk::factory()->for($this->vendor, 'account')->create(['name' => 'Main']);
    }

    private function cdr(array $attrs = []): Cdr
    {
        return Cdr::factory()->create([
            'uuid' => (string) Str::uuid(),
            'customer_account_id' => $this->customer->id,
            'callee' => '442079460000',
            'destination' => '442079460000',
            'started_at' => now()->subHour(),
            ...$attrs,
        ]);
    }

    private function answered(array $attrs = []): Cdr
    {
        return $this->cdr([
            'answered_at' => now()->subHour()->addSeconds(5), 'billsec' => 120, 'attempts' => 1, 'sip_code' => 200,
            'sell_cost' => '0.100000', 'buy_cost' => '0.060000', 'vendor_account_id' => $this->vendor->id, 'trunk_id' => $this->trunk->id,
            ...$attrs,
        ]);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('cdrs.index'))->assertRedirect(route('login'));
        $this->get(route('cdrs.download'))->assertRedirect(route('login'));
    }

    public function test_list_and_summary(): void
    {
        $this->answered();
        $this->answered(['billsec' => 60, 'sell_cost' => '0.050000', 'buy_cost' => '0.030000']);
        $this->cdr(['attempts' => 2, 'sip_code' => 503, 'hangup_cause' => 'NORMAL_TEMPORARY_FAILURE']); // failed
        $this->cdr(['attempts' => 0, 'sip_code' => 402, 'hangup_cause' => 'insufficient_funds']);      // rejected
        $this->answered(['started_at' => now()->subDays(3)]);                                           // outside default 24h

        $this->actingAs($this->admin)->get(route('cdrs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('cdrs/Index')
                ->has('cdrs.data', 4)
                ->where('summary.calls', 4)
                ->where('summary.answered', 2)
                ->where('summary.rejected', 1)
                ->where('summary.asr', 66.7)        // 2 answered of 3 routed
                ->where('summary.acd', 90)          // (120 + 60) / 2
                ->where('summary.minutes', 3)
                ->where('summary.sell', '0.150000')
                ->where('summary.margin', '0.060000')
                ->where('cdrs.data.0.customer', 'CUST'));
    }

    public function test_filters(): void
    {
        $this->answered(['destination' => '447700900123', 'caller' => '15145550100']);
        $this->cdr(['attempts' => 1, 'sip_code' => 486, 'hangup_cause' => 'USER_BUSY']);
        $this->cdr(['attempts' => 0, 'sip_code' => 403, 'hangup_cause' => 'blocked_destination']);
        $other = Account::factory()->create(['code' => 'OTHER']);
        $this->answered(['customer_account_id' => $other->id]);

        $count = fn (array $q) => $this->actingAs($this->admin)->get(route('cdrs.index', $q))->viewData('page')['props']['summary']['calls'];

        $this->assertSame(4, $count([]));
        $this->assertSame(3, $count(['customer' => $this->customer->id]));
        $this->assertSame(2, $count(['vendor' => $this->vendor->id]));
        $this->assertSame(2, $count(['status' => 'answered']));
        $this->assertSame(1, $count(['status' => 'failed']));
        $this->assertSame(1, $count(['status' => 'rejected']));
        $this->assertSame(1, $count(['number' => '+44 7700']));
        $this->assertSame(1, $count(['caller' => '5145550100']));
        $this->assertSame(1, $count(['sip' => 486]));
        $this->assertSame(1, $count(['reason' => 'blocked_destination']));
        $this->assertSame(0, $count(['from' => now()->subMinutes(10)->toIso8601String()]));
    }

    public function test_range_is_validated(): void
    {
        $this->actingAs($this->admin)->get(route('cdrs.index', ['from' => now()->subDays(40)->toIso8601String()]))->assertSessionHasErrors('from');
        $this->actingAs($this->admin)->get(route('cdrs.index', ['from' => now()->toIso8601String(), 'to' => now()->subDay()->toIso8601String()]))->assertSessionHasErrors('from');
        $this->actingAs($this->admin)->get(route('cdrs.index', ['number' => "44'; drop"]))->assertSessionHasErrors('number');
    }

    public function test_detail_shows_every_attempt(): void
    {
        $cdr = $this->answered(['attempts' => 2]);
        $cdr->attemptLog()->create(['attempt' => 1, 'trunk_id' => $this->trunk->id, 'started_at' => now(), 'sip_code' => 503, 'hangup_cause' => 'NORMAL_TEMPORARY_FAILURE']);
        $cdr->attemptLog()->create(['attempt' => 2, 'trunk_id' => $this->trunk->id, 'started_at' => now(), 'answered_at' => now(), 'sip_code' => 200]);

        $this->actingAs($this->admin)->get(route('cdrs.show', $cdr))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('cdrs/Show')
                ->where('cdr.margin', '0.040000')
                ->where('cdr.vendor.code', 'VEND')
                ->has('attempts', 2)
                ->where('attempts.0.sip_code', 503)
                ->where('attempts.1.answered', true)
                ->where('attempts.0.trunk', 'VEND / Main'));

        $rejected = $this->cdr(['attempts' => 0, 'hangup_cause' => 'insufficient_funds']);
        $this->actingAs($this->admin)->get(route('cdrs.show', $rejected))
            ->assertInertia(fn (Assert $p) => $p->where('cdr.reason', 'Not enough funds for the minimum billable duration.'));
    }

    public function test_csv_export_uses_the_filters_and_neutralises_formulas(): void
    {
        $this->answered(['caller' => '=HYPERLINK("http://evil")', 'destination' => '442079460000']);
        $this->answered(['caller' => '+15145550100']);
        $this->answered(['started_at' => now()->subDays(3)]); // outside range

        $response = $this->actingAs($this->admin)->get(route('cdrs.download'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(3, $lines); // header + 2 calls
        $this->assertStringStartsWith('started_at_utc,', $lines[0]);
        $csv = implode("\n", $lines);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(',+15145550100,', $csv); // phone numbers are left alone
        $this->assertStringContainsString(',0.040000,', $csv);     // margin column
    }

    public function test_cell_escaping(): void
    {
        $this->assertSame("'=1+1", CdrController::cell('=1+1'));
        $this->assertSame("'@SUM(A1)", CdrController::cell('@SUM(A1)'));
        $this->assertSame("'-2+3", CdrController::cell('-2+3'));
        $this->assertSame('-0.500000', CdrController::cell('-0.500000'));
        $this->assertSame('+447700900123', CdrController::cell('+447700900123'));
        $this->assertSame(42, CdrController::cell(42));
    }
}
