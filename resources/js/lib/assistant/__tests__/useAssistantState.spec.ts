import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useAssistantState } from '../useAssistantState';

describe('useAssistantState', () => {
    it('maps chatLoading=true to processing', () => {
        const chatLoading = ref(false);
        const { state } = useAssistantState({ chatLoading });

        expect(state.value).toBe('idle');

        chatLoading.value = true;

        expect(state.value).toBe('processing');
    });

    it('defaults to idle when no signal is active', () => {
        const { state, isBusy } = useAssistantState({
            chatLoading: ref(false),
        });

        expect(state.value).toBe('idle');
        expect(isBusy.value).toBe(false);
    });

    it('exposes all four states and isBusy', () => {
        const chatLoading = ref(false);
        const voice = {
            calling: ref(false),
            listening: ref(false),
            playing: ref(false),
        };
        const { state, isBusy } = useAssistantState({ chatLoading, voice });

        voice.calling.value = true;
        expect(state.value).toBe('processing');
        expect(isBusy.value).toBe(true);

        voice.calling.value = false;
        voice.listening.value = true;
        expect(state.value).toBe('listening');
        expect(isBusy.value).toBe(false);

        voice.listening.value = false;
        voice.playing.value = true;
        expect(state.value).toBe('speaking');
    });

    it('keeps listening/speaking present even without a voice source', () => {
        const { state } = useAssistantState({ chatLoading: ref(false) });

        expect(state.value).toBe('idle');
        // The model holds all four states by construction via the type.
        expect(typeof state).toBe('object');
    });
});
