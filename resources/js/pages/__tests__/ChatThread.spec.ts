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

describe('Chat.vue thread render', () => {
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

    it('renders every prior turn from the response history', async () => {
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

        const text = wrapper.text();
        expect(text).toContain('¿Me investigas el clima?');
        expect(text).toContain('Claro, ya lo tengo.');
        expect(text).toContain('Sí, apúntalo en Obsidian.');
        expect(text).toContain('Listo');
    });

    it('sends the persisted session_id on POST /chat', async () => {
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

        expect(fetchMock).toHaveBeenCalledTimes(1);

        const [, init] = fetchMock.mock.calls[0] as [
            string,
            RequestInit | undefined,
        ];
        const body = JSON.parse(String(init?.body));

        expect(body.message).toBe('Hola');
        expect(body.session_id).toBe('test-session-123');
    });
});
