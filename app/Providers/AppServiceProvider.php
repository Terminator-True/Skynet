<?php

namespace App\Providers;

use App\Services\Gmail\ApiclientGmailMessagesReader;
use App\Services\Gmail\GmailMessagesReader;
use App\Services\Google\ApiclientCalendarEventsReader;
use App\Services\Google\ApiclientGoogleOAuthClient;
use App\Services\Google\CalendarEventsReader;
use App\Services\Google\GoogleOAuthClient;
use App\Tools\BuscarCorreos;
use App\Tools\Dummy\CalculateSum;
use App\Tools\Dummy\GetCurrentTime;
use App\Tools\Dummy\GetWeatherMock;
use App\Tools\LeerCorreo;
use App\Tools\ListarEventosCalendario;
use App\Tools\ToolRegistry;
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
        $this->app->singleton(ToolRegistry::class, function (): ToolRegistry {
            $registry = new ToolRegistry;
            $registry->register(new GetCurrentTime);
            $registry->register(new CalculateSum);
            $registry->register(new GetWeatherMock);
            $registry->register($this->app->make(ListarEventosCalendario::class));
            $registry->register($this->app->make(BuscarCorreos::class));
            $registry->register($this->app->make(LeerCorreo::class));

            return $registry;
        });

        // Vendor seam: tests bind fakes here (Http::fake cannot intercept
        // google/apiclient's internal Guzzle).
        $this->app->bind(GoogleOAuthClient::class, ApiclientGoogleOAuthClient::class);

        // Vendor seam: tests bind fakes here (apiclient Guzzle bypasses Http::fake).
        $this->app->bind(CalendarEventsReader::class, ApiclientCalendarEventsReader::class);
        $this->app->bind(GmailMessagesReader::class, ApiclientGmailMessagesReader::class);
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
