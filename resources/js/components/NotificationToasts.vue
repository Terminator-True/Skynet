<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { RealtimeNotification } from '@/echo';

const toasts = ref<RealtimeNotification[]>([]);
const timers = new Map<number, ReturnType<typeof setTimeout>>();
let nextId = 0;

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);

    const timer = timers.get(id);

    if (timer !== undefined) {
        clearTimeout(timer);
        timers.delete(id);
    }
}

function push(payload: RealtimeNotification): void {
    const toast = { ...payload };
    const id = ++nextId;

    toasts.value.push(toast);
    timers.set(
        id,
        setTimeout(() => dismiss(id), 5000),
    );
}

function onReceived(event: Event): void {
    push((event as CustomEvent<RealtimeNotification>).detail);
}

onMounted(() => {
    window.addEventListener('notification:received', onReceived);
});

onBeforeUnmount(() => {
    window.removeEventListener('notification:received', onReceived);

    timers.forEach((timer) => clearTimeout(timer));
    timers.clear();
});
</script>

<template>
    <div
        aria-live="polite"
        class="pointer-events-none fixed top-4 right-4 z-50 flex w-80 flex-col gap-2"
    >
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="pointer-events-auto rounded-lg border border-[#e3e3e0] bg-white p-4 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div class="flex items-start justify-between gap-2">
                <p
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ toast.title }}
                </p>
                <button
                    type="button"
                    aria-label="Dismiss notification"
                    class="text-xs text-[#706f6c] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                    @click="dismiss(toast.id)"
                >
                    ✕
                </button>
            </div>
            <p class="mt-1 text-xs text-[#706f6c] dark:text-[#949490]">
                {{ toast.body }}
            </p>
        </div>
    </div>
</template>
