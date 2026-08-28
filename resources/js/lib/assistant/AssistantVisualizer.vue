<script setup lang="ts">
import { useMotion } from '@vueuse/motion';
import { computed, onMounted, ref, watch } from 'vue';
import { mapAssistantState } from './mapAssistantState';
import type { AssistantVisualState } from './types';
import { useCanvasVisualizer } from './useCanvasVisualizer';

const props = withDefaults(
    defineProps<{
        state?: AssistantVisualState;
    }>(),
    {
        state: 'idle',
    },
);

const canvasRef = ref<HTMLCanvasElement | null>(null);
const containerRef = ref<HTMLElement | null>(null);
const ctxUnavailable = ref(false);

const params = computed(() => mapAssistantState(props.state));

const visualizer = useCanvasVisualizer(canvasRef, params);

const STATUS_LABELS: Record<AssistantVisualState, string> = {
    idle: 'Idle',
    processing: 'Processing',
    listening: 'Listening',
    speaking: 'Speaking',
};

const statusText = computed(() => STATUS_LABELS[props.state]);

// Crossfade the container whenever the visual state changes.
const motion = useMotion(containerRef, {
    initial: { opacity: 1, scale: 1 },
    hidden: { opacity: 0 },
    visible: {
        opacity: 1,
        transition: { duration: 250 },
    },
});

watch(
    () => props.state,
    async () => {
        await motion.apply('hidden');
        await motion.apply('visible');
    },
);

onMounted(() => {
    // If Canvas 2D is unavailable, keep the static ring fallback visible.
    ctxUnavailable.value = !canvasRef.value?.getContext('2d');

    if (!ctxUnavailable.value) {
        visualizer.start();
    }
});
</script>

<template>
    <div
        ref="containerRef"
        class="hud-visualizer relative w-40 h-40 sm:w-52 sm:h-52 md:w-64 md:h-64"
        role="img"
        :aria-label="`Assistant status: ${statusText}`"
    >
        <canvas ref="canvasRef" class="h-full w-full" aria-hidden="true" />

        <svg
            v-if="ctxUnavailable"
            class="absolute inset-0 h-full w-full"
            viewBox="0 0 160 160"
            fill="none"
            aria-hidden="true"
        >
            <circle
                cx="80"
                cy="80"
                r="64"
                stroke="var(--color-hud-accent)"
                stroke-opacity="0.35"
                stroke-width="1.5"
            />
            <circle
                cx="80"
                cy="80"
                r="40"
                stroke="var(--color-hud-accent)"
                stroke-opacity="0.25"
                stroke-width="1.5"
            />
            <circle
                cx="80"
                cy="80"
                r="8"
                fill="var(--color-hud-accent)"
                fill-opacity="0.7"
            />
        </svg>

        <span
            class="absolute inset-x-0 -bottom-1 text-center font-mono text-xs text-hud-text-dim"
        >
            {{ statusText }}
        </span>
    </div>
</template>
