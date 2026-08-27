import {
    TARGET_SAMPLE_RATE,
    WAKE_BUFFER_MS,
    WAKE_PHRASES,
    WAKE_SENSITIVITY,
    WAKE_UTTERANCE_MAX_MS,
    WAKE_UTTERANCE_SILENCE_MS,
} from './config';
import type { MicOps, WakeWordDetector } from './types';

/** Lowercase and strip diacritics so matching is accent- and case-insensitive. */
export function normalizePhrase(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
}

/** Root-mean-square energy of a chunk (0..1-ish); near-zero for silence. */
export function rmsEnergy(chunk: Float32Array): number {
    let sum = 0;

    for (let i = 0; i < chunk.length; i += 1) {
        sum += chunk[i] * chunk[i];
    }

    return chunk.length === 0 ? 0 : Math.sqrt(sum / chunk.length);
}

/** True when the normalized text contains any configured wake phrase. */
export function matchesWakePhrase(
    text: string,
    phrases: readonly string[] = WAKE_PHRASES,
): boolean {
    const normalized = normalizePhrase(text);

    return phrases.some((phrase) =>
        normalized.includes(normalizePhrase(phrase)),
    );
}

interface WhisperWakeWordDetectorDeps {
    mic: MicOps;
    /** Runs whisper.wasm over a buffer snapshot, yielding non-empty partials. */
    streamText(audio: Float32Array): AsyncIterable<string>;
}

/**
 * Real wake-word detector. Energy/VAD-gates incoming mic chunks, accumulates a
 * rolling buffer (WAKE_BUFFER_MS), periodically runs local whisper.wasm partials
 * over it, and fires `onWake` on any configured phrase match (accent/case
 * insensitive). After a wake it assembles the following utterance (silence-gap
 * end + max-duration cap) and hands it to `onUtterance`. Fully local, no new dep.
 */
export function createWhisperWakeWordDetector({
    mic,
    streamText,
}: WhisperWakeWordDetectorDeps): WakeWordDetector {
    const bufferMaxSamples = Math.floor(
        (WAKE_BUFFER_MS / 1000) * TARGET_SAMPLE_RATE,
    );
    const silenceMaxSamples = Math.floor(
        (WAKE_UTTERANCE_SILENCE_MS / 1000) * TARGET_SAMPLE_RATE,
    );
    const utteranceMaxSamples = Math.floor(
        (WAKE_UTTERANCE_MAX_MS / 1000) * TARGET_SAMPLE_RATE,
    );
    // Throttle: re-run whisper at most this often while speech streams in.
    const whisperEverySamples = Math.floor(0.5 * TARGET_SAMPLE_RATE);

    let stopCapture: (() => void) | null = null;
    let handlers: {
        onWake(): void;
        onUtterance(audio: Float32Array): void;
    } | null = null;
    let suspended = false;
    let wakeFired = false;
    let whisperRunning = false;

    let buffer: Float32Array[] = [];
    let bufferSamples = 0;
    let utterance: Float32Array[] = [];
    let utteranceSamples = 0;
    let silenceSamples = 0;
    let samplesSinceWhisper = 0;

    function concat(list: Float32Array[], samples: number): Float32Array {
        const out = new Float32Array(samples);
        let offset = 0;

        for (const chunk of list) {
            out.set(chunk, offset);
            offset += chunk.length;
        }

        return out;
    }

    async function runWhisper(): Promise<void> {
        if (whisperRunning || wakeFired || suspended) {
            return;
        }

        whisperRunning = true;

        try {
            const audio = concat(buffer, bufferSamples);

            if (audio.length === 0) {
                return;
            }

            let text = '';

            for await (const partial of streamText(audio)) {
                text += ` ${partial}`;
            }

            if (matchesWakePhrase(text)) {
                wakeFired = true;
                buffer = [];
                bufferSamples = 0;
                handlers?.onWake();
            }
        } finally {
            whisperRunning = false;
        }
    }

    function onChunk(chunk: Float32Array): void {
        if (suspended) {
            return;
        }

        if (wakeFired) {
            // Assembling the post-wake utterance until silence or max duration.
            utterance.push(chunk);
            utteranceSamples += chunk.length;

            if (rmsEnergy(chunk) < WAKE_SENSITIVITY) {
                silenceSamples += chunk.length;
            } else {
                silenceSamples = 0;
            }

            if (
                silenceSamples >= silenceMaxSamples ||
                utteranceSamples >= utteranceMaxSamples
            ) {
                const audio = concat(utterance, utteranceSamples);

                wakeFired = false;
                utterance = [];
                utteranceSamples = 0;
                silenceSamples = 0;
                handlers?.onUtterance(audio);
            }

            return;
        }

        // Wake-detection phase: drop silence, keep a rolling buffer, match.
        if (rmsEnergy(chunk) < WAKE_SENSITIVITY) {
            return;
        }

        buffer.push(chunk);
        bufferSamples += chunk.length;

        while (bufferSamples > bufferMaxSamples && buffer.length > 0) {
            const removed = buffer.shift();

            if (removed !== undefined) {
                bufferSamples -= removed.length;
            }
        }

        samplesSinceWhisper += chunk.length;

        if (samplesSinceWhisper >= whisperEverySamples) {
            samplesSinceWhisper = 0;
            void runWhisper();
        }
    }

    return {
        start(stream, nextHandlers) {
            handlers = nextHandlers;
            suspended = false;
            stopCapture = mic.startCapture(stream, onChunk);
        },
        stop() {
            stopCapture?.();
            stopCapture = null;
            handlers = null;
            wakeFired = false;
            buffer = [];
            bufferSamples = 0;
            utterance = [];
            utteranceSamples = 0;
            silenceSamples = 0;
            samplesSinceWhisper = 0;
        },
        suspend() {
            suspended = true;
        },
        resume() {
            suspended = false;
        },
    };
}
