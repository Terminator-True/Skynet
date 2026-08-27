import type { WakeWordDetector } from './types';

/**
 * Deterministic fake WakeWordDetector for state-machine tests. It exposes
 * scripted `emitWake()`/`emitUtterance(audio)` so tests can drive the
 * listening → wake_detected → capture → reply → listening loop without any real
 * microphone, WASM, or audio hardware. While `suspend()`ed it drops emissions,
 * mirroring the audio-feedback-loop guard.
 */
export interface FakeWakeWordDetector extends WakeWordDetector {
    emitWake(): void;
    emitUtterance(audio?: Float32Array): void;
}

export function createFakeWakeWordDetector(): FakeWakeWordDetector {
    let handlers: {
        onWake(): void;
        onUtterance(audio: Float32Array): void;
    } | null = null;
    let suspended = false;

    return {
        start(_stream, nextHandlers) {
            handlers = nextHandlers;
        },
        stop() {
            handlers = null;
        },
        suspend() {
            suspended = true;
        },
        resume() {
            suspended = false;
        },
        emitWake() {
            if (suspended) {
                return;
            }

            handlers?.onWake();
        },
        emitUtterance(audio = new Float32Array(0)) {
            if (suspended) {
                return;
            }

            handlers?.onUtterance(audio);
        },
    };
}
