<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proactive notification polling window (minutes)
    |--------------------------------------------------------------------------
    |
    | Fase 5 (Roadmap §12): the scheduler dispatches CheckForNotifications
    | every polling_interval_minutes. The job lands on the database queue
    | (QUEUE_CONNECTION=database) and is drained by a separate queue:work
    | worker. Requires both `php artisan schedule:work` AND `php artisan
    | queue:work` running for delivery.
    */
    'polling_interval_minutes' => (int) env('NOTIFICATIONS_POLLING_INTERVAL_MINUTES', 15),

];
