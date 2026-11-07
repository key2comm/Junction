<?php

namespace App\Providers;

use App\Services\Freeswitch\EventSocket;
use App\Services\MailSettings;
use App\Services\Freeswitch\GatewaySync;
use App\Services\Freeswitch\PbxSync;
use Carbon\CarbonImmutable;
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
