import { describe, expect, it } from 'vitest';
import {
    matchesWakePhrase,
    normalizePhrase,
    rmsEnergy,
} from '../whisperWakeWordDetector';

describe('whisperWakeWordDetector', () => {
    it('normalizes case and strips accents', () => {
        expect(normalizePhrase('HOLA SKYNET')).toBe('hola skynet');
        expect(normalizePhrase('Áéíóú')).toBe('aeiou');
    });

    it('matches configured phrases case/accent-insensitively', () => {
        expect(matchesWakePhrase('oye skynet', ['Oye Skynet'])).toBe(true);
        expect(matchesWakePhrase('hola skynet', ['Hola Skynet'])).toBe(true);
        expect(matchesWakePhrase('hola mundo', ['Oye Skynet'])).toBe(false);
    });

    it('matches a custom phrase and ignores the defaults', () => {
        expect(matchesWakePhrase('hola mundo', ['Hola Mundo'])).toBe(true);
        expect(matchesWakePhrase('oye skynet', ['Hola Mundo'])).toBe(false);
    });

    it('matches when the phrase appears inside a longer transcript', () => {
        expect(
            matchesWakePhrase('sí, oye skynet, atiende', ['Oye Skynet']),
        ).toBe(true);
    });

    it('energy gate: silence has near-zero RMS, speech has higher energy', () => {
        expect(rmsEnergy(new Float32Array(1600))).toBe(0);

        const loud = new Float32Array(1600).fill(0.5);

        expect(rmsEnergy(loud)).toBeGreaterThan(0.4);
    });
});
