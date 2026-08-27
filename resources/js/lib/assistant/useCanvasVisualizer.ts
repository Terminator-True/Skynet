import { onBeforeUnmount, watch } from 'vue';
import type { Ref } from 'vue';
import type { AnimationParams } from './types';

export interface CanvasVisualizer {
    /** Start the render loop (respects reduced motion). */
    start: () => void;
    /** Stop the render loop and cancel any scheduled frame. */
    stop: () => void;
    /** Draw a single frame of the current params. */
    drawFrame: () => void;
}

/**
 * Thin Canvas 2D adapter. Owns the backing-canvas resize + visibility
 * lifecycle and renders the current `AnimationParams` on a requestAnimationFrame
 * loop:
 *
 * - ResizeObserver keeps the backing store in sync with the CSS size.
 * - `document.visibilitychange` pauses the loop when the tab is hidden and
 *   restarts it when visible.
 * - `prefers-reduced-motion: reduce` draws one static frame and stops.
 *
 * The adapter intentionally has no Vue reactivity of its own beyond reading
 * `params`; the caller passes a live ref that the component updates.
 */
export function useCanvasVisualizer(
    canvasRef: Ref<HTMLCanvasElement | null>,
    params: Ref<AnimationParams>,
): CanvasVisualizer {
    let rafId: number | null = null;
    let running = false;
    let observer: ResizeObserver | null = null;
    let reducedMotion = false;

    function getCtx(): CanvasRenderingContext2D | null {
        return canvasRef.value?.getContext('2d') ?? null;
    }

    function resize(): void {
        const canvas = canvasRef.value;

        if (!canvas) {
            return;
        }

        const rect = canvas.getBoundingClientRect();

        if (rect.width === 0 || rect.height === 0) {
            return;
        }

        const dpr = window.devicePixelRatio || 1;

        canvas.width = Math.max(1, Math.round(rect.width * dpr));
        canvas.height = Math.max(1, Math.round(rect.height * dpr));

        const ctx = getCtx();

        ctx?.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function drawFrame(): void {
        const ctx = getCtx();
        const canvas = canvasRef.value;

        if (!ctx || !canvas) {
            return;
        }

        const { width, height } = canvas;
        const centerX = width / 2;
        const centerY = height / 2;
        const baseRadius = Math.min(width, height) / 2;
        const p = params.value;

        ctx.clearRect(0, 0, width, height);

        const t = performance.now();

        // Concentric waves expanding from the center.
        ctx.strokeStyle = p.colors.accent;
        ctx.lineWidth = 1.5;
        ctx.globalAlpha = p.intensity;

        for (let i = 0; i < 3; i += 1) {
            const phase = ((t / 1000) * p.waveSpeed + i / 3) % 1;
            const radius = phase * p.waveRadius * baseRadius;

            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
            ctx.stroke();
        }

        // Orbiting particles.
        ctx.fillStyle = p.colors.secondary;
        ctx.globalAlpha = Math.min(1, p.intensity * 0.9);

        for (let i = 0; i < p.particleCount; i += 1) {
            const angle =
                (t / 1000) * p.rotation * (Math.PI / 180) +
                (i / p.particleCount) * Math.PI * 2;
            const radius =
                p.waveRadius * baseRadius * (0.6 + (0.4 * (i % 3)) / 3);

            ctx.beginPath();
            ctx.arc(
                centerX + Math.cos(angle) * radius,
                centerY + Math.sin(angle) * radius,
                1.5,
                0,
                Math.PI * 2,
            );
            ctx.fill();
        }

        // Center pulse dot.
        ctx.globalAlpha = p.intensity;
        const pulse =
            p.mode === 'processing'
                ? 0.5 + 0.5 * Math.sin((t / p.pulsePeriodMs) * Math.PI * 2)
                : 0.7;

        ctx.fillStyle = p.colors.accent;
        ctx.beginPath();
        ctx.arc(
            centerX,
            centerY,
            Math.max(2, baseRadius * 0.08 * pulse),
            0,
            Math.PI * 2,
        );
        ctx.fill();

        ctx.globalAlpha = 1;
    }

    function loop(): void {
        if (!running) {
            return;
        }

        drawFrame();
        rafId = requestAnimationFrame(loop);
    }

    function start(): void {
        if (running) {
            return;
        }

        if (reducedMotion) {
            drawFrame();

            return;
        }

        running = true;
        rafId = requestAnimationFrame(loop);
    }

    function stop(): void {
        running = false;

        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
    }

    function onVisibilityChange(): void {
        if (document.hidden) {
            stop();
        } else {
            start();
        }
    }

    // Track reduced-motion preference.
    const motionQuery = window.matchMedia?.('(prefers-reduced-motion: reduce)');

    if (motionQuery) {
        reducedMotion = motionQuery.matches;
        motionQuery.addEventListener?.('change', (event) => {
            reducedMotion = event.matches;

            if (reducedMotion) {
                stop();
                drawFrame();
            } else {
                start();
            }
        });
    }

    // Resize observer keeps the backing store in sync.
    observer = new ResizeObserver(() => {
        resize();
        drawFrame();
    });

    if (canvasRef.value) {
        observer.observe(canvasRef.value);
    }

    watch(canvasRef, (canvas, previous) => {
        if (previous) {
            observer?.unobserve(previous);
        }

        if (canvas) {
            resize();
            observer?.observe(canvas);
        }
    });

    document.addEventListener('visibilitychange', onVisibilityChange);

    onBeforeUnmount(() => {
        stop();
        observer?.disconnect();
        motionQuery?.removeEventListener?.('change', () => {});
        document.removeEventListener('visibilitychange', onVisibilityChange);
    });

    return { start, stop, drawFrame };
}
