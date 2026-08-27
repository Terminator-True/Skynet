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
});
