import { describe, expect, it, vi } from 'vitest';
import { createFakeEngine } from '../FakeVoiceEngine';
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
});
