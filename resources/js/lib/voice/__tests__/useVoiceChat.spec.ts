import { describe, expect, it, vi } from 'vitest';
import { createFakeEngine } from '../FakeVoiceEngine';
import { createFakeWakeWordDetector } from '../FakeWakeWordDetector';
import type { ChatPost, MicOps } from '../types';
import { useVoiceChat } from '../useVoiceChat';

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });

    return { promise, resolve };
}

function fakeMic(denied = false): MicOps {
    return {
        async request() {
            if (denied) {
                throw new Error('Permission denied');
            }

            return {} as MediaStream;
        },
        release() {},
        async capture() {
            return new Float32Array(16000);
        },
        startCapture() {
            return () => {};
        },
    };
}

function fakeChatPost(): { chatPost: ChatPost; calls: string[] } {
    const calls: string[] = [];

    return {
        calls,
        async chatPost(message) {
            calls.push(message);

            return `reply to "${message}"`;
        },
    };
}

describe('useVoiceChat', () => {
    it('walks recording→transcribing→calling→synthesizing→playing→idle', async () => {
        const transcribe = deferred<string>();
        const chat = deferred<string>();
        const synthesize = deferred<Blob>();
        const engine = createFakeEngine({
            transcribe: () => transcribe.promise,
            synthesize: () => synthesize.promise,
        });
        const chatPost = vi.fn(() => chat.promise);
        const play = vi.fn(async () => {});
        const voice = useVoiceChat(() => engine, chatPost, fakeMic(), play);

        await voice.start();
        expect(voice.state.value).toBe('recording');

        const stopping = voice.stop();
        await vi.waitFor(() => expect(voice.state.value).toBe('transcribing'));

        transcribe.resolve('hola mundo');
        await vi.waitFor(() => expect(voice.state.value).toBe('calling'));

        chat.resolve('la respuesta');
        await vi.waitFor(() => expect(voice.state.value).toBe('synthesizing'));

        synthesize.resolve(new Blob(['wav'], { type: 'audio/wav' }));
        await stopping;

        expect(voice.state.value).toBe('idle');
        expect(voice.transcription.value).toBe('hola mundo');
        expect(voice.reply.value).toBe('la respuesta');
        expect(chatPost).toHaveBeenCalledWith('hola mundo');
        expect(play).toHaveBeenCalledTimes(1);
    });

    it('sends a greeting chip through chat and speaks the reply', async () => {
        const chat = deferred<string>();
        const synthesize = deferred<Blob>();
        const engine = createFakeEngine({
            synthesize: () => synthesize.promise,
        });
        const chatSpy = vi.fn(() => chat.promise);
        const play = vi.fn(async () => {});
        const voice = useVoiceChat(() => engine, chatSpy, fakeMic(), play);

        const sending = voice.sendText('Hola');
        await vi.waitFor(() => expect(voice.state.value).toBe('calling'));

        expect(chatSpy).toHaveBeenCalledWith('Hola');

        chat.resolve('¡Hola!');
        await vi.waitFor(() => expect(voice.state.value).toBe('synthesizing'));

        synthesize.resolve(new Blob(['wav'], { type: 'audio/wav' }));
        await sending;

        expect(voice.state.value).toBe('idle');
        expect(voice.reply.value).toBe('¡Hola!');
        expect(play).toHaveBeenCalledTimes(1);
    });

    it('falls back to a text reply when no voice engine is available', async () => {
        const { chatPost, calls } = fakeChatPost();
        const voice = useVoiceChat(() => null, chatPost, fakeMic());

        await voice.sendText('¿Qué tengo hoy?');

        expect(calls).toEqual(['¿Qué tengo hoy?']);
        expect(voice.reply.value).toBe('reply to "¿Qué tengo hoy?"');
        expect(voice.state.value).toBe('idle');
        expect(voice.error.value).toBeNull();
    });

    it('enters error state when mic permission is denied', async () => {
        const voice = useVoiceChat(
            () => createFakeEngine(),
            fakeChatPost().chatPost,
            fakeMic(true),
        );

        await voice.start();

        expect(voice.state.value).toBe('error');
        expect(voice.error.value).toBe('Permission denied');
    });

    it('wake→utterance→chat→reply→returns to listening (loop, not idle)', async () => {
        const chat = deferred<string>();
        const synthesize = deferred<Blob>();
        const playDone = deferred<void>();
        const engine = createFakeEngine({
            transcribe: async () => 'hola mundo',
            synthesize: () => synthesize.promise,
        });
        const chatSpy = vi.fn(() => chat.promise);
        const play = vi.fn(() => playDone.promise);
        const detector = createFakeWakeWordDetector();
        const voice = useVoiceChat(
            () => engine,
            chatSpy,
            fakeMic(),
            play,
            () => detector,
        );

        await voice.startListening();
        expect(voice.mode.value).toBe('wake');
        expect(voice.state.value).toBe('listening');

        detector.emitWake();
        await vi.waitFor(() => expect(voice.state.value).toBe('wake_detected'));

        detector.emitUtterance(new Float32Array(16000));
        await vi.waitFor(() => expect(voice.state.value).toBe('calling'));

        expect(chatSpy).toHaveBeenCalledWith('hola mundo');

        chat.resolve('la respuesta');
        await vi.waitFor(() => expect(voice.state.value).toBe('synthesizing'));

        synthesize.resolve(new Blob(['wav'], { type: 'audio/wav' }));
        await vi.waitFor(() => expect(voice.state.value).toBe('playing'));

        playDone.resolve();
        await vi.waitFor(() => expect(voice.state.value).toBe('listening'));

        expect(voice.transcription.value).toBe('hola mundo');
        expect(voice.reply.value).toBe('la respuesta');
        expect(play).toHaveBeenCalledTimes(1);
        expect(voice.mode.value).toBe('wake');
    });

    it('stays listening and makes no /chat call when no wake is detected', async () => {
        const { chatPost, calls } = fakeChatPost();
        const detector = createFakeWakeWordDetector();
        const voice = useVoiceChat(
            () => createFakeEngine(),
            chatPost,
            fakeMic(),
            vi.fn(async () => {}),
            () => detector,
        );

        await voice.startListening();
        expect(voice.state.value).toBe('listening');

        // No wake emission and no utterance: still listening, no /chat.
        expect(calls).toEqual([]);
        expect(voice.state.value).toBe('listening');
        expect(voice.error.value).toBeNull();
    });

    it('enters error when wake transcription fails', async () => {
        const engine = createFakeEngine({
            transcribe: async () => {
                throw new Error('boom');
            },
        });
        const detector = createFakeWakeWordDetector();
        const voice = useVoiceChat(
            () => engine,
            fakeChatPost().chatPost,
            fakeMic(),
            vi.fn(async () => {}),
            () => detector,
        );

        await voice.startListening();
        detector.emitWake();
        detector.emitUtterance(new Float32Array(16000));

        await vi.waitFor(() => expect(voice.error.value).toBe('boom'));
        expect(voice.state.value).toBe('listening');
    });

    it('mute guard: suspends wake detection during synthesizing/playing, resumes after', async () => {
        const chat = deferred<string>();
        const synthesize = deferred<Blob>();
        const playDone = deferred<void>();
        const engine = createFakeEngine({
            transcribe: async () => 'hola',
            synthesize: () => synthesize.promise,
        });
        const detector = createFakeWakeWordDetector();
        const voice = useVoiceChat(
            () => engine,
            () => chat.promise,
            fakeMic(),
            vi.fn(() => playDone.promise),
            () => detector,
        );

        await voice.startListening();
        detector.emitWake();
        detector.emitUtterance(new Float32Array(16000));
        await vi.waitFor(() => expect(voice.state.value).toBe('calling'));

        chat.resolve('la respuesta');
        await vi.waitFor(() => expect(voice.state.value).toBe('synthesizing'));

        // While the own reply is being produced, a stray wake is dropped.
        detector.emitWake();
        expect(voice.state.value).toBe('synthesizing');

        synthesize.resolve(new Blob(['wav'], { type: 'audio/wav' }));
        await vi.waitFor(() => expect(voice.state.value).toBe('playing'));

        playDone.resolve();
        await vi.waitFor(() => expect(voice.state.value).toBe('listening'));

        // After the reply, detection is active again.
        detector.emitWake();
        await vi.waitFor(() => expect(voice.state.value).toBe('wake_detected'));
    });

    it('push-to-talk coexists with always-listening (mode push still works)', async () => {
        const chat = deferred<string>();
        const engine = createFakeEngine({
            synthesize: () => chat.promise.then(() => new Blob(['wav'])),
        });
        const voice = useVoiceChat(
            () => engine,
            () => chat.promise,
            fakeMic(),
            vi.fn(async () => {}),
            () => createFakeWakeWordDetector(),
        );

        // Default is push-to-talk.
        expect(voice.mode.value).toBe('push');

        await voice.start();
        expect(voice.state.value).toBe('recording');

        const stopping = voice.stop();
        await vi.waitFor(() => expect(voice.state.value).toBe('transcribing'));
        chat.resolve('respuesta');
        await stopping;

        expect(voice.mode.value).toBe('push');
        expect(voice.state.value).toBe('idle');
    });

    it('stopListening stops the detector, releases the mic, and resets mode', async () => {
        const mic = fakeMic();
        const detector = createFakeWakeWordDetector();
        const stopSpy = vi.spyOn(detector, 'stop');
        const releaseSpy = vi.spyOn(mic, 'release');
        const voice = useVoiceChat(
            () => createFakeEngine(),
            fakeChatPost().chatPost,
            mic,
            vi.fn(async () => {}),
            () => detector,
        );

        await voice.startListening();
        expect(voice.mode.value).toBe('wake');

        voice.stopListening();

        expect(voice.mode.value).toBe('push');
        expect(voice.state.value).toBe('idle');
        expect(stopSpy).toHaveBeenCalledTimes(1);
        expect(releaseSpy).toHaveBeenCalledTimes(1);
    });
});
