<?php

namespace App\Jobs;

use App\Services\Freeswitch\EventSocket;
use App\Services\LiveCalls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Hang up a customer call in progress (both legs end). */
class HangupCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $uuid, public ?int $userId = null) {}

    public function handle(EventSocket $esl, LiveCalls $live): void
    {
        $reply = trim($esl->api("uuid_kill {$this->uuid} NORMAL_CLEARING"));
        Log::info('Call hung up from the admin UI', ['uuid' => $this->uuid, 'user_id' => $this->userId, 'reply' => $reply]);
        $live->refresh();
    }
}
