import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Realtime notification payload delivered over the private channel
 * (`NotificationCreated` → `broadcastWith()` → `{ id, title, body, created_at }`).
 */
export interface RealtimeNotification {
    id: number;
    title: string;
    body: string;
    created_at: string | null;
}

declare global {
    interface WindowEventMap {
        'notification:received': CustomEvent<RealtimeNotification>;
    }
}

/**
 * Subscribe the single owner to their private-notifications channel and re-emit
 * received payloads as a DOM `notification:received` event for the toast layer.
 *
 * Reverb speaks the Pusher protocol, so Echo uses the `reverb` connector backed
 * by pusher-js pointed at the LOCAL Reverb server (privacy §8 — no third-party
 * push). The channel name must match `NotificationCreated::broadcastOn()`.
 */
export function initRealtime(userId: number): void {
    const echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY as string,
        Pusher,
        wsHost:
            (import.meta.env.VITE_REVERB_HOST as string) ||
            window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME as string) === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // Echo's `.private()` prepends `private-`, so the UNPREFIXED name is passed
    // here — the wire channel becomes `private-notifications.{userId}`, matching
    // NotificationCreated::broadcastOn() and the auth pattern `notifications.{userId}`.
    echo.private(`notifications.${userId}`).listen(
        'NotificationCreated',
        (payload: RealtimeNotification) => {
            window.dispatchEvent(
                new CustomEvent('notification:received', { detail: payload }),
            );
        },
    );
}
