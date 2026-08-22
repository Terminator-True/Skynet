import { createInertiaApp } from '@inertiajs/vue3';
import NotificationToasts from '@/components/NotificationToasts.vue';
import { initRealtime } from '@/echo';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    // Fase 5 realtime: register the toast layer and subscribe the single owner to
    // their private-notifications channel once, at app boot (no page reload).
    withApp: (app, { page }) => {
        app.component('NotificationToasts', NotificationToasts);

        const userId = page.props.auth?.user?.id;

        if (userId != null) {
            initRealtime(userId);
        }
    },
});
