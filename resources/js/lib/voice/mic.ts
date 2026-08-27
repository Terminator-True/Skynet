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
    // Streaming chunk capture for wake-word detection. Uses a 16kHz AudioContext
    // so the raw Float32Array chunks are already whisper-ready. A zero-gain sink
    // keeps the mic feed silent (never audible while always-listening). Returns
    // a cleanup that stops the processor and closes the context.
    startCapture(stream, onChunk) {
        const context = new AudioContext({ sampleRate: TARGET_SAMPLE_RATE });
        const source = context.createMediaStreamSource(stream);
        const processor = context.createScriptProcessor(4096, 1, 1);
        const sink = context.createGain();

        sink.gain.value = 0;

        processor.onaudioprocess = (event) => {
            const channel = event.inputBuffer.getChannelData(0);
            // Copy so the consumer owns the buffer (the processor reuses it).
            onChunk(new Float32Array(channel));
        };

        source.connect(processor);
        processor.connect(sink);
        sink.connect(context.destination);
        void context.resume();

        return () => {
            processor.disconnect();
            source.disconnect();
            sink.disconnect();
            void context.close();
        };
    },
};
