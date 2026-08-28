import { describe, expect, it } from 'vitest';
import { mapAssistantState } from '../mapAssistantState';

describe('mapAssistantState', () => {
    it('is importable without a DOM (pure module)', () => {
        // Importing at module scope already exercised this; assert no throw.
        expect(typeof mapAssistantState).toBe('function');
    });

    it('maps processing to load params', () => {
        const params = mapAssistantState('processing');

        expect(params.mode).toBe('processing');
        expect(params.intensity).toBeGreaterThan(0.5);
        expect(params.particleCount).toBeGreaterThan(24);
        expect(params.rotation).toBeGreaterThan(20);
    });

    it('maps idle to slow pulse params', () => {
        const params = mapAssistantState('idle');

        expect(params.mode).toBe('idle');
        expect(params.intensity).toBeLessThanOrEqual(0.5);
        expect(params.pulsePeriodMs).toBeGreaterThan(1000);
    });

    it('returns params for all four states without throwing', () => {
        for (const state of [
            'idle',
            'processing',
            'listening',
            'speaking',
        ] as const) {
            expect(() => mapAssistantState(state)).not.toThrow();
        }
    });

    it('keeps listening/speaking on the idle pulse (placeholder)', () => {
        expect(mapAssistantState('listening').mode).toBe('idle');
        expect(mapAssistantState('speaking').mode).toBe('idle');
    });

    it('yields four distinct color/intensity sets across states', () => {
        const idle = mapAssistantState('idle');
        const processing = mapAssistantState('processing');
        const listening = mapAssistantState('listening');
        const speaking = mapAssistantState('speaking');

        // Ember palette on idle/processing, cyan enters for listening/speaking.
        expect(idle.colors.accent).toBe('#ff7a1a');
        expect(idle.colors.secondary).toBe('#ffc178');
        expect(processing.colors.accent).toBe('#ff7a1a');
        expect(listening.colors.accent).toBe('#5eead4');
        expect(speaking.colors.accent).toBe('#5eead4');
        expect(speaking.colors.secondary).toBe('#ff7a1a');

        // Each state has a distinct intensity to keep the orb visually distinct.
        const intensities = new Set([
            idle.intensity,
            processing.intensity,
            listening.intensity,
            speaking.intensity,
        ]);
        expect(intensities.size).toBe(4);

        // Distinct particle densities back the distinct intensity sets.
        const counts = new Set([
            idle.particleCount,
            processing.particleCount,
            listening.particleCount,
            speaking.particleCount,
        ]);
        expect(counts.size).toBe(4);

        // Listening/speaking still ride the idle pulse mode.
        expect(listening.mode).toBe('idle');
        expect(speaking.mode).toBe('idle');
    });
});
