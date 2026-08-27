import type { AnimationParams, AssistantVisualState } from './types';

/** Slow, calm pulse for idle (and, for now, listening/speaking). */
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
        accent: '#22d3ee',
        secondary: '#38bdf8',
    },
};

/** Faster, brighter "load" pattern while the assistant is working. */
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
        accent: '#22d3ee',
        secondary: '#38bdf8',
    },
};

/**
 * Pure mapping from a visual state to animation parameters. No DOM or canvas
 * access, so it is unit-testable in happy-dom.
 *
 * MVP renders idle/processing distinctly; listening/speaking fall back to the
 * idle pulse until the voice states carry their own motion (Slice B).
 */
export function mapAssistantState(
    state: AssistantVisualState,
): AnimationParams {
    switch (state) {
        case 'processing':
            return PROCESSING_PARAMS;
        case 'idle':
        case 'listening':
        case 'speaking':
        default:
            return IDLE_PARAMS;
    }
}
