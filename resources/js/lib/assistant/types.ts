/** Visual states the HUD central visualizer can express. */
export type AssistantVisualState =
    'idle' | 'processing' | 'listening' | 'speaking';

/** Animation parameters the Canvas renderer consumes for the current state. */
export interface AnimationParams {
    /** Which draw pattern to run. Listening/speaking use idle for now. */
    mode: 'idle' | 'processing';
    /** Rotation speed, deg/sec. */
    rotation: number;
    /** Particle drift speed, 0..1. */
    particleSpeed: number;
    /** Glow/opacity multiplier, 0..1. */
    intensity: number;
    /** Concentric wave radius as a fraction of the base radius. */
    waveRadius: number;
    /** Wave propagation speed, 0..1. */
    waveSpeed: number;
    /** Idle pulse period in ms. */
    pulsePeriodMs: number;
    /** Number of orbiting particles. */
    particleCount: number;
    colors: {
        accent: string;
        secondary: string;
    };
}
