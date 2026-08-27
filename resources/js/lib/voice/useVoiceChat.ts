import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { VOICE_LANGUAGE } from './config';
import type {
    ChatPost,
    MicOps,
    VoiceChatState,
    VoiceEngine,
    VoiceMode,
    WakeWordDetector,
} from './types';

const STATUS_LABELS: Record<VoiceChatState, string> = {
    idle: 'Press and hold to talk',
    listening: 'Always listening…',
    recording: 'Recording…',
    wake_detected: 'Wake word detected…',
    transcribing: 'Transcribing…',
    calling: 'Asking the assistant…',
    synthesizing: 'Preparing the reply…',
    playing: 'Playing the reply…',
    error: 'Something went wrong',
    unsupported: 'Voice input is not supported',
};

export interface UseVoiceChat {
    state: Ref<VoiceChatState>;
    status: ComputedRef<string>;
    error: Ref<string | null>;
    transcription: Ref<string>;
    reply: Ref<string | null>;
    progress: Ref<number>;
    mode: Ref<VoiceMode>;
    start: () => Promise<void>;
    stop: () => Promise<void>;
    sendText: (message: string) => Promise<void>;
    startListening: () => Promise<void>;
    stopListening: () => void;
    onWake: () => void;
}

/** Play a synthesized WAV Blob through an <audio> element. */
function defaultPlayAudio(blob: Blob): Promise<void> {
    const url = URL.createObjectURL(blob);
    const audio = new Audio(url);

    return new Promise<void>((resolve, reject) => {
        audio.onended = () => {
            URL.revokeObjectURL(url);
            resolve();
        };
        audio.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Audio playback failed.'));
        };
        void audio.play().catch(() => {
            URL.revokeObjectURL(url);
            reject(new Error('Audio playback failed.'));
        });
    });
}

/**
 * Pure state-machine composable for voice chat. `getEngine`, `chatPost`, `mic`,
 * and `getWakeDetector` are injected so the transitions stay deterministic and
 * testable without real WASM or hardware. `playAudio` defaults to <audio>
 * playback and is injectable so tests can observe the synthesize→play→(idle or
 * listening) transitions. Supports two modes:
 *
 * - `push` (default): push-to-talk `start()`/`stop()`/`recording`, unchanged.
 * - `wake` (always-listening): `startListening()` enters `listening`; a wake
 *   signal moves to `wake_detected`, the captured utterance runs through the
 *   shared transcribe→calling→synthesizing→playing tail, then loops back to
 *   `listening` (not `idle`).
 *
 * The `muted` ref (set while synthesizing/playing) plus `detector.suspend()`
 * form the audio-feedback-loop guard: the assistant never transcribes its own
 * spoken reply.
 */
export function useVoiceChat(
    getEngine: () => VoiceEngine | null,
    chatPost: ChatPost,
    mic: MicOps,
    playAudio: (blob: Blob) => Promise<void> = defaultPlayAudio,
    getWakeDetector: () => WakeWordDetector | null = () => null,
): UseVoiceChat {
    const state = ref<VoiceChatState>('idle');
    const error = ref<string | null>(null);
    const transcription = ref('');
    const reply = ref<string | null>(null);
    const progress = ref(0);
    const micStream = ref<MediaStream | null>(null);
    const mode = ref<VoiceMode>('push');
    const muted = ref(false);
    const wakeDetector = ref<WakeWordDetector | null>(null);

    const status = computed(() => STATUS_LABELS[state.value] ?? state.value);

    function requireEngine(): VoiceEngine {
        const engine = getEngine();

        if (engine === null) {
            throw new Error('Voice engine is not ready.');
        }

        return engine;
    }

    /** Resolve the reply tail back to the mode-appropriate resting state. */
    function finishReply(): void {
        muted.value = false;
        wakeDetector.value?.resume();

        if (mode.value === 'wake' && wakeDetector.value !== null) {
            state.value = 'listening';
        } else {
            state.value = 'idle';
        }
    }

    async function start(): Promise<void> {
        if (state.value === 'recording') {
            return;
        }

        error.value = null;
        transcription.value = '';
        reply.value = null;
        progress.value = 0;

        try {
            requireEngine();

            micStream.value = await mic.request();

            state.value = 'recording';
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Microphone unavailable.';

            state.value = 'error';
        }
    }

    async function stop(): Promise<void> {
        if (state.value !== 'recording') {
            return;
        }

        state.value = 'transcribing';

        try {
            const engine = requireEngine();
            const stream = micStream.value;

            if (stream === null) {
                throw new Error('No active microphone.');
            }

            const audio = await mic.capture(stream);

            mic.release();
            micStream.value = null;

            const text = await engine.transcribe(audio, VOICE_LANGUAGE, (p) => {
                progress.value = p;
            });

            const trimmed = text.trim();

            if (trimmed.length === 0) {
                state.value = 'idle';

                return;
            }

            transcription.value = trimmed;

            await sendText(trimmed);
        } catch (e) {
            mic.release();
            micStream.value = null;
            error.value =
                e instanceof Error ? e.message : 'Transcription failed.';

            state.value = 'error';
        }
    }

    async function sendText(message: string): Promise<void> {
        state.value = 'calling';

        try {
            const replyText = await chatPost(message);

            reply.value = replyText;

            const engine = getEngine();

            // Text fallback / greeting without a voice engine: show the text
            // reply only. When an engine is present, synthesize and speak it.
            if (engine === null) {
                finishReply();

                return;
            }

            // Audio-feedback-loop guard: while we produce and play the reply,
            // drop incoming audio so the own voice is never transcribed.
            muted.value = true;
            wakeDetector.value?.suspend();

            state.value = 'synthesizing';

            const wav = await engine.synthesize(replyText);

            state.value = 'playing';
            await playAudio(wav);

            finishReply();
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Request failed.';

            if (mode.value === 'wake') {
                muted.value = false;
                wakeDetector.value?.resume();
                state.value = 'listening';
            } else {
                state.value = 'error';
            }
        }
    }

    /** Wake signal: listening → wake_detected, then wait for the utterance. */
    function onWake(): void {
        if (muted.value || state.value !== 'listening') {
            return;
        }

        state.value = 'wake_detected';
    }

    /** Detector signals the post-wake utterance is complete. */
    async function onUtterance(audio: Float32Array): Promise<void> {
        if (muted.value || state.value !== 'wake_detected') {
            return;
        }

        await handleUtterance(audio);
    }

    async function handleUtterance(audio: Float32Array): Promise<void> {
        state.value = 'transcribing';

        try {
            const engine = requireEngine();
            const text = await engine.transcribe(audio, VOICE_LANGUAGE, (p) => {
                progress.value = p;
            });

            const trimmed = text.trim();

            if (trimmed.length === 0) {
                if (mode.value === 'wake') {
                    state.value = 'listening';
                } else {
                    state.value = 'idle';
                }

                return;
            }

            transcription.value = trimmed;

            await sendText(trimmed);
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Transcription failed.';

            if (mode.value === 'wake') {
                state.value = 'listening';
            } else {
                state.value = 'error';
            }
        }
    }

    /** Enter always-listening mode and start wake detection. */
    async function startListening(): Promise<void> {
        if (mode.value === 'wake') {
            return;
        }

        try {
            requireEngine();

            const detector = getWakeDetector();

            if (detector === null) {
                throw new Error('Wake detector is not available.');
            }

            const stream = await mic.request();

            micStream.value = stream;
            mode.value = 'wake';
            wakeDetector.value = detector;
            muted.value = false;

            detector.start(stream, { onWake, onUtterance });

            state.value = 'listening';
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Microphone unavailable.';

            state.value = 'error';
        }
    }

    /** Leave always-listening mode, stop detection and release the mic. */
    function stopListening(): void {
        if (mode.value !== 'wake') {
            return;
        }

        wakeDetector.value?.stop();
        wakeDetector.value = null;
        mic.release();
        micStream.value = null;
        mode.value = 'push';
        muted.value = false;
        state.value = 'idle';
    }

    return {
        state,
        status,
        error,
        transcription,
        reply,
        progress,
        mode,
        start,
        stop,
        sendText,
        startListening,
        stopListening,
        onWake,
    };
}
