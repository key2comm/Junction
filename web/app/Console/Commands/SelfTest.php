<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountIp;
use App\Models\BlockedPrefix;
use App\Models\Cdr;
use App\Models\Endpoint;
use App\Models\Gateway;
use App\Models\PbxDid;
use App\Models\PbxExtension;
use App\Models\PbxIvr;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Services\EndpointSync;
use App\Services\Freeswitch\EventSocket;
use App\Services\Freeswitch\GatewaySync;
use App\Services\Freeswitch\PbxSync;
use App\Services\Router;
use App\Support\Billing;
use App\Support\SipWebSocketProbe;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * End-to-end self-test of the live switch, with no external tools.
 *
 * Creates temporary SELFTEST-* accounts, rates, trunks and routes, then has
 * FreeSWITCH place real SIP calls from its loopback-only "selftest" profile
 * (the fake customer) into the carrier profile. They go through the normal
 * routing engine to vendor gateways that point back at the selftest profile
 * (the fake vendor), whose dialplan answers, returns 503 or returns busy
 * depending on the trunk's tech prefix. Each scenario checks the SIP outcome
 * and the CDR the app stored.
 *
 * PBX scenarios: the fake customer account also gets extensions, an IVR and a
 * DID. The selftest profile then acts as a phone (answering the internal
 * profile's digest challenge with the extension's credentials) or as a carrier
 * delivering the DID, and a small WebSocket client registers an extension
 * through nginx (wss://<host>/ws) like the web phone does.
 *
 * Everything is removed afterwards.
 */
class SelfTest extends Command
{
    public const CUSTOMER = 'SELFTEST-C';

    public const VENDOR = 'SELFTEST-V';

    private const TECH = '9999';          // customer tech prefix

    private const DEST = '9901234567';    // test destination (prefix 990)

    private const DID = '99099900011';    // PBX DID of the fake customer (-> IVR 5000)

    // DIDs to PBX/Devices on the fake carrier (its dialplan: 8200... answers, 8486... is busy).
    private const DEVICE_DID = '82000990021';

    private const BUSY_DEVICE_DID = '84860990022';

    private const BANNED_DID = '99099900013';    // banned DID: refused with 403

    // A tenant of the shared PBX: its device's receiving rules.
    private const TENANT_NUMBER = '14165559999';

    private const TENANT_DOMAIN = 'tenant.selftest.invalid';

    protected $signature = 'softswitch:selftest {--keep : Leave the test data in place afterwards}';

    protected $description = 'Place real test calls through the switch and check routing, failover, limits and billing';

    private array $f = [];

    public function handle(EventSocket $esl, GatewaySync $gateways, PbxSync $pbx, EndpointSync $endpointSync): int
    {
        $ip = config('softswitch.public_ip');
        if (! $ip) {
            $this->error('SOFTSWITCH_PUBLIC_IP is not set.');

            return self::FAILURE;
        }
        $this->cleanup();
        $results = [];
        try {
            $this->fixtures($ip);
            $gateways->sync();
            $pbx->sync();
            $endpointSync->sync();
            $this->waitForGateways($esl);
            $this->waitForPbxUsers($esl);

            $scenarios = [
                'Answered call is rated' => fn () => $this->answered($esl, $ip),
                'Fails over on 503 to the next carrier' => fn () => $this->failover($esl, $ip),
                'Busy is final (no failover)' => fn () => $this->busy($esl, $ip),
                'Unknown customer is refused (403)' => fn () => $this->unknownCustomer($esl, $ip),
                'Blocked destination is refused (403)' => fn () => $this->blocked($esl, $ip),
                // Funds checks only apply while the built-in billing is on (Settings → Billing).
                ...(Billing::enabled() ? [
                    'No funds: call refused (402)' => fn () => $this->noFunds($esl, $ip),
                    'Call is cut off when funds run out' => fn () => $this->cutOff($esl, $ip),
                ] : [
                    'Billing off: call with no funds goes through' => fn () => $this->billingOff($esl, $ip),
                ]),
                'PBX: extension registers over WebSocket (wss)' => fn () => $this->pbxWebSocket($esl),
                'PBX: extension calls out through LCR' => fn () => $this->pbxOutbound($esl, $ip),
                'PBX: unanswered extension goes to voicemail' => fn () => $this->pbxVoicemail($esl, $ip),
                'PBX: DID reaches the IVR, key press followed' => fn () => $this->pbxDidIvr($esl, $ip),
                'DID: a banned DID is refused (403)' => fn () => $this->bannedDid($esl, $ip),
                'PBX/Device: DID delivered to a device' => fn () => $this->deviceDid($esl, $ip),
                'PBX/Device: busy device forwards through LCR' => fn () => $this->deviceBusyForward($esl, $ip),
                'Device: call via a shared PBX, matched by caller number' => fn () => $this->deviceByNumber($esl, $ip),
                'Device: call via a shared PBX, matched by FROM domain' => fn () => $this->deviceByDomain($esl, $ip),
                'Device: SIP trunk login calls out through LCR' => fn () => $this->deviceTrunk($esl, $ip),
            ];
            foreach ($scenarios as $name => $scenario) {
                $this->output->write("  {$name} … ");
                try {
                    $detail = $scenario();
                    $results[] = [$name, '<info>PASS</info>', $detail];
                    $this->line('<info>PASS</info>');
                } catch (Throwable $e) {
                    $results[] = [$name, '<error>FAIL</error>', $e->getMessage()];
                    $this->line('<error>FAIL</error>');
                }
            }
        } catch (Throwable $e) {
            $this->error('Self-test could not run: '.$e->getMessage());
            $results[] = ['Setup', '<error>FAIL</error>', $e->getMessage()];
        } finally {
            if (! $this->option('keep')) {
                $this->cleanup();
                try {
                    $gateways->sync();
                    $pbx->sync();
                    $endpointSync->sync();
                } catch (Throwable) {
                    // Leftover gateway and PBX user files are removed by the next sync.
                }
            }
        }

        $this->table(['Scenario', 'Result', 'Detail'], $results);
        $failed = collect($results)->filter(fn ($r) => str_contains($r[1], 'FAIL'))->count();
        $failed ? $this->error("{$failed} scenario(s) failed.") : $this->info('All scenarios passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    // --- scenarios -------------------------------------------------------

    private function answered(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 3, expectAnswer: true);

        $this->check($cdr->billsec >= 2 && $cdr->billsec <= 5, "billsec {$cdr->billsec}, expected about 3");
        $this->check($cdr->attempts === 1, "{$cdr->attempts} attempts, expected 1");
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'carried by the wrong trunk');
        // Sell 0.06/min, 6/6: billsec rounded up to 6s steps.
        $expected = number_format(ceil(max($cdr->billsec, 1) / 6) * 6 * 0.06 / 60, 6, '.', '');
        $this->check($cdr->sell_cost === $expected, "sell cost {$cdr->sell_cost}, expected {$expected}");
        $this->check(bccomp((string) $cdr->margin(), '0', 6) > 0, 'no margin recorded');

        return "{$cdr->billsec}s, sell {$cdr->sell_cost}, buy {$cdr->buy_cost}";
    }

    private function failover(EventSocket $esl, string $ip): string
    {
        $this->routes(['503', 'ok']); // 503 is cheapest, so it is tried first
        $plan = array_map(fn ($a) => $a['gateways'][0]['name'], app(Router::class)->route($this->f['customer']->fresh(), self::DEST)['attempts']);
        $this->check($plan === ['selftest-503', 'selftest-ok'], 'routing engine planned '.json_encode($plan).', expected [selftest-503, selftest-ok]');
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 2, expectAnswer: true);

        $codes = $cdr->attemptLog()->orderBy('attempt')->pluck('sip_code')->all();
        $this->check($codes === [503, 200], 'attempt SIP codes '.json_encode($codes).', expected [503,200]');
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'not carried by the second carrier');

        return 'attempts: 503 → 200';
    }

    private function busy(EventSocket $esl, string $ip): string
    {
        $this->routes(['busy', 'ok']); // busy is cheaper than ok
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 0, expectAnswer: false);

        $codes = $cdr->attemptLog()->orderBy('attempt')->pluck('sip_code')->all();
        $this->check($codes === [486], 'attempt SIP codes '.json_encode($codes).', expected [486] only');
        $this->check($cdr->billsec === 0 && $cdr->sell_cost === '0.000000', 'an unanswered call was billed');

        return 'customer got busy; next carrier not tried';
    }

    private function unknownCustomer(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $cdr = $this->placeCall($esl, $ip, self::DEST, hold: 0, expectAnswer: false, customer: false); // no tech prefix

        $this->check($cdr->hangup_cause === 'unknown_customer', "reason {$cdr->hangup_cause}");
        $this->check($cdr->sip_code === 403, "SIP {$cdr->sip_code}");

        return '403, reason unknown_customer';
    }

    private function blocked(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $block = BlockedPrefix::create(['account_id' => $this->f['customer']->id, 'prefix' => '9901', 'reason' => 'selftest']);
        try {
            $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 0, expectAnswer: false);
        } finally {
            $block->delete();
        }
        $this->check($cdr->hangup_cause === 'blocked_destination', "reason {$cdr->hangup_cause}");
        $this->check($cdr->sip_code === 403, "SIP {$cdr->sip_code}");

        return '403, reason blocked_destination';
    }

    private function noFunds(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        // Less than one 6s increment (0.006) beyond what earlier calls already used.
        $unposted = (string) Cdr::query()->where('customer_account_id', $this->f['customer']->id)
            ->whereNull('posted_at')->sum('sell_cost');
        $this->f['customer']->update(['credit_limit' => bcadd($unposted, '0.001', 6)]);
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 0, expectAnswer: false);

        $this->check($cdr->hangup_cause === 'insufficient_funds', "reason {$cdr->hangup_cause}");
        $this->check($cdr->sip_code === 402, "SIP {$cdr->sip_code}");

        return '402, reason insufficient_funds';
    }

    private function billingOff(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        // Far below zero with no credit: refused if the switch were checking funds.
        DB::table('accounts')->where('id', $this->f['customer']->id)->update(['balance' => '-1000', 'credit_limit' => 0]);
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 3, expectAnswer: true);

        $this->check($cdr->billsec > 0, "billsec {$cdr->billsec}");
        $this->check($cdr->sell_cost !== null, 'not rated');

        return "answered with a balance of -1000, rated {$cdr->sell_cost}";
    }

    private function cutOff(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        // Exactly 12 seconds of credit at 0.06/min (two 6s increments) on top of
        // the earlier scenarios' usage, which isn't posted yet but already counts.
        $unposted = (string) Cdr::query()->where('customer_account_id', $this->f['customer']->id)
            ->whereNull('posted_at')->sum('sell_cost');
        $this->f['customer']->update(['credit_limit' => bcadd($unposted, '0.012', 6)]);
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 25, expectAnswer: true, expectRemoteHangup: true);

        $this->check($cdr->billsec >= 11 && $cdr->billsec <= 13, "billsec {$cdr->billsec}, expected 12");
        $this->check($cdr->sell_cost === '0.012000', "sell cost {$cdr->sell_cost}, expected 0.012000");

        return "hung up by the switch after {$cdr->billsec}s, cost {$cdr->sell_cost}";
    }

    private function pbxWebSocket(EventSocket $esl): string
    {
        $ext = $this->f['extensions']['1001'];
        $client = new SipWebSocketProbe($this->wsHost());
        try {
            $code = $client->register($ext->sip_username, $ext->sip_password, 60);
            $this->check($code === 200, "REGISTER over wss://{$this->wsHost()}/ws got {$code}, expected 200");
            $reg = $esl->api("sofia status profile internal reg {$ext->sip_username}");
            $this->check(str_contains($reg, $ext->sip_username) && str_contains(strtolower($reg), 'transport=ws'), 'FreeSWITCH does not list the WebSocket registration');
            $intruder = new SipWebSocketProbe($this->wsHost());
            $bad = $intruder->register($ext->sip_username, 'wrong-password-123', 60);
            $intruder->close();
            $this->check($bad === 403 || $bad === 401, "a wrong password got {$bad}, expected 401/403");
        } finally {
            $client->register($ext->sip_username, $ext->sip_password, 0); // unregister
            $client->close();
        }

        return "registered via wss://{$this->wsHost()}/ws; wrong password refused";
    }

    private function pbxOutbound(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $cdr = $this->placeCall($esl, $ip, self::DEST, hold: 3, expectAnswer: true, phone: $this->f['extensions']['1001']);

        $this->check($cdr->callee === self::DEST, "the switch saw {$cdr->callee}, expected ".self::DEST);
        $this->check($cdr->direction === 'pbx_out', "direction {$cdr->direction}, expected pbx_out");
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'carried by the wrong trunk');
        $this->check(bccomp((string) $cdr->sell_cost, '0', 6) > 0, 'the call was not billed');

        return "{$cdr->billsec}s via selftest-ok, sell {$cdr->sell_cost}";
    }

    private function pbxVoicemail(EventSocket $esl, string $ip): string
    {
        // 1002 has no phone registered: the PBX answers with its voicemail.
        $cdr = $this->placeCall($esl, $ip, '1002', hold: 2, expectAnswer: true, phone: $this->f['extensions']['1001']);

        $this->check($cdr->callee === '1002', "the switch saw {$cdr->callee}, expected 1002");
        $this->check($cdr->direction === 'pbx_local', "direction {$cdr->direction}, expected pbx_local");
        $this->check($cdr->answered_at !== null && $cdr->sell_cost === null, 'expected an answered, unbilled call');

        return 'answered by voicemail of 1002';
    }

    private function pbxDidIvr(EventSocket $esl, string $ip): string
    {
        // Key 9 hangs up. Without it the IVR would repeat for ~8s first.
        $cdr = $this->placeCall($esl, $ip, self::DID, hold: 5, expectAnswer: true, expectRemoteHangup: true,
            during: function (string $uuid) use ($esl) {
                usleep(1_500_000);
                $esl->api("uuid_send_dtmf {$uuid} 9");
            });

        $this->check($cdr->callee === self::DID, "the switch saw {$cdr->callee}, expected ".self::DID);
        $this->check($cdr->direction === 'pbx_in', "direction {$cdr->direction}, expected pbx_in");
        $this->check($cdr->sell_cost === null, 'an inbound DID call was billed');

        return 'IVR answered the DID and hung up on key 9';
    }

    private function bannedDid(EventSocket $esl, string $ip): string
    {
        $cdr = $this->placeCall($esl, $ip, self::BANNED_DID, hold: 1, expectAnswer: false);

        $this->check($cdr->sip_code === 403, "SIP {$cdr->sip_code}, expected 403");
        $this->check($cdr->hangup_cause === 'did_banned', "reason {$cdr->hangup_cause}, expected did_banned");

        return '403, reason did_banned';
    }

    private function deviceDid(EventSocket $esl, string $ip): string
    {
        $cdr = $this->placeCall($esl, $ip, self::DEVICE_DID, hold: 3, expectAnswer: true);

        $this->check($cdr->direction === 'pbx_in', "direction {$cdr->direction}, expected pbx_in");
        $this->check((int) $cdr->endpoint_id === $this->f['endpoints']['device']->id, 'the CDR does not name the device');
        $this->check($cdr->billsec >= 2, "billsec {$cdr->billsec}, expected about 3");
        $this->check($cdr->sell_cost === null, 'an inbound DID call was billed');

        return "{$cdr->billsec}s, answered by the device";
    }

    private function deviceBusyForward(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $cdr = $this->placeCall($esl, $ip, self::BUSY_DEVICE_DID, hold: 3, expectAnswer: true);

        $this->check($cdr->direction === 'pbx_out', "direction {$cdr->direction}, expected pbx_out (forwarded)");
        $this->check((int) $cdr->endpoint_id === $this->f['endpoints']['busy']->id, 'the CDR does not name the device');
        $this->check($cdr->destination === self::DEST, "forwarded to {$cdr->destination}, expected ".self::DEST);
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'the forward was not carried by the LCR trunk');
        $this->check(bccomp((string) $cdr->sell_cost, '0', 6) > 0, 'the forward was not billed');
        $this->check($cdr->caller === '15550001000', "caller {$cdr->caller}, expected the original caller");

        return "busy → forwarded to ".self::DEST.", sell {$cdr->sell_cost}";
    }

    private function deviceByNumber(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $cdr = $this->placeCall($esl, $ip, self::DEST, hold: 3, expectAnswer: true, caller: self::TENANT_NUMBER);

        $this->check((int) $cdr->endpoint_id === $this->f['endpoints']['office']->id, 'not recognised as the device');
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'not carried by the LCR trunk');
        $this->check(bccomp((string) $cdr->sell_cost, '0', 6) > 0, 'the call was not billed');

        return "caller {$cdr->caller} → device, {$cdr->billsec}s, sell {$cdr->sell_cost}";
    }

    private function deviceByDomain(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $cdr = $this->placeCall($esl, $ip, self::DEST, hold: 3, expectAnswer: true, caller: '100', fromHost: self::TENANT_DOMAIN);

        $this->check((int) $cdr->endpoint_id === $this->f['endpoints']['office']->id, 'not recognised as the device');
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'not carried by the LCR trunk');

        return '100@'.self::TENANT_DOMAIN." → device, {$cdr->billsec}s";
    }

    private function deviceTrunk(EventSocket $esl, string $ip): string
    {
        $this->routes(['ok']);
        $trunk = $this->f['endpoints']['trunk'];
        $cdr = $this->placeCall($esl, $ip, self::DEST, hold: 3, expectAnswer: true, login: [$trunk->sip_username, $trunk->sip_password, '15550002000']);

        $this->check($cdr->direction === 'pbx_out', "direction {$cdr->direction}, expected pbx_out");
        $this->check((int) $cdr->endpoint_id === $trunk->id, 'the CDR does not name the trunk device');
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'not carried by the LCR trunk');
        $this->check(bccomp((string) $cdr->sell_cost, '0', 6) > 0, 'the call was not billed');

        return "authenticated as {$trunk->sip_username}, {$cdr->billsec}s, sell {$cdr->sell_cost}";
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Place a call from the fake customer and return the CDR the app stored.
     */
    private function placeCall(EventSocket $esl, string $ip, string $number, int $hold, bool $expectAnswer,
        bool $expectRemoteHangup = false, bool $customer = true, ?PbxExtension $phone = null, ?Closure $during = null,
        ?string $caller = null, ?string $fromHost = null, ?array $login = null): Cdr
    {
        $before = (int) Cdr::query()->max('id');
        // A SIP login: a PBX phone's, or a device's trunk ([username, password, caller]).
        $login ??= $phone ? [$phone->sip_username, $phone->sip_password, $phone->extension] : null;
        // As a carrier (carrier profile), or with a login (internal profile, digest auth).
        $port = $login ? (int) config('softswitch.pbx_port') : (int) config('softswitch.carrier_port');
        $vars = $login
            ? "origination_caller_id_number={$login[2]},sip_auth_username={$login[0]},sip_auth_password={$login[1]},"
            : 'origination_caller_id_number='.($caller ?? '15550001000').',';
        if ($fromHost) {
            $vars .= "sip_from_host={$fromHost},";
        }
        // Phones address the switch by its hostname (which becomes the digest realm),
        // while the INVITE itself is sent to our IP.
        if ($login) {
            $vars .= "sip_invite_req_uri=sip:{$number}@{$this->wsHost()},sip_invite_to_uri=<sip:{$number}@{$this->wsHost()}>,";
        }
        $reply = trim($esl->api(
            "originate {{$vars}originate_timeout=20,ignore_early_media=true,absolute_codec_string=PCMU}"
            ."sofia/selftest/{$number}@{$ip}:{$port} &park",
            30,
        ));

        $answered = str_starts_with($reply, '+OK');
        $this->check($answered === $expectAnswer, $expectAnswer ? "call not answered ({$reply})" : "call was answered ({$reply})");

        if ($answered) {
            $uuid = trim(substr($reply, 3));
            $deadline = microtime(true) + $hold;
            if ($during) {
                $during($uuid);
            }
            while (microtime(true) < $deadline && str_contains($esl->api("uuid_exists {$uuid}"), 'true')) {
                usleep(250_000);
            }
            $stillUp = str_contains($esl->api("uuid_exists {$uuid}"), 'true');
            if ($expectRemoteHangup) {
                $this->check(! $stillUp, "the switch did not hang up within {$hold}s");
            }
            if ($stillUp) {
                $esl->api("uuid_kill {$uuid}");
            }
        }

        // The CDR arrives a moment after hangup (mod_xml_cdr -> /internal/freeswitch/cdr).
        $query = fn () => Cdr::query()->where('id', '>', $before)
            ->when($customer, fn ($q) => $q->where('customer_account_id', $this->f['customer']->id),
                fn ($q) => $q->whereNull('customer_account_id')->where('callee', $number))
            ->latest('id')->first();
        $deadline = microtime(true) + 15;
        while (! ($cdr = $query()) && microtime(true) < $deadline) {
            usleep(250_000);
        }
        $this->check($cdr !== null, 'no CDR was stored for the call');

        return $cdr;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    /** Enable exactly these trunks' routes and reset the customer's credit. */
    private function routes(array $enabled): void
    {
        foreach ($this->f['routes'] as $name => $route) {
            // (string): PHP turns the array key '503' into the integer 503.
            $route->update(['enabled' => in_array((string) $name, $enabled, true)]);
        }
        $this->f['customer']->update(['credit_limit' => '100']);
        DB::table('active_calls')->where('account_id', $this->f['customer']->id)->delete();
    }

    private function waitForGateways(EventSocket $esl): void
    {
        $deadline = microtime(true) + 15;
        foreach (array_keys($this->f['trunks']) as $name) {
            while (str_contains($esl->api("sofia status gateway selftest-{$name}"), 'Invalid Gateway')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException("gateway selftest-{$name} did not load into FreeSWITCH");
                }
                usleep(500_000);
            }
        }
    }

    private function waitForPbxUsers(EventSocket $esl): void
    {
        $domain = trim($esl->api('global_getvar domain'));
        $deadline = microtime(true) + 15;
        foreach ([...array_values($this->f['extensions']), $this->f['endpoints']['trunk']] as $ext) {
            while (trim($esl->api("user_exists id {$ext->sip_username} {$domain}")) !== 'true') {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException("SIP user {$ext->sip_username} did not load into FreeSWITCH");
                }
                usleep(500_000);
            }
        }
    }

    private function wsHost(): string
    {
        return (string) (config('softswitch.hostname') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    private function fixtures(string $ip): void
    {
        // Without model events: this command syncs FreeSWITCH itself, and queued
        // sync jobs would reload gateways in the middle of the test calls.
        Model::withoutEvents(fn () => $this->createFixtures($ip));
    }

    private function createFixtures(string $ip): void
    {
        $deck = function (string $name, string $rate, string $kind = 'carrier'): RateDeck {
            $deck = RateDeck::create(['name' => "SELFTEST {$name}", 'kind' => $kind, 'currency' => 'USD']);
            Rate::create(['rate_deck_id' => $deck->id, 'prefix' => '990', 'rate' => $rate, 'connect_fee' => 0,
                'initial_increment' => 6, 'increment' => 6, 'effective_from' => now()->subDay()]);

            return $deck;
        };

        $plan = RoutingPlan::create(['name' => 'SELFTEST plan', 'mode' => 'lcr', 'max_attempts' => 3]);
        $customer = Account::create([
            'name' => 'Self-test customer', 'code' => self::CUSTOMER, 'is_customer' => true, 'status' => 'active',
            'currency' => 'USD', 'billing_type' => 'postpaid', 'credit_limit' => '100', 'timezone' => 'UTC',
            'sell_rate_deck_id' => $deck('sell', '0.06', 'tariff')->id, 'routing_plan_id' => $plan->id,
        ]);
        AccountIp::create(['account_id' => $customer->id, 'ip' => $ip, 'tech_prefix' => self::TECH, 'description' => 'selftest']);
        $vendor = Account::create([
            'name' => 'Self-test carrier', 'code' => self::VENDOR, 'is_vendor' => true, 'status' => 'active',
            'currency' => 'USD', 'billing_type' => 'postpaid', 'credit_limit' => 0, 'timezone' => 'UTC',
        ]);

        // name => [buy rate, tech prefix that tells the fake carrier what to do]
        $behaviours = ['503' => ['0.010', '8503'], 'busy' => ['0.015', '8486'], 'ok' => ['0.020', '8200']];
        $trunks = $routes = [];
        foreach ($behaviours as $name => [$rate, $prefix]) {
            $trunk = Trunk::create(['account_id' => $vendor->id, 'name' => "selftest {$name}", 'rate_deck_id' => $deck("buy {$name}", $rate)->id,
                'enabled' => true, 'tech_prefix' => $prefix, 'strip_digits' => 0]);
            Gateway::create(['trunk_id' => $trunk->id, 'name' => "selftest-{$name}", 'host' => $ip,
                'port' => (int) config('softswitch.selftest_port'), 'transport' => 'udp', 'register' => false, 'enabled' => true,
                'priority' => 1, 'weight' => 100]);
            $trunks[$name] = $trunk;
            $routes[$name] = RoutingPlanRoute::create(['routing_plan_id' => $plan->id, 'prefix' => '990', 'trunk_id' => $trunk->id,
                'priority' => 1, 'weight' => 100, 'enabled' => false]);
        }

        // PBX: 1001 places calls, 1002 never registers (voicemail), IVR 5000 behind a DID.
        $extensions = [];
        foreach (['1001', '1002'] as $n) {
            $extensions[$n] = PbxExtension::create([
                'account_id' => $customer->id, 'extension' => $n, 'name' => "Selftest {$n}", 'sip_username' => "selftest-{$n}",
                'sip_password' => PbxExtension::generatePassword(), 'caller_id_number' => '15550001000', 'outbound_enabled' => true,
                'voicemail_enabled' => true, 'voicemail_pin' => '246810', 'ring_seconds' => 5, 'enabled' => true,
            ]);
        }
        PbxIvr::create(['account_id' => $customer->id, 'extension' => '5000', 'name' => 'Selftest IVR', 'timeout_seconds' => 2,
            'options' => (object) ['9' => ['type' => 'hangup'], '1' => ['type' => 'voicemail', 'target' => '1002']]]);
        PbxDid::create(['account_id' => $customer->id, 'provider_account_id' => $vendor->id, 'number' => self::DID, 'destination' => ['type' => 'number', 'target' => '5000']]);
        PbxDid::create(['account_id' => $customer->id, 'provider_account_id' => $vendor->id, 'number' => self::BANNED_DID, 'destination' => ['type' => 'number', 'target' => '5000'],
            'status' => 'banned', 'status_reason' => 'Self-test: unpaid']);

        // PBX/Devices on the fake carrier. Inbound only: calls from this IP must
        // keep being identified by the customer's tech prefix.
        $device = fn (string $name, array $over) => Endpoint::create([
            'account_id' => $customer->id, 'kind' => 'device', 'name' => "Selftest {$name}", 'enabled' => true,
            'hosts' => [$ip], 'transport' => 'udp', 'port' => (int) config('softswitch.selftest_port'),
            'codecs' => ['PCMU', 'PCMA'], 'forward_caller_id' => 'original', ...$over,
        ]);
        $endpoints = [
            'device' => $device('device', ['delivery' => 'sip']),
            'busy' => $device('busy', ['delivery' => 'flow', 'flow' => 'busy', 'forward_number' => self::DEST]),
            // A tenant behind the shared PBX below, recognised by caller number or From domain.
            'office' => $device('office', ['delivery' => 'sip']),
            // An outside PBX on a SIP trunk login.
            'trunk' => $device('trunk', ['delivery' => 'sip', 'hosts' => [], 'sip_username' => 'selftest-trunk', 'sip_password' => Endpoint::generatePassword()]),
        ];
        $endpoints['office']->rules()->create(['type' => 'number', 'value' => self::TENANT_NUMBER]);
        $endpoints['office']->rules()->create(['type' => 'domain', 'value' => self::TENANT_DOMAIN]);
        // The shared PBX: this switch's own IP stands in for it (a trusted source).
        $endpoints['pbx'] = Endpoint::create(['kind' => 'pbx', 'name' => 'Selftest shared PBX', 'enabled' => true, 'hosts' => [$ip],
            'transport' => 'udp', 'port' => (int) config('softswitch.selftest_port'), 'codecs' => ['PCMU']]);
        PbxDid::create(['account_id' => $customer->id, 'number' => self::DEVICE_DID, 'destination' => ['type' => 'endpoint', 'target' => (string) $endpoints['device']->id]]);
        PbxDid::create(['account_id' => $customer->id, 'number' => self::BUSY_DEVICE_DID, 'destination' => ['type' => 'endpoint', 'target' => (string) $endpoints['busy']->id]]);

        $this->f = compact('customer', 'vendor', 'trunks', 'routes', 'plan', 'extensions', 'endpoints');
    }

    private function cleanup(): void
    {
        Model::withoutEvents(fn () => DB::transaction(function () {
            $ids = Account::query()->whereIn('code', [self::CUSTOMER, self::VENDOR])->pluck('id');
            DB::table('active_calls')->whereIn('account_id', $ids)->delete();
            $uuids = Cdr::query()->whereIn('customer_account_id', $ids)->pluck('uuid');
            DB::table('cdr_leg_timings')->whereIn('parent_uuid', $uuids)->delete();
            Cdr::query()->whereIn('customer_account_id', $ids)->orWhereIn('vendor_account_id', $ids)->delete();
            // The "unknown customer" scenario's CDRs have no account: they are the
            // test number, sent from the switch's own IP (no real carrier can be).
            if ($ip = config('softswitch.public_ip')) {
                Cdr::query()->whereNull('customer_account_id')->where('callee', self::DEST)
                    ->where('source_ip', $ip)->delete();
            }
            Trunk::query()->whereIn('account_id', $ids)->get()->each->delete();
            Account::query()->whereIn('id', $ids)->get()->each->delete();
            RoutingPlan::query()->where('name', 'SELFTEST plan')->delete();
            RateDeck::query()->where('name', 'like', 'SELFTEST %')->delete();
            Endpoint::query()->whereNull('account_id')->where('name', 'like', 'Selftest %')->delete(); // the shared PBX
        }));
    }
}
