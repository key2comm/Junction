<?php

namespace App\Console\Commands;

use App\Mail\LowBalanceMail;
use App\Models\Account;
use App\Services\MailSettings;
use App\Support\Billing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Low-balance alerts. A customer whose available funds (as the routing engine
 * sees them) fall below their threshold gets one email, with a copy to the
 * admin address; the alert re-arms once funds recover above the threshold.
 */
class CheckLowBalances extends Command
{
    protected $signature = 'softswitch:check-balances';

    protected $description = 'Send low-balance alerts to customers below their threshold';

    public function handle(): int
    {
        if (! Billing::enabled()) {
            $this->info('Billing is turned off: no low-balance alerts.');

            return self::SUCCESS;
        }
        $mail = MailSettings::fresh();
        $alerted = $cleared = 0;

        $accounts = Account::query()->customers()->where('status', 'active')->whereNotNull('low_balance_threshold')
            ->where('code', '!=', SelfTest::CUSTOMER)->get();
        foreach ($accounts as $account) {
            $available = $account->availableFundsNow();
            $below = bccomp($available, (string) $account->low_balance_threshold, 6) < 0;

            if ($below && ! $account->low_balance_alerted_at) {
                // Recorded even without email, so the dashboard shows it and nobody is emailed late in a burst.
                $account->forceFill(['low_balance_alerted_at' => now()])->save();
                $alerted++;
                if ($mail) {
                    $this->send($account, $available);
                }
            } elseif (! $below && $account->low_balance_alerted_at) {
                $account->forceFill(['low_balance_alerted_at' => null])->save();
                $cleared++;
            }
        }

        $this->info("{$alerted} alert(s) raised, {$cleared} cleared".($mail ? '.' : ' (email not configured).'));

        return self::SUCCESS;
    }

    private function send(Account $account, string $available): void
    {
        $to = array_values(array_filter([$account->billing_email]));
        $copy = MailSettings::adminAddress();
        try {
            $message = new LowBalanceMail($account, $available, (string) $account->low_balance_threshold);
            if ($to) {
                $pending = Mail::to($to);
                if ($copy && ! in_array(strtolower($copy), array_map('strtolower', $to), true)) {
                    $pending->bcc($copy);
                }
                $pending->send($message);
            } elseif ($copy) {
                Mail::to($copy)->send($message); // no customer address: at least tell the admin
            }
        } catch (Throwable $e) {
            Log::warning('Low-balance alert email failed', ['account' => $account->code, 'error' => $e->getMessage()]);
        }
    }
}
