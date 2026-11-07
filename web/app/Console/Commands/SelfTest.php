<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountIp;
use App\Models\BlockedPrefix;
use App\Models\Cdr;
use App\Models\Gateway;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Services\Freeswitch\EventSocket;
use App\Services\Freeswitch\GatewaySync;
use Carbon\CarbonImmutable;
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
 * and the CDR the app stored. Everything is removed afterwards.
 */
class SelfTest extends Command
{
    public const CUSTOMER = 'SELFTEST-C';

    public const VENDOR = 'SELFTEST-V';

    private const TECH = '9999';          // customer tech prefix

    private const DEST = '9901234567';    // test destination (prefix 990)

    protected $signature = 'softswitch:selftest {--keep : Leave the test data in place afterwards}';

    protected $description = 'Place real test calls through the switch and check routing, failover, limits and billing';

    private CarbonImmutable $runStart;

    private array $f = [];

    public function handle(EventSocket $esl, GatewaySync $gateways): int
    {
        $ip = config('softswitch.public_ip');
        if (! $ip) {
            $this->error('SOFTSWITCH_PUBLIC_IP is not set.');

            return self::FAILURE;
        }
        $this->runStart = CarbonImmutable::now()->subSecond();

        $this->cleanup();
        $results = [];
        try {
            $this->fixtures($ip);
            $gateways->sync();
            $this->waitForGateways($esl);

            $scenarios = [
                'Answered call is rated' => fn () => $this->answered($esl, $ip),
                'Fails over on 503 to the next vendor' => fn () => $this->failover($esl, $ip),
                'Busy is final (no failover)' => fn () => $this->busy($esl, $ip),
                'Unknown customer is refused (403)' => fn () => $this->unknownCustomer($esl, $ip),
                'Blocked destination is refused (403)' => fn () => $this->blocked($esl, $ip),
                'No funds: call refused (402)' => fn () => $this->noFunds($esl, $ip),
                'Call is cut off when funds run out' => fn () => $this->cutOff($esl, $ip),
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
                } catch (Throwable) {
                    // Leftover gateway files are removed by the next sync.
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
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 2, expectAnswer: true);

        $codes = $cdr->attemptLog()->orderBy('attempt')->pluck('sip_code')->all();
        $this->check($codes === [503, 200], 'attempt SIP codes '.json_encode($codes).', expected [503,200]');
        $this->check((int) $cdr->trunk_id === $this->f['trunks']['ok']->id, 'not carried by the second vendor');

        return 'attempts: 503 → 200';
    }

    private function busy(EventSocket $esl, string $ip): string
    {
        $this->routes(['busy', 'ok']); // busy is cheaper than ok
        $cdr = $this->placeCall($esl, $ip, self::TECH.self::DEST, hold: 0, expectAnswer: false);

        $codes = $cdr->attemptLog()->orderBy('attempt')->pluck('sip_code')->all();
        $this->check($codes === [486], 'attempt SIP codes '.json_encode($codes).', expected [486] only');
        $this->check($cdr->billsec === 0 && $cdr->sell_cost === '0.000000', 'an unanswered call was billed');

        return 'customer got busy; next vendor not tried';
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

    // --- helpers ---------------------------------------------------------

    /**
     * Place a call from the fake customer and return the CDR the app stored.
     */
    private function placeCall(EventSocket $esl, string $ip, string $number, int $hold, bool $expectAnswer,
        bool $expectRemoteHangup = false, bool $customer = true): Cdr
    {
        $before = (int) Cdr::query()->max('id');
        $port = (int) config('softswitch.carrier_port');
        $reply = trim($esl->api(
            'originate {origination_caller_id_number=15550001000,originate_timeout=20,ignore_early_media=true,absolute_codec_string=PCMU}'
            ."sofia/selftest/{$number}@{$ip}:{$port} &park",
            30,
        ));

        $answered = str_starts_with($reply, '+OK');
        $this->check($answered === $expectAnswer, $expectAnswer ? "call not answered ({$reply})" : "call was answered ({$reply})");

        if ($answered) {
            $uuid = trim(substr($reply, 3));
            $deadline = microtime(true) + $hold;
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
            $route->update(['enabled' => in_array($name, $enabled, true)]);
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

    private function fixtures(string $ip): void
    {
        // Without model events: this command syncs FreeSWITCH itself, and queued
        // sync jobs would reload gateways in the middle of the test calls.
        Model::withoutEvents(fn () => $this->createFixtures($ip));
    }

    private function createFixtures(string $ip): void
    {
        $deck = function (string $name, string $rate): RateDeck {
            $deck = RateDeck::create(['name' => "SELFTEST {$name}", 'currency' => 'USD']);
            Rate::create(['rate_deck_id' => $deck->id, 'prefix' => '990', 'rate' => $rate, 'connect_fee' => 0,
                'initial_increment' => 6, 'increment' => 6, 'effective_from' => now()->subDay()]);

            return $deck;
        };

        $plan = RoutingPlan::create(['name' => 'SELFTEST plan', 'mode' => 'lcr', 'max_attempts' => 3]);
        $customer = Account::create([
            'name' => 'Self-test customer', 'code' => self::CUSTOMER, 'is_customer' => true, 'status' => 'active',
            'currency' => 'USD', 'billing_type' => 'postpaid', 'credit_limit' => '100', 'timezone' => 'UTC',
            'sell_rate_deck_id' => $deck('sell', '0.06')->id, 'routing_plan_id' => $plan->id,
        ]);
        AccountIp::create(['account_id' => $customer->id, 'ip' => $ip, 'tech_prefix' => self::TECH, 'description' => 'selftest']);
        $vendor = Account::create([
            'name' => 'Self-test vendor', 'code' => self::VENDOR, 'is_vendor' => true, 'status' => 'active',
            'currency' => 'USD', 'billing_type' => 'postpaid', 'credit_limit' => 0, 'timezone' => 'UTC',
        ]);

        // name => [buy rate, tech prefix that tells the fake vendor what to do]
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

        $this->f = compact('customer', 'vendor', 'trunks', 'routes', 'plan');
    }

    private function cleanup(): void
    {
        Model::withoutEvents(fn () => DB::transaction(function () {
            $ids = Account::query()->whereIn('code', [self::CUSTOMER, self::VENDOR])->pluck('id');
            DB::table('active_calls')->whereIn('account_id', $ids)->delete();
            Cdr::query()->whereIn('customer_account_id', $ids)->orWhereIn('vendor_account_id', $ids)->delete();
            if (isset($this->runStart)) {
                // The "unknown customer" scenario's CDR has no account.
                Cdr::query()->whereNull('customer_account_id')->where('callee', self::DEST)
                    ->where('started_at', '>=', $this->runStart)->delete();
            }
            Trunk::query()->whereIn('account_id', $ids)->get()->each->delete();
            Account::query()->whereIn('id', $ids)->get()->each->delete();
            RoutingPlan::query()->where('name', 'SELFTEST plan')->delete();
            RateDeck::query()->where('name', 'like', 'SELFTEST %')->delete();
        }));
    }
}
