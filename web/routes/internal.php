<?php

use App\Http\Controllers\Internal\FreeswitchCdrController;
use Illuminate\Support\Facades\Route;

/*
 * Loopback-only callbacks from FreeSWITCH. Served by the internal nginx server
 * on 127.0.0.1:8008 (the public site returns 404 for /internal/*) and guarded
 * by InternalAuth. No session, cookies or CSRF.
 */
Route::post('internal/freeswitch/cdr', FreeswitchCdrController::class)->name('internal.freeswitch.cdr');
