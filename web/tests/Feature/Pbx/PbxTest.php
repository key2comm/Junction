<?php

namespace Tests\Feature\Pbx;

use App\Jobs\SyncSwitchConfig;
use App\Models\Account;
use App\Models\PbxDid;
use App\Models\PbxExtension;
use App\Models\PbxIvr;
use App\Models\User;
use App\Services\Freeswitch\EventSocket;
use App\Services\Freeswitch\PbxSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * Minimal PBX: admin pages, validation, the routing-side SQL functions,
 * FreeSWITCH directory generation and the web phone pages.
 */
class PbxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->customer = Account::factory()->create(['code' => 'ACME-1']);
    }

    private function extension(array $attrs = []): PbxExtension
    {
        return $this->customer->pbxExtensions()->create([
            'extension' => '1001', 'name' => 'Reception', 'sip_username' => '1001.acme1', 'sip_password' => 'S3cretPassword99',
            'caller_id_number' => '15145550100', 'voicemail_pin' => '4321', 'ring_seconds' => 20, ...$attrs,
        ]);
    }

    /** A minimal valid WAV (8 kHz mono PCM, 0.1 s of silence). */
    private function wav(): string
    {
        $data = str_repeat("\0", 1600);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    public function test_pages_render_for_admins(): void
    {
        $ext = $this->extension();
        $ivr = PbxIvr::create(['account_id' => $this->customer->id, 'extension' => '5000', 'name' => 'Main', 'options' => (object) []]);

        foreach ([
            [route('pbx.index'), 'pbx/Index'],
            [route('accounts.pbx.show', $this->customer), 'pbx/Show'],
            [route('accounts.pbx.extensions.edit', [$this->customer, $ext]), 'pbx/ExtensionEdit'],
            [route('accounts.pbx.ivrs.edit', [$this->customer, $ivr]), 'pbx/IvrEdit'],
            [route('accounts.pbx.phone', $this->customer), 'pbx/Phone'],
        ] as [$url, $component]) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
        }
        $this->get(route('accounts.pbx.show', $this->customer))->assertInertia(fn (Assert $p) => $p
            ->where('extensions.0.sip_password', 'S3cretPassword99')
            ->where('connection.wss', fn ($v) => str_starts_with($v, 'wss://') && str_ends_with($v, '/ws')));

        // Vendors have no PBX.
        $vendor = Account::factory()->vendor()->create(['is_customer' => false]);
        $this->get(route('accounts.pbx.show', $vendor))->assertNotFound();
    }

    public function test_creating_an_extension_generates_credentials_and_syncs_the_switch(): void
    {
        $this->actingAs($this->admin)->post(route('accounts.pbx.extensions.store', $this->customer), [
            'extension' => '1001', 'name' => 'Reception', 'caller_id_number' => '+1 514', 'ring_seconds' => 25,
            'outbound_enabled' => '1', 'voicemail_enabled' => '1', 'enabled' => '1',
        ])->assertSessionHasErrors('caller_id_number');

        $this->post(route('accounts.pbx.extensions.store', $this->customer), [
            'extension' => '1001', 'name' => 'Reception', 'caller_id_number' => '+15145550100', 'ring_seconds' => 25,
            'outbound_enabled' => '1', 'voicemail_enabled' => '1', 'enabled' => '1',
        ])->assertSessionHasNoErrors();

        $ext = PbxExtension::query()->sole();
        $this->assertSame('1001.acme1', $ext->sip_username);
        $this->assertSame('15145550100', $ext->caller_id_number);
        $this->assertGreaterThanOrEqual(20, strlen($ext->sip_password));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $ext->voicemail_pin);
        $this->assertNotSame($ext->sip_password, DB::table('pbx_extensions')->value('sip_password'), 'stored encrypted');
        Queue::assertPushed(SyncSwitchConfig::class);

        // Outside calls need a caller ID; numbers are unique in the account (IVRs included).
        $this->post(route('accounts.pbx.extensions.store', $this->customer), ['extension' => '1002', 'ring_seconds' => 25, 'outbound_enabled' => '1'])
            ->assertSessionHasErrors('caller_id_number');
        $this->post(route('accounts.pbx.extensions.store', $this->customer), ['extension' => '1001', 'ring_seconds' => 25])
            ->assertSessionHasErrors('extension');
        $this->post(route('accounts.pbx.ivrs.store', $this->customer), ['extension' => '1001', 'name' => 'Menu'])
            ->assertSessionHasErrors('extension');
        $this->post(route('accounts.pbx.extensions.store', $this->customer), ['extension' => '0123', 'ring_seconds' => 25])
            ->assertSessionHasErrors('extension');
        $this->post(route('accounts.pbx.extensions.store', $this->customer), ['extension' => '1003', 'ring_seconds' => 25, 'sip_username' => '1001.acme1'])
            ->assertSessionHasErrors('sip_username');
    }

    public function test_updating_keeps_the_password_unless_changed(): void
    {
        $ext = $this->extension();
        $this->actingAs($this->admin)->put(route('accounts.pbx.extensions.update', [$this->customer, $ext]), [
            'extension' => '1001', 'name' => 'Front desk', 'sip_username' => '', 'sip_password' => '', 'caller_id_number' => '15145550100',
            'ring_seconds' => 30, 'outbound_enabled' => '1', 'voicemail_enabled' => '1', 'enabled' => '1',
        ])->assertSessionHasNoErrors();
        $ext->refresh();
        $this->assertSame(['Front desk', '1001.acme1', 'S3cretPassword99', '4321', 30], [$ext->name, $ext->sip_username, $ext->sip_password, $ext->voicemail_pin, $ext->ring_seconds]);

        $this->put(route('accounts.pbx.extensions.update', [$this->customer, $ext]), [
            'extension' => '1001', 'ring_seconds' => 30, 'regenerate_password' => '1', 'enabled' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertNotSame('S3cretPassword99', $ext->fresh()->sip_password);
        $this->assertFalse($ext->fresh()->outbound_enabled);

        // Another account's extension is not reachable through this account.
        $other = Account::factory()->create()->pbxExtensions()->create(['extension' => '2000', 'sip_username' => 'other2000', 'sip_password' => 'x']);
        $this->get(route('accounts.pbx.extensions.edit', [$this->customer, $other]))->assertNotFound();
    }

    public function test_ivrs_get_options_a_default_and_a_greeting(): void
    {
        Storage::fake('local');
        $this->extension();
        $this->customer->pbxExtensions()->create(['extension' => '1002', 'sip_username' => '1002.acme1', 'sip_password' => 'x', 'voicemail_enabled' => false]);
        $this->actingAs($this->admin)->post(route('accounts.pbx.ivrs.store', $this->customer), ['extension' => '5000', 'name' => 'Main menu'])->assertSessionHasNoErrors();
        $ivr = PbxIvr::query()->sole();
        $this->assertSame('{}', DB::table('pbx_ivrs')->value('options'));

        $url = route('accounts.pbx.ivrs.update', [$this->customer, $ivr]);
        $base = ['extension' => '5000', 'name' => 'Main menu', 'timeout_seconds' => 4];
        $this->post($url, [...$base, 'options' => [['key' => '1', 'type' => 'voicemail', 'target' => '1002']]])->assertSessionHasErrors('options.0.type');
        $this->post($url, [...$base, 'options' => [['key' => '12', 'type' => 'number', 'target' => '1001']]])->assertSessionHasErrors('options.0.key');
        $this->post($url, [...$base, 'options' => [['key' => '1', 'type' => 'number', 'target' => '5000']]])->assertSessionHasErrors('options');
        $this->post($url, [...$base, 'greeting' => UploadedFile::fake()->createWithContent('g.wav', 'not audio')])->assertSessionHasErrors('greeting');

        $this->post($url, [
            ...$base,
            'options' => [['key' => '2', 'type' => 'number', 'target' => '1002'], ['key' => '0', 'type' => 'voicemail', 'target' => '1001'], ['key' => '', 'type' => '']],
            'default_destination' => ['type' => 'number', 'target' => '1001'],
            'greeting' => UploadedFile::fake()->createWithContent('hello.wav', $this->wav()),
        ])->assertSessionHasNoErrors();

        $ivr->refresh();
        $this->assertSame(['type' => 'number', 'target' => '1001'], $ivr->default_destination);
        $this->assertTrue(PbxSync::validName($ivr->greeting));
        Storage::disk('local')->assertExists(PbxIvr::GREETING_PATH."/{$ivr->greeting}");
        $this->get(route('accounts.pbx.ivrs.greeting', [$this->customer, $ivr]))->assertOk();

        // What the call path sees (keys "0" and "2" stay strings, sorted).
        $row = DB::selectOne('SELECT * FROM softswitch_pbx_dial(?, ?)', [$this->customer->id, '5000']);
        $this->assertSame('ivr', $row->kind);
        $this->assertSame('0=voicemail:1001;2=number:1002', $row->options);
        $this->assertSame(['number', '1001'], [$row->default_type, $row->default_target]);
        $this->assertSame(4, $row->timeout_seconds);

        // Replacing the greeting removes the old file.
        $old = $ivr->greeting;
        $this->post($url, [...$base, 'greeting' => UploadedFile::fake()->createWithContent('new.wav', $this->wav())])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing(PbxIvr::GREETING_PATH."/{$old}");
        $this->assertSame([], $ivr->fresh()->options);
    }

    public function test_dids_route_to_a_destination_of_their_account(): void
    {
        $this->extension();
        $provider = Account::factory()->vendor()->create();
        $did = fn (array $over = []) => ['number' => '+1 (514) 555-0199', 'account_id' => $this->customer->id, 'provider_account_id' => $provider->id,
            'language' => 'EN', 'status' => 'active', 'destination' => ['type' => 'number', 'target' => '1001'], ...$over];

        $this->actingAs($this->admin)->post(route('dids.store'), $did(['destination' => ['type' => 'number', 'target' => '9999']]))->assertSessionHasErrors('destination.type');
        $this->post(route('dids.store'), $did(['provider_account_id' => null]))->assertSessionHasErrors('provider_account_id');
        $this->post(route('dids.store'), $did(['provider_account_id' => $this->customer->id]))->assertSessionHasErrors('provider_account_id');
        $this->post(route('dids.store'), $did(['language' => 'xx']))->assertSessionHasErrors('language');
        $this->post(route('dids.store'), $did(['status' => 'banned']))->assertSessionHasErrors('status_reason');
        $this->post(route('dids.store'), $did(['description' => 'Main line']))->assertSessionHasNoErrors()->assertRedirect(route('dids.index'));
        $row = PbxDid::query()->sole();
        $this->assertSame(['15145550199', 'en', 'active', 'Main line', $provider->id], [$row->number, $row->language, $row->status, $row->description, $row->provider_account_id]);

        $other = Account::factory()->create();
        $this->post(route('dids.store'), $did(['account_id' => $other->id, 'destination' => ['type' => 'hangup']]))->assertSessionHasErrors('number');

        // Lists and the PBX page.
        $this->get(route('dids.index', ['status' => 'active', 'q' => '555-0199']))->assertInertia(fn (Assert $p) => $p
            ->component('dids/Index')->has('dids.data', 1)->where('dids.data.0.destination', '1001 Reception')->where('counts.active', 1));
        $this->get(route('dids.index', ['status' => 'banned']))->assertInertia(fn (Assert $p) => $p->has('dids.data', 0));
        $this->get(route('dids.edit', $row))->assertInertia(fn (Assert $p) => $p->component('dids/Form')->where('did.number', '15145550199')
            ->where('languages.0.code', 'en')->has('targets.'.$this->customer->id.'.numbers', 1));
        $this->get(route('accounts.pbx.show', $this->customer))->assertInertia(fn (Assert $p) => $p->where('dids.0.status', 'active'));

        // Any format of the number finds it, with its status for the call path.
        $q = fn () => DB::selectOne('SELECT * FROM softswitch_did(?)', ['+15145550199']);
        $this->assertSame([(int) $this->customer->id, '15145550199', 'number', '1001', 'active', 'en', true],
            [(int) $q()->account_id, $q()->number, $q()->dest_type, $q()->dest_target, $q()->status, $q()->language, $q()->account_active]);

        $this->put(route('dids.update', $row), $did(['status' => 'banned', 'status_reason' => 'Unpaid invoice', 'language' => 'fr']))->assertSessionHasNoErrors();
        $this->assertSame(['banned', 'Unpaid invoice', 'fr'], [$q()->status, $row->fresh()->status_reason, $q()->language]);
        // Back to active clears the reason.
        $this->put(route('dids.update', $row), $did(['status' => 'active', 'status_reason' => 'old']))->assertSessionHasNoErrors();
        $this->assertNull($row->fresh()->status_reason);
        $this->customer->update(['status' => 'suspended']);
        $this->assertFalse($q()->account_active);

        $this->delete(route('dids.destroy', $row))->assertRedirect(route('dids.index'));
        $this->assertNull($row->fresh());
    }

    public function test_the_caller_function_identifies_the_phone(): void
    {
        $this->extension(['outbound_enabled' => false]);
        $row = DB::selectOne('SELECT * FROM softswitch_pbx_caller(?)', ['1001.acme1']);
        $this->assertSame([(int) $this->customer->id, '1001', '15145550100', false, true], [(int) $row->account_id, $row->extension, $row->caller_id_number, $row->outbound, $row->account_active]);
        $this->assertNull(DB::selectOne('SELECT * FROM softswitch_pbx_caller(?)', ['nobody']));

        $row = DB::selectOne('SELECT * FROM softswitch_pbx_dial(?, ?)', [$this->customer->id, '1001']);
        $this->assertSame(['extension', '1001.acme1', 20, true], [$row->kind, $row->sip_username, $row->ring_seconds, $row->voicemail]);
        $this->assertNull(DB::selectOne('SELECT * FROM softswitch_pbx_dial(?, ?)', [$this->customer->id + 1, '1001']));
    }

    public function test_pbx_sync_writes_the_directory_and_greetings(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/ss-pbx-'.Str::random(6);
        $greetings = "{$dir}-greetings";
        File::ensureDirectoryExists($dir);
        File::ensureDirectoryExists($greetings);
        file_put_contents("{$dir}/gone.xml", '<include/>');
        file_put_contents("{$greetings}/ivr-1-oldoldold.wav", 'x');

        $ext = $this->extension(['name' => 'R&D <lab>']);
        $this->customer->pbxExtensions()->create(['extension' => '1009', 'sip_username' => 'off1009', 'sip_password' => 'x', 'enabled' => false]);
        $ivr = PbxIvr::create(['account_id' => $this->customer->id, 'extension' => '5000', 'name' => 'Main', 'options' => (object) [], 'greeting' => 'ivr-9-abcdefgh12345678.wav']);
        Storage::disk('local')->put(PbxIvr::GREETING_PATH.'/ivr-9-abcdefgh12345678.wav', $this->wav());

        $esl = Mockery::mock(EventSocket::class);
        $esl->shouldReceive('api')->with('reloadxml')->twice()->andReturn('+OK');
        $esl->shouldReceive('api')->with('global_getvar domain')->andReturn("203.0.113.1\n");
        $esl->shouldReceive('api')->with('sofia profile internal flush_inbound_reg gone@203.0.113.1')->once()->andReturn('+OK');
        $esl->shouldReceive('api')->with('sofia profile internal flush_inbound_reg 1001.acme1@203.0.113.1')->once()->andReturn('+OK');
        $sync = new PbxSync($esl, $dir, $greetings);

        try {
            $r = $sync->sync();
            $this->assertSame(['1001.acme1'], $r['written']);
            $this->assertSame(['gone'], $r['removed']);
            $this->assertTrue($r['reloaded']);
            $xml = file_get_contents("{$dir}/1001.acme1.xml");
            $this->assertStringContainsString('<param name="password" value="S3cretPassword99"/>', $xml);
            $this->assertStringContainsString('<param name="vm-password" value="4321"/>', $xml);
            $this->assertStringContainsString('<variable name="user_context" value="pbx"/>', $xml);
            $this->assertStringContainsString('<variable name="softswitch_pbx_user" value="1001.acme1"/>', $xml);
            $this->assertStringContainsString('value="R&amp;D &lt;lab&gt;"', $xml);
            $this->assertNotFalse(simplexml_load_string($xml));
            $this->assertFileDoesNotExist("{$dir}/off1009.xml");
            $this->assertFileExists("{$greetings}/ivr-9-abcdefgh12345678.wav");
            $this->assertFileDoesNotExist("{$greetings}/ivr-1-oldoldold.wav");

            // Unchanged: nothing reloaded. A name change rewrites without flushing; a password change flushes.
            $this->assertFalse($sync->sync()['reloaded']);
            $ext->update(['sip_password' => 'AnotherPassword42']);
            $this->assertSame(['1001.acme1'], $sync->sync()['written']);
        } finally {
            File::deleteDirectory($dir);
            File::deleteDirectory($greetings);
        }
    }

    public function test_the_portal_web_phone_only_offers_the_users_own_extensions(): void
    {
        $this->extension();
        Account::factory()->create()->pbxExtensions()->create(['extension' => '2000', 'sip_username' => 'other2000', 'sip_password' => 'nope']);
        $user = User::factory()->create(['account_id' => $this->customer->id]);

        $this->actingAs($user)->get(route('portal.phone'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('pbx/Phone')
            ->where('admin', false)
            ->has('extensions', 1)
            ->where('credentials.username', '1001.acme1'));
        $other = PbxExtension::query()->where('sip_username', 'other2000')->value('id');
        $this->get(route('portal.phone', ['extension' => $other]))->assertInertia(fn (Assert $p) => $p->where('credentials.username', '1001.acme1'));
        $this->get(route('accounts.pbx.show', $this->customer))->assertRedirect();
    }
}
