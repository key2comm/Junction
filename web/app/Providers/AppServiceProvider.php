<?php

namespace App\Providers;

use App\Services\Freeswitch\EventSocket;
use App\Services\MailSettings;
use App\Services\FirewallSync;
use App\Services\Freeswitch\CarrierAcl;
use App\Services\Freeswitch\GatewaySync;
use App\Services\Freeswitch\PbxSync;
use App\Models\User;
use App\Support\Audit;
use App\Support\DatabaseFunctions;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EventSocket::class, fn () => new EventSocket(
            config('softswitch.esl.host'),
            (int) config('softswitch.esl.port'),
            config('softswitch.esl.password'),
        ));
        $this->app->bind(GatewaySync::class, fn ($app) => new GatewaySync(
            $app->make(EventSocket::class),
            config('softswitch.freeswitch.gateway_dir'),
        ));
        $this->app->bind(CarrierAcl::class, fn ($app) => new CarrierAcl(
            $app->make(EventSocket::class),
            $app->make(FirewallSync::class),
            config('softswitch.freeswitch.acl_dir'),
        ));
        $this->app->bind(PbxSync::class, fn ($app) => new PbxSync(
            $app->make(EventSocket::class),
            config('softswitch.freeswitch.directory_dir'),
            config('softswitch.freeswitch.greeting_dir'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // REST API: 120 requests a minute per token (per IP before authentication).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by(($request->attributes->get('api_token')?->id ? 'token:'.$request->attributes->get('api_token')->id : 'ip:'.$request->ip())));

        // Settings → Audit log: record changes made during admin and portal requests, and sign-ins.
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $data) use ($event) {
                if (Audit::active() && ($data[0] ?? null) instanceof Model) {
                    Audit::model($event, $data[0]);
                }
            });
        }
        Event::listen(Login::class, fn (Login $e) => $e->user instanceof User ? Audit::auth('login', $e->user, request()) : null);
        Event::listen(Logout::class, fn (Logout $e) => $e->user instanceof User ? Audit::auth('logout', $e->user, request()) : null);
        Event::listen(Failed::class, fn (Failed $e) => Audit::auth('login_failed', null, request(), (string) ($e->credentials['email'] ?? '')));

        // PostgreSQL functions in database/sql/ (softswitch_route...): applied after
        // every migrate, including when there was nothing to migrate. Not after a
        // rollback, which may have removed something they use.
        Event::listen([MigrationsEnded::class, NoPendingMigrations::class], function (MigrationsEnded|NoPendingMigrations $e) {
            if ($e->method === 'up') {
                DatabaseFunctions::apply();
            }
        });

        // SMTP settings come from Settings → Email, applied only when mail is sent.
        $this->app->afterResolving('mail.manager', fn () => MailSettings::apply());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
