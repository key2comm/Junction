<?php

namespace Tests\Feature\Routing;

use App\Jobs\ExtractCallPcap;
use App\Models\Cdr;
use App\Models\User;
use App\Services\PcapExtractor;
use App\Support\Captures;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CallCaptureTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/ss-capture-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A Linux "any" (SLL2) frame: IPv4/UDP carrying $payload. */
    private function frame(string $payload, int $id = 1, int $frag = 0, string $src = "\xC0\x00\x02\x0A"): string
    {
        $udp = pack('nnnn', 5060, 5080, 8 + strlen($payload), 0).$payload;
        $ip = pack('CCnnnCCn', 0x45, 0, 20 + strlen($udp), $id, $frag, 64, 17, 0).$src."\xC0\x00\x02\x01";

        return str_repeat("\0", 20).$ip.$udp;
    }

    private function writeCapture(string $name, array $frames): void
    {
        $out = pack('VvvlVVV', 0xA1B2C3D4, 2, 4, 0, 0, 262144, 276);
        foreach ($frames as [$ts, $frame]) {
            $out .= pack('VVVV', $ts, 0, strlen($frame), strlen($frame)).$frame;
        }
        file_put_contents("{$this->dir}/{$name}", $out);
    }

    /** @return list<string> the packets in a pcap file */
    private function packets(string $bytes): array
    {
        $packets = [];
        for ($p = 24; $p + 16 <= strlen($bytes);) {
            $len = unpack('V', substr($bytes, $p + 8, 4))[1];
            $packets[] = substr($bytes, $p + 16, $len);
            $p += 16 + $len;
        }

        return $packets;
    }

    public function test_a_calls_legs_and_fragments_are_cut_out_of_the_raw_capture(): void
    {
        $cdr = Cdr::factory()->create(['started_at' => '2026-11-10 12:01:00', 'ended_at' => '2026-11-10 12:02:00', 'sip_call_id' => 'abc123@192.0.2.10']);
        $t = strtotime('2026-11-10 12:01:00 UTC');
        $this->writeCapture('sip-20261110120000.pcap', [
            [$t - 60, $this->frame("INVITE sip:1@x SIP/2.0\r\nCall-ID: abc123@192.0.2.10\r\n")],      // before the call: left out
            [$t, $this->frame("INVITE sip:1@x SIP/2.0\r\nCall-ID: abc123@192.0.2.10\r\n")],           // the customer's INVITE
            [$t + 1, $this->frame("INVITE sip:1@carrier SIP/2.0\r\nCall-ID: {$cdr->uuid}-1beef\r\n")], // our carrier leg
            [$t + 1, $this->frame("INVITE sip:2@x SIP/2.0\r\nCall-ID: someone-else\r\n")],            // another call
            [$t + 2, $this->frame("INVITE big SIP/2.0\r\nCall-ID: {$cdr->uuid}-2cafe\r\n", 77, 0x2000)], // first fragment
            [$t + 2, $this->frame('...rest of the SDP...', 77, 0x00B9)],                                 // its second fragment
            [$t + 2, $this->frame('unrelated fragment', 78, 0x00B9)],
        ]);
        $this->writeCapture('sip-20261110120200.pcap', [
            [$t + 90, $this->frame("BYE sip:1@x SIP/2.0\r\nCall-ID: abc123@192.0.2.10\r\n")],
            [$t + 200, $this->frame("BYE sip:1@x SIP/2.0\r\nCall-ID: abc123@192.0.2.10\r\n")], // well after the end: left out
        ]);
        $this->writeCapture('sip-20261110100000.pcap', [[$t, $this->frame("Call-ID: abc123@192.0.2.10\r\n")]]); // hours before: not even read

        $path = (new PcapExtractor($this->dir))->extract($cdr);

        $this->assertSame("pcaps/20261110/{$cdr->uuid}.pcap", $path);
        $this->assertSame($path, $cdr->fresh()->pcap_path);
        $packets = $this->packets(Storage::disk('local')->get($path));
        $this->assertCount(5, $packets);
        $this->assertStringContainsString('INVITE sip:1@x', $packets[0]);
        $this->assertStringContainsString("{$cdr->uuid}-1beef", $packets[1]);
        $this->assertStringContainsString('rest of the SDP', implode('', $packets));
        $this->assertStringNotContainsString('someone-else', implode('', $packets));
        $this->assertStringNotContainsString('unrelated fragment', implode('', $packets));
        $this->assertStringContainsString('BYE', $packets[4]);
    }

    public function test_cdrs_queue_their_capture_and_the_list_links_to_it(): void
    {
        Queue::fake();
        $xml = '<cdr><variables><uuid>'.($uuid = '0b6d7a8e-1111-4222-8333-944445555666').'</uuid><sip_call_id>abc%40192.0.2.10</sip_call_id>'
            .'<start_uepoch>1794312000000000</start_uepoch><end_uepoch>1794312060000000</end_uepoch></variables></cdr>';
        config(['softswitch.internal.password' => 'secret-pw']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['Authorization' => 'Basic '.base64_encode('freeswitch:secret-pw')])
            ->post('/internal/freeswitch/cdr', ['cdr' => $xml])->assertOk();
        $cdr = Cdr::query()->where('uuid', $uuid)->sole();
        $this->assertSame('abc@192.0.2.10', $cdr->sip_call_id);
        Queue::assertPushed(ExtractCallPcap::class, fn ($j) => $j->cdrId === $cdr->id);
    }

    public function test_the_pcap_downloads_from_the_call_list_and_old_ones_are_pruned(): void
    {
        $admin = User::factory()->create();
        $cdr = Cdr::factory()->create(['started_at' => now()->subDays(100), 'sip_call_id' => 'x@y', 'pcap_path' => 'pcaps/'.now()->subDays(100)->format('Ymd').'/a.pcap']);
        $new = Cdr::factory()->create(['started_at' => now()->subHour(), 'sip_call_id' => 'z@y', 'pcap_path' => 'pcaps/'.now()->format('Ymd').'/b.pcap']);
        Storage::disk('local')->put($cdr->pcap_path, 'old');
        Storage::disk('local')->put($new->pcap_path, 'new');

        $this->actingAs($admin)->get(route('cdrs.index', ['from' => now()->subDay()->toIso8601String()]))->assertInertia(fn (Assert $p) => $p
            ->where('cdrs.data.0.has_pcap', true)->missing('cdrs.data.0.sell')->has('summary'));
        $this->actingAs($admin)->get(route('cdrs.show', $new))->assertInertia(fn (Assert $p) => $p->where('cdr.has_pcap', true));
        $this->actingAs($admin)->get(route('cdrs.pcap', $new))->assertOk()->assertHeader('Content-Type', 'application/vnd.tcpdump.pcap');

        Settings::put([Captures::RETENTION => 90]);
        touch("{$this->dir}/sip-20200101000000.pcap", time() - 3 * 86400);
        $result = (new PcapExtractor($this->dir))->prune();
        $this->assertSame(['days' => 1, 'calls' => 1, 'raw' => 1], $result);
        $this->assertNull($cdr->fresh()->pcap_path);
        $this->assertNotNull($new->fresh()->pcap_path);
        $this->actingAs($admin)->get(route('cdrs.pcap', $cdr))->assertNotFound();

        // Settings → Call captures.
        $this->actingAs($admin)->put(route('captures.update'), ['enabled' => '1', 'retention_days' => 30])->assertRedirect(route('captures.edit'));
        $this->assertSame(30, Captures::retentionDays());
        $this->actingAs($admin)->put(route('captures.update'), ['retention_days' => 0])->assertSessionHasErrors('retention_days');
    }
}
