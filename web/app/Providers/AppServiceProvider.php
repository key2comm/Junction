<?php

namespace App\Providers;

use App\Services\Freeswitch\EventSocket;
use App\Services\Freeswitch\GatewaySync;
use Carbon\CarbonImmutable;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
