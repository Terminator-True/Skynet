import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AssistantVisualizer from '../AssistantVisualizer.vue';

function fakeCtx(): CanvasRenderingContext2D {
    return {
        clearRect: vi.fn(),
        beginPath: vi.fn(),
        arc: vi.fn(),
        stroke: vi.fn(),
        fill: vi.fn(),
        setTransform: vi.fn(),
        globalAlpha: 1,
        lineWidth: 1,
        strokeStyle: '',
        fillStyle: '',
    } as unknown as CanvasRenderingContext2D;
}

beforeEach(() => {
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
        cb(0);

        return 1;
    });
    vi.stubGlobal('cancelAnimationFrame', vi.fn());
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.stubGlobal('matchMedia', () => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
});

describe('AssistantVisualizer', () => {
    it('renders a canvas element', () => {
        const wrapper = mount(AssistantVisualizer);

        expect(wrapper.find('canvas').exists()).toBe(true);
    });

    it('renders the static SVG ring + status text when canvas 2D is unavailable', async () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(
            null,
        );

        const wrapper = mount(AssistantVisualizer, {
            props: { state: 'processing' },
        });

        await wrapper.vm.$nextTick();

        expect(wrapper.find('svg').exists()).toBe(true);
        expect(wrapper.text()).toContain('Processing');
    });

    it('draws a static frame (no loop) under reduced motion', async () => {
        vi.stubGlobal('matchMedia', () => ({
            matches: true,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        }));
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(
            fakeCtx(),
        );

        const wrapper = mount(AssistantVisualizer, {
            props: { state: 'idle' },
        });

        await wrapper.vm.$nextTick();

        // With canvas available, the fallback SVG is hidden.
        expect(wrapper.find('svg').exists()).toBe(false);
        expect(wrapper.find('canvas').exists()).toBe(true);
        expect(wrapper.text()).toContain('Idle');
    });
});
