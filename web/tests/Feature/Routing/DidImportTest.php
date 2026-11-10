<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\Endpoint;
use App\Models\PbxDid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DidImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    private Account $carrier;

    private Endpoint $device;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->customer = Account::factory()->create(['is_customer' => true, 'is_vendor' => false, 'code' => 'ACME01']);
        $this->carrier = Account::factory()->vendor()->create(['code' => 'CARRIER1', 'name' => 'Big Carrier']);
        $this->device = Endpoint::create(['kind' => 'device', 'mode' => 'sending', 'account_id' => $this->customer->id, 'name' => 'Office',
            'hosts' => ['office.example.com'], 'codecs' => ['PCMU']]);
    }

    private function upload(string $csv)
    {
        return $this->actingAs($this->admin)->post(route('did-import.upload'), ['file' => UploadedFile::fake()->createWithContent('dids.csv', $csv)]);
    }

    private function import(array $over = [])
    {
        return $this->actingAs($this->admin)->post(route('did-import.store'), [
            'has_header' => true, 'add_one' => true, 'existing' => 'skip',
            'columns' => ['number' => 0, 'customer' => 1, 'provider' => 2, 'device_id' => 3, 'extension' => null, 'language' => 4, 'status' => null, 'status_reason' => null, 'comment' => 5],
            'fallback' => ['language' => 'en', 'status' => 'active'],
            ...$over,
        ]);
    }

    public function test_upload_then_match_columns_then_import(): void
    {
        $this->actingAs($this->admin)->get(route('did-import.show'))->assertInertia(fn (Assert $p) => $p->component('dids/Import')->where('upload', null));
        $this->actingAs($this->admin)->get(route('did-import.sample'))->assertOk()->assertDownload('dids-sample.csv');

        $id = $this->device->id;
        $this->upload("DID,Account code,Carrier,Device ID,Lang,Notes\n4165550100,acme01,Big Carrier,{$id},fr,Main\n+1 416 555 0101,ACME01,CARRIER1,{$id},,\n")
            ->assertRedirect(route('did-import.show'));
        $this->actingAs($this->admin)->get(route('did-import.show'))->assertInertia(fn (Assert $p) => $p
            ->where('upload.filename', 'dids.csv')->where('upload.preview.count', 2)
            ->where('upload.preview.suggested.number', 0)->where('upload.preview.suggested.customer', 1)->where('upload.preview.suggested.provider', 2)
            ->where('upload.preview.suggested.device_id', 3)->where('upload.preview.suggested.language', 4)->where('upload.preview.suggested.comment', 5));

        $this->import()->assertSessionHasNoErrors()->assertRedirect(route('dids.index'));
        $dids = PbxDid::query()->orderBy('number')->get();
        $this->assertSame(['14165550100', '14165550101'], $dids->pluck('number')->all()); // 1 added, punctuation dropped
        $first = $dids[0];
        $this->assertSame([$this->customer->id, $this->carrier->id, ['type' => 'endpoint', 'target' => (string) $id], 'fr', 'active', 'Main'],
            [$first->account_id, $first->provider_account_id, $first->destination, $first->language, $first->status, $first->description]);
        $this->assertSame('en', $dids[1]->language); // the default for empty cells
        $this->assertSame([], Storage::disk('local')->files('did-imports')); // the upload is removed
    }

    public function test_any_bad_row_stops_the_whole_import(): void
    {
        $other = Account::factory()->create(['is_customer' => true, 'code' => 'OTHER']);
        $theirs = Endpoint::create(['kind' => 'device', 'mode' => 'sending', 'account_id' => $other->id, 'name' => 'Theirs', 'hosts' => ['x.example.com'], 'codecs' => ['PCMU']]);
        $id = $this->device->id;
        $this->upload("number,customer,provider,device,language,comment\n14165550100,ACME01,CARRIER1,{$id},en,ok\n123,ACME01,CARRIER1,{$id},en,\n"
            ."14165550102,NOPE,CARRIER1,{$id},en,\n14165550103,ACME01,CARRIER1,{$theirs->id},en,\n14165550104,ACME01,CARRIER1,,en,\n14165550100,ACME01,CARRIER1,{$id},en,dup\n");

        $this->import()->assertSessionHasErrors(['rows.0', 'rows.1', 'rows.2', 'rows.3', 'rows.4']);
        $errors = session('errors')->getBag('default')->all();
        $this->assertStringContainsString('Line 3: 123 is not a valid number', $errors[0]);
        $this->assertStringContainsString('Line 4: no customer with account code NOPE', $errors[1]);
        $this->assertStringContainsString("Line 5: PBX/Device ID {$theirs->id}", $errors[2]);
        $this->assertStringContainsString('Line 6: no destination', $errors[3]);
        $this->assertStringContainsString('Line 7: 14165550100 is also on line 2', $errors[4]);
        $this->assertSame(0, PbxDid::query()->count());
        $this->assertCount(1, Storage::disk('local')->files('did-imports')); // kept, so the mapping can be fixed
    }

    public function test_defaults_fill_missing_columns_and_existing_dids_are_skipped_or_updated(): void
    {
        PbxDid::create(['account_id' => $this->customer->id, 'provider_account_id' => $this->carrier->id, 'number' => '14165550100',
            'destination' => ['type' => 'hangup'], 'description' => 'keep me']);
        $columns = ['number' => 0, 'customer' => null, 'provider' => null, 'device_id' => null, 'extension' => null, 'language' => null, 'status' => null, 'status_reason' => null, 'comment' => null];
        $defaults = ['account_id' => $this->customer->id, 'provider_account_id' => $this->carrier->id, 'device_id' => $this->device->id, 'language' => 'en', 'status' => 'deactivated'];

        $this->upload("14165550100\n14165550199\n");
        $this->import(['has_header' => false, 'columns' => $columns, 'fallback' => $defaults])->assertSessionHasNoErrors();
        $this->assertSame(['type' => 'hangup'], PbxDid::query()->where('number', '14165550100')->sole()->destination); // skipped
        $new = PbxDid::query()->where('number', '14165550199')->sole();
        $this->assertSame(['deactivated', (string) $this->device->id], [$new->status, $new->destination['target']]);

        $this->upload("14165550100\n");
        $this->import(['has_header' => false, 'columns' => $columns, 'fallback' => $defaults, 'existing' => 'update'])->assertSessionHasNoErrors();
        $did = PbxDid::query()->where('number', '14165550100')->sole();
        $this->assertSame([(string) $this->device->id, 'deactivated', 'keep me'], [$did->destination['target'], $did->status, $did->description]);
    }

    public function test_the_import_needs_an_upload_and_a_csv(): void
    {
        $this->import()->assertRedirect(route('did-import.show'));
        $this->actingAs($this->admin)->post(route('did-import.upload'), ['file' => UploadedFile::fake()->create('dids.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
    }
}
