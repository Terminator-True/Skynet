<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface Props {
    status: 'not_connected' | 'connected' | 'reconnect_required';
    email?: string | null;
    scopes?: string[];
    expiresAt?: string | null;
    googleError?: string | null;
}

const props = defineProps<Props>();

const errorMessages: Record<string, string> = {
    denied: 'Google consent was denied. Connect again to retry.',
    state_mismatch: 'Security check failed (state mismatch). Start the connection again.',
    exchange_failed: 'Could not exchange the authorization code with Google. Try again.',
};

const errorMessage = props.googleError !== null && props.googleError in errorMessages
    ? errorMessages[props.googleError]
    : null;

function formatExpiry(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="Connect Google" />
    <div
        class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <main class="flex w-full max-w-2xl flex-col gap-6 pt-10">
            <h1 class="text-2xl font-semibold">Google Connection</h1>

            <p
                v-if="errorMessage"
                class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
                role="alert"
            >
                {{ errorMessage }}
            </p>

            <section
                v-if="status === 'not_connected'"
                class="flex flex-col gap-4 rounded-lg border border-[#e3e3e0] p-6 dark:border-[#3E3E3A]"
            >
                <p class="text-sm">No Google account is connected yet.</p>
                <a
                    href="/auth/google/redirect"
                    class="self-start rounded-lg bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    Connect Google
                </a>
            </section>

            <section
                v-else-if="status === 'connected'"
                class="flex flex-col gap-3 rounded-lg border border-[#e3e3e0] p-6 dark:border-[#3E3E3A]"
            >
                <h2 class="text-sm font-medium">Connected</h2>
                <p class="text-sm">{{ email }}</p>
                <ul class="flex list-inside list-disc flex-col gap-1 text-xs opacity-80">
                    <li v-for="scope in scopes" :key="scope">{{ scope }}</li>
                </ul>
                <p v-if="expiresAt" class="text-xs opacity-80">
                    Token expires: {{ formatExpiry(expiresAt) }}
                </p>
            </section>

            <section
                v-else
                class="flex flex-col gap-4 rounded-lg border border-[#e3e3e0] p-6 dark:border-[#3E3E3A]"
            >
                <p class="text-sm">
                    The Google connection needs to be renewed — the stored access token is no longer
                    usable.
                </p>
                <a
                    href="/auth/google/redirect"
                    class="self-start rounded-lg bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    Reconnect Google
                </a>
            </section>
        </main>
    </div>
</template>
