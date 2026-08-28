import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Chat from '../Chat.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
}));

const SESSION_KEY = 'skynet.chat.session_id';

interface HistoryMessage {
    role: string;
    content: string;
    tool_trace?: unknown[] | null;
}

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

function methodOf(init?: RequestInit): string {
    return (init?.method ?? 'GET').toUpperCase();
}

function isPostCall(call: unknown[]): boolean {
    const [, init] = call as [string, RequestInit | undefined];

    return methodOf(init) === 'POST';
}

/**
 * A fetch mock that distinguishes the GET /chat/history preload from the
 * POST /chat exchange, returning separate payloads for each.
 */
function createChatFetchMock(
    overrides: { getHistory?: HistoryMessage[] } = {},
): ReturnType<typeof vi.fn> {
    return vi.fn((input: string | Request | URL, init?: RequestInit) => {
        const url = String(input);

        if (methodOf(init) === 'GET' && url.startsWith('/chat/history')) {
            return Promise.resolve({
                ok: true,
                status: 200,
                json: async () => ({
                    session_id: 'test-session-123',
                    history: overrides.getHistory ?? [],
                }),
            });
        }

        return Promise.resolve({
            ok: true,
            status: 200,
            json: async () => ({
                reply: '**Listo**, guardado en tu nota.',
                tool_calls: [],
                session_id: 'test-session-123',
                history: THREAD,
            }),
        });
    });
}

describe('Chat.vue thread render', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.stubGlobal('localStorage', createStorage());
        localStorage.setItem(SESSION_KEY, 'test-session-123');

        fetchMock = createChatFetchMock();
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

        const postCalls = fetchMock.mock.calls.filter(isPostCall);

        expect(postCalls).toHaveLength(1);

        const [, init] = postCalls[0] as [string, RequestInit | undefined];
        const body = JSON.parse(String(init?.body));

        expect(body.message).toBe('Hola');
        expect(body.session_id).toBe('test-session-123');
    });

    it('seeds history from the GET preload on mount and auto-scrolls', async () => {
        const getHistory: HistoryMessage[] = [
            { role: 'assistant', content: 'Preloaded message from server.' },
        ];
        const mock = createChatFetchMock({ getHistory });
        vi.stubGlobal('fetch', mock);

        // Give the scroll container a non-zero scrollHeight so the auto-scroll
        // (scrollTop = scrollHeight) is observable in the test DOM.
        Object.defineProperty(window.HTMLElement.prototype, 'scrollHeight', {
            configurable: true,
            get: () => 600,
        });

        const wrapper = mount(Chat, {
            global: {
                stubs: {
                    Head: true,
                    NotificationToasts: true,
                },
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Preloaded message from server.');

        const thread = wrapper.find('section.overflow-y-auto');

        expect(thread.exists()).toBe(true);
        expect(thread.element.scrollTop).toBe(600);

        const getCalls = mock.mock.calls.filter((call) => !isPostCall(call));

        expect(getCalls).toHaveLength(1);
        expect(mock.mock.calls.filter(isPostCall)).toHaveLength(0);
    });

    it('keeps POST authoritative over GET preload when they race', async () => {
        let resolveGet!: (value: unknown) => void;
        const getPromise = new Promise((resolve) => {
            resolveGet = resolve;
        });

        const stale = 'Stale preload content — should be ignored.';
        const fresh = 'Fresh POST content wins.';

        const mock = vi.fn(
            (input: string | Request | URL, init?: RequestInit) => {
                const url = String(input);

                if (
                    methodOf(init) === 'GET' &&
                    url.startsWith('/chat/history')
                ) {
                    return getPromise.then(() => ({
                        ok: true,
                        status: 200,
                        json: async () => ({
                            session_id: 'test-session-123',
                            history: [{ role: 'assistant', content: stale }],
                        }),
                    }));
                }

                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: async () => ({
                        reply: fresh,
                        tool_calls: [],
                        session_id: 'test-session-123',
                        history: [
                            { role: 'user', content: 'hello' },
                            { role: 'assistant', content: fresh },
                        ],
                    }),
                });
            },
        );
        vi.stubGlobal('fetch', mock);

        const wrapper = mount(Chat, {
            global: {
                stubs: {
                    Head: true,
                    NotificationToasts: true,
                },
            },
        });

        // The user sends before the slow GET preload resolves.
        await wrapper.find('input').setValue('hello');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(fresh);

        // The stale GET resolves after the POST.
        resolveGet(true);
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(fresh);
        expect(wrapper.text()).not.toContain(stale);
    });
});
