<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\Endpoint;
use App\Models\PbxDid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Routing → DIDs: the "Free" status (no customer yet) and the CSV export. */
class DidExportAndFreeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    private Account $carrier;

    private Endpoint $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->customer = Account::factory()->create(['is_customer' => true, 'is_vendor' => false, 'code' => 'ACME01', 'name' => 'Acme']);
        $this->carrier = Account::factory()->vendor()->create(['code' => 'CARRIER1', 'name' => 'Big Carrier']);
        $this->device = Endpoint::create(['kind' => 'device', 'mode' => 'sending', 'account_id' => $this->customer->id, 'name' => 'Office',
            'hosts' => ['office.example.com'], 'codecs' => ['PCMU']]);
    }

    private function save(array $data, ?PbxDid $did = null)
    {
        $data = ['number' => '14165550100', 'provider_account_id' => $this->carrier->id, 'language' => 'en', ...$data];

        return $did
            ? $this->actingAs($this->admin)->put(route('dids.update', $did), $data)
            : $this->actingAs($this->admin)->post(route('dids.store'), $data);
    }

    public function test_a_free_did_needs_only_a_provider(): void
    {
        $this->save(['status' => 'free'])->assertSessionHasNoErrors()->assertRedirect(route('dids.index'));
        $did = PbxDid::query()->sole();
        $this->assertSame([null, null, 'free'], [$did->account_id, $did->destination, $did->status]);

        // Only the provider is needed: no language either (it keeps the default).
        $this->actingAs($this->admin)->post(route('dids.store'), ['number' => '14165550102', 'provider_account_id' => $this->carrier->id, 'status' => 'free'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['free', 'en'], [PbxDid::where('number', '14165550102')->value('status'), PbxDid::where('number', '14165550102')->value('language')]);

        // "Not assigned" in a CSV still means Free.
        $this->assertSame(['free', 'free', 'free'], [PbxDid::statusFrom('Free'), PbxDid::statusFrom('Not assigned'), PbxDid::statusFrom('unassigned')]);

        // Any other status still needs a customer.
        $this->save(['number' => '14165550101', 'status' => 'active'])->assertSessionHasErrors('account_id');

        // Assigning it later: customer and destination.
        $this->save(['status' => 'active', 'account_id' => $this->customer->id, 'destination' => ['type' => 'endpoint', 'target' => (string) $this->device->id]], $did)
            ->assertSessionHasNoErrors();
        $this->assertSame([$this->customer->id, 'active'], [$did->fresh()->account_id, $did->fresh()->status]);

        // Back in stock: the customer and destination are cleared.
        $this->save(['status' => 'free', 'account_id' => $this->customer->id], $did)->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$did->fresh()->account_id, $did->fresh()->destination]);

        $this->actingAs($this->admin)->get(route('dids.index'))->assertInertia(fn (Assert $p) => $p
            ->where('dids.data.0.account', null)->where('dids.data.0.destination', null)->where('counts.free', 2));
    }

    public function test_calls_to_a_free_did_are_refused(): void
    {
        PbxDid::create(['number' => '14165550100', 'provider_account_id' => $this->carrier->id, 'language' => 'en', 'status' => 'free']);

        // The call path still finds it (so route.lua refuses it as "not found" rather than routing it as a carrier call).
        $row = DB::selectOne("SELECT * FROM softswitch_did('+1 416 555 0100')");
        $this->assertSame(['free', null, false], [$row->status, $row->account_id, $row->account_active]);
    }

    public function test_the_database_keeps_customer_and_status_consistent(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        PbxDid::create(['number' => '14165550100', 'provider_account_id' => $this->carrier->id, 'language' => 'en', 'status' => 'active']);
    }

    public function test_export_lists_every_did_in_the_import_columns(): void
    {
        PbxDid::create(['number' => '14165550100', 'account_id' => $this->customer->id, 'provider_account_id' => $this->carrier->id,
            'destination' => ['type' => 'endpoint', 'target' => (string) $this->device->id], 'language' => 'fr', 'status' => 'banned',
            'status_reason' => 'Unpaid', 'description' => 'Main line']);
        PbxDid::create(['number' => '14165550199', 'provider_account_id' => $this->carrier->id, 'language' => 'en', 'status' => 'free']);

        $response = $this->actingAs($this->admin)->get(route('dids.export'))->assertOk()->assertDownload('dids-'.now()->format('Y-m-d').'.csv');
        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));
        $this->assertSame(['number', 'customer', 'customer_name', 'provider', 'provider_name', 'device_id', 'extension', 'destination',
            'language', 'status', 'status_reason', 'comment', 'created_at', 'updated_at'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['14165550100', 'ACME01', 'Acme', 'CARRIER1', 'Big Carrier', (string) $this->device->id, ''],
            array_slice($rows[1], 0, 7));
        $this->assertSame(['fr', 'banned', 'Unpaid', 'Main line'], array_slice($rows[1], 8, 4));
        $this->assertSame(['14165550199', '', '', 'CARRIER1'], array_slice($rows[2], 0, 4));
        $this->assertSame('free', $rows[2][9]);

        // The list's filters apply.
        $filtered = $this->actingAs($this->admin)->get(route('dids.export', ['status' => 'free']))->streamedContent();
        $this->assertCount(2, array_filter(explode("\n", $filtered)));
    }

    public function test_import_takes_free_dids(): void
    {
        Storage::fake('local');
        $csv = "number,customer,provider,status\n14165550103,,CARRIER1,Not assigned\n14165550104,ACME01,CARRIER1,free\n";
        $this->actingAs($this->admin)->post(route('did-import.upload'), ['file' => UploadedFile::fake()->createWithContent('dids.csv', $csv)]);
        $import = fn () => $this->actingAs($this->admin)->post(route('did-import.store'), [
            'has_header' => true, 'add_one' => true, 'existing' => 'skip',
            'columns' => ['number' => 0, 'customer' => 1, 'provider' => 2, 'device_id' => null, 'extension' => null, 'language' => null, 'status' => 3, 'status_reason' => null, 'comment' => null],
            'fallback' => ['language' => 'en', 'status' => 'active'],
        ]);

        // A Free DID can't name a customer: the whole file is refused.
        $import()->assertSessionHasErrors();
        $this->assertSame(0, PbxDid::query()->count());

        $this->actingAs($this->admin)->post(route('did-import.upload'), ['file' => UploadedFile::fake()->createWithContent('dids.csv', "number,customer,provider,status\n14165550103,,CARRIER1,Free\n")]);
        $import()->assertSessionHasNoErrors();
        $did = PbxDid::query()->sole();
        $this->assertSame([null, null, 'free'], [$did->account_id, $did->destination, $did->status]);
    }
}
