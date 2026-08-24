import { convertFromMediaStream } from '@timur00kh/whisper.wasm';
import { TARGET_SAMPLE_RATE } from './config';
import type { MicOps } from './types';

let activeStream: MediaStream | null = null;

export const defaultMic: MicOps = {
    async request() {
        if (activeStream) {
            return activeStream;
        }

        activeStream = await navigator.mediaDevices.getUserMedia({
            audio: true,
        });

        return activeStream;
    },
    release() {
        activeStream?.getTracks().forEach((track) => track.stop());

        activeStream = null;
    },
    async capture(stream) {
        // convertFromMediaStream records the current stream window (MediaRecorder
        // under the hood) and returns whisper-ready 16kHz Float32Array audio.
        const { audioData } = await convertFromMediaStream(stream, {
            targetSampleRate: TARGET_SAMPLE_RATE,
            normalize: true,
        });

        return audioData;
    },
    // Streaming chunk capture for wake-word detection. The real 16kHz
    // AudioWorklet/script-processor implementation ships with Slice B; this
    // placeholder keeps the MicOps contract satisfied until then.
    startCapture() {
        return () => {};
    },
};
