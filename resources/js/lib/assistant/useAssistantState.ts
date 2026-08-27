import { computed } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { AssistantVisualState } from './types';

/** Signals the shared assistant state is derived from. */
export interface UseAssistantStateSources {
    /** Chat request in flight → processing. */
    chatLoading: Ref<boolean>;
    /** Voice signals (Slice B); optional in MVP. */
    voice?: {
        calling: Ref<boolean>;
        listening: Ref<boolean>;
        playing: Ref<boolean>;
    };
}

export interface UseAssistantState {
    state: ComputedRef<AssistantVisualState>;
    isBusy: ComputedRef<boolean>;
}

/**
 * Maps Chat/Voice signals into the four visual states. The model contains all
 * four states from day one; listening/speaking are driven by the optional
 * `voice` signals and render idle/placeholder until the voice backlog lands.
 */
export function useAssistantState(
    sources: UseAssistantStateSources,
): UseAssistantState {
    const { chatLoading, voice } = sources;

    const state = computed<AssistantVisualState>(() => {
        if (chatLoading.value) {
            return 'processing';
        }

        if (voice) {
            if (voice.calling.value) {
                return 'processing';
            }

            if (voice.listening.value) {
                return 'listening';
            }

            if (voice.playing.value) {
                return 'speaking';
            }
        }

        return 'idle';
    });

    const isBusy = computed(() => state.value === 'processing');

    return { state, isBusy };
}
