import type { AnimationParams, AssistantVisualState } from './types';

/** Slow, calm ember pulse for idle. */
const IDLE_PARAMS: AnimationParams = {
    mode: 'idle',
    rotation: 8,
    particleSpeed: 0.15,
    intensity: 0.35,
    waveRadius: 0.55,
    waveSpeed: 0.2,
    pulsePeriodMs: 2600,
    particleCount: 18,
    colors: {
        accent: '#ff7a1a',
        secondary: '#ffc178',
    },
};

/** Faster, brighter ember "load" pattern while the assistant is working. */
const PROCESSING_PARAMS: AnimationParams = {
    mode: 'processing',
    rotation: 90,
    particleSpeed: 0.8,
    intensity: 1,
    waveRadius: 0.9,
    waveSpeed: 0.9,
    pulsePeriodMs: 600,
    particleCount: 42,
    colors: {
        accent: '#ff7a1a',
        secondary: '#ffc178',
    },
};

/** Cyan listening pulse — rides the idle mode so no draw-pattern change. */
const LISTENING_PARAMS: AnimationParams = {
    mode: 'idle',
    rotation: 20,
    particleSpeed: 0.3,
    intensity: 0.6,
    waveRadius: 0.7,
    waveSpeed: 0.4,
    pulsePeriodMs: 1800,
    particleCount: 26,
    colors: {
        accent: '#5eead4',
        secondary: '#1c3d38',
    },
};

/** Mixed ember+cyan speaking pulse — also rides the idle mode. */
const SPEAKING_PARAMS: AnimationParams = {
    mode: 'idle',
    rotation: 40,
    particleSpeed: 0.5,
    intensity: 0.8,
    waveRadius: 0.8,
    waveSpeed: 0.6,
    pulsePeriodMs: 1200,
    particleCount: 34,
    colors: {
        accent: '#5eead4',
        secondary: '#ff7a1a',
    },
};

/**
 * Pure mapping from a visual state to animation parameters. No DOM or canvas
 * access, so it is unit-testable in happy-dom.
 *
 * Each of the four states carries its own ember/cyan parameter set; only the
 * colors/intensity/count/rotation differ, so `mode` stays 'idle' for the
 * voice-driven states (no useCanvasVisualizer change required).
 */
export function mapAssistantState(
    state: AssistantVisualState,
): AnimationParams {
    switch (state) {
        case 'processing':
            return PROCESSING_PARAMS;
        case 'listening':
            return LISTENING_PARAMS;
        case 'speaking':
            return SPEAKING_PARAMS;
        case 'idle':
        default:
            return IDLE_PARAMS;
    }
}
