<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CheckForNotifications;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    // Fase 5 proactive notifications (Roadmap §12): dispatch the sweep job
    // every polling_interval_minutes. Laravel 13 has no Console Kernel — the
    // schedule lives here. Dev runtime requires BOTH processes for delivery:
    //   php artisan schedule:work   (scheduler dispatcher)
    //   php artisan queue:work      (drains the database queue)
    // Plus Reverb for realtime push: `php artisan reverb:start` needs the
    // `websockets` PECL extension (Reverb 1.x) — see config/reverb.php. Without
    // it the server won't boot, but offline tests (Event::fake) stay green.
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new CheckForNotifications)
            ->everyFifteenMinutes();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
