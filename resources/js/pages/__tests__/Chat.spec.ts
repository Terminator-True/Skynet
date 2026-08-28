import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Chat from '../Chat.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
}));

const SESSION_KEY = 'skynet.chat.session_id';

const THREAD = [
    { role: 'user', content: '¿Me investigas el clima?' },
    { role: 'assistant', content: 'Claro, ya lo tengo.' },
    { role: 'user', content: 'Sí, apúntalo en Obsidian.' },
    { role: 'assistant', content: '**Listo**, guardado en tu nota.' },
];

function createStorage(): Storage {
    const store = new Map<string, string>();

    return {
        get length() {
            return store.size;
        },
        clear: () => store.clear(),
        getItem: (key) => store.get(key) ?? null,
        key: (index) => [...store.keys()][index] ?? null,
        removeItem: (key) => store.delete(key),
        setItem: (key, value) => store.set(key, String(value)),
    };
}

function flushPromises(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

async function mountChatWithThread(): Promise<
    ReturnType<typeof mount<typeof Chat>>
> {
    const wrapper = mount(Chat, {
        global: {
            stubs: {
                Head: true,
                NotificationToasts: true,
            },
        },
    });

    await wrapper.find('input').setValue('Hola');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    await wrapper.vm.$nextTick();

    return wrapper;
}

describe('Chat.vue state buttons', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.stubGlobal('localStorage', createStorage());
        localStorage.setItem(SESSION_KEY, 'test-session-123');

        fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                reply: '**Listo**, guardado en tu nota.',
                tool_calls: [],
                session_id: 'test-session-123',
                history: THREAD,
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('is passive: clicking a non-matching button leaves assistantState unchanged and only the matching button is highlighted', async () => {
        const wrapper = await mountChatWithThread();

        // Idle is the default runtime state before any request.
        const activeClass = 'bg-hud-accent';

        const idleButton = wrapper.find('button[data-state="idle"]');
        const processingButton = wrapper.find(
            'button[data-state="processing"]',
        );

        expect(idleButton.exists()).toBe(true);
        expect(processingButton.exists()).toBe(true);
        expect(idleButton.classes()).toContain(activeClass);
        expect(processingButton.classes()).not.toContain(activeClass);

        await processingButton.trigger('click');
        await wrapper.vm.$nextTick();

        // No state override happened: the matching (idle) button is still the
        // only highlighted one and the clicked button stays dimmed.
        expect(wrapper.find('button[data-state="idle"]').classes()).toContain(
            activeClass,
        );
        expect(
            wrapper.find('button[data-state="processing"]').classes(),
        ).not.toContain(activeClass);
    });
});

describe('Chat.vue bubble alignment', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.stubGlobal('localStorage', createStorage());
        localStorage.setItem(SESSION_KEY, 'test-session-123');

        fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                reply: '**Listo**, guardado en tu nota.',
                tool_calls: [],
                session_id: 'test-session-123',
                history: THREAD,
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('aligns user bubbles to the right (ml-auto) and assistant bubbles to the left (mr-auto) at 78% width', async () => {
        const wrapper = await mountChatWithThread();

        const userBubbles = wrapper.findAll('[data-role="user"]');
        const assistantBubbles = wrapper.findAll('[data-role="assistant"]');

        expect(userBubbles.length).toBeGreaterThan(0);
        expect(assistantBubbles.length).toBeGreaterThan(0);

        for (const bubble of userBubbles) {
            expect(bubble.classes()).toContain('ml-auto');
            expect(bubble.classes()).toContain('w-[78%]');
        }

        for (const bubble of assistantBubbles) {
            expect(bubble.classes()).toContain('mr-auto');
            expect(bubble.classes()).toContain('w-[78%]');
        }
    });
});
