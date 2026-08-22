<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\Rules\AmazonStatusChangeRule;
use App\Notifications\Rules\CalendarEventRule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled sweep (REQ scheduler+queue): one queued job per 15-min tick that
 * resolves the single owner (GoogleToken precedent — first user wins) and runs
 * the proactive rule services.
 *
 * The single job dispatches both rule services — calendar events and Amazon
 * package status changes. Requires `php artisan queue:work` to drain the
 * database queue (QUEUE_CONNECTION=database), alongside `php artisan
 * schedule:work`.
 */
class CheckForNotifications implements ShouldQueue
{
    use Queueable;

    public function handle(
        CalendarEventRule $calendarRule,
        AmazonStatusChangeRule $amazonRule,
    ): void {
        $user = User::query()->first();

        if ($user === null) {
            return;
        }

        $calendarRule->run($user);
        $amazonRule->run($user);
    }
}
