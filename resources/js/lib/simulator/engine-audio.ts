/**
 * Synthesized quadcopter motor sound built live with the Web Audio API, so
 * (like the cockpit voice) the simulator ships no audio assets. Four detuned
 * sawtooth oscillators stand in for the four motors — their slight mistuning
 * beats against each other the way real props do — muffled through a lowpass,
 * tremolo-modulated at the blade-pass rate, and layered over filtered noise
 * for prop wash. The physics loop feeds it throttle and rotor speed each
 * frame, so pitch and loudness track the airframe: a rising spool on takeoff,
 * a strained whine under hard climbs, a wash of air as it picks up speed.
 *
 * The AudioContext is created lazily on the first start() — which only ever
 * happens from a run the user clicked — so autoplay policies never block it.
 */

const STORAGE_KEY = 'droneverse:engine-enabled';

// Motor detune ratios: four motors never spin at exactly the same rate.
const MOTOR_RATIOS = [1, 1.007, 0.993, 1.013];

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

type EngineNodes = {
    context: AudioContext;
    master: GainNode;
    tremolo: GainNode;
    motorFilter: BiquadFilterNode;
    motors: OscillatorNode[];
    lfo: OscillatorNode;
    noiseGain: GainNode;
    noiseFilter: BiquadFilterNode;
    noise: AudioBufferSourceNode;
};

class DroneEngineAudio {
    private nodes: EngineNodes | null = null;
    private running = false;
    private enabled: boolean;
    private listeners = new Set<() => void>();

    constructor() {
        let stored: string | null = null;

        if (typeof window !== 'undefined') {
            try {
                stored = window.localStorage.getItem(STORAGE_KEY);
            } catch {
                stored = null;
            }
        }

        this.enabled = stored !== 'false';
    }

    isSupported(): boolean {
        return (
            typeof window !== 'undefined' &&
            ('AudioContext' in window || 'webkitAudioContext' in window)
        );
    }

    isEnabled = (): boolean => this.enabled;

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    setEnabled(enabled: boolean): void {
        this.enabled = enabled;

        if (typeof window !== 'undefined') {
            try {
                window.localStorage.setItem(STORAGE_KEY, String(enabled));
            } catch {
                // Ignore storage failures (private mode, quota).
            }
        }

        if (!enabled) {
            this.silence();
        }

        this.listeners.forEach((listener) => listener());
    }

    toggle(): void {
        this.setEnabled(!this.enabled);
    }

    /** Spool the motors up for a run. No-op if muted or unsupported. */
    start(): void {
        if (!this.enabled || !this.isSupported()) {
            return;
        }

        const nodes = this.ensureNodes();

        if (!nodes) {
            return;
        }

        if (nodes.context.state === 'suspended') {
            void nodes.context.resume();
        }

        this.running = true;
    }

    /** Cut the motors, ramping the master gain down so it doesn't click. */
    stop(): void {
        this.running = false;
        this.silence();
    }

    /**
     * Feed the current airframe state to the synth. Called every frame while a
     * run is active; safe to call when idle to let the sound coast to silence.
     */
    setState(throttle: number, rotorSpeed: number, groundSpeed: number): void {
        const nodes = this.nodes;

        if (!nodes || !this.enabled) {
            return;
        }

        const now = nodes.context.currentTime;
        const audible = clamp((rotorSpeed - 3) / 30, 0, 1);
        const thr = clamp(throttle, 0, 1);

        // Fundamental motor whine climbs with throttle; rotor speed keeps the
        // transition smooth through spool-up and spool-down.
        const base = 72 + thr * 168 + audible * 14;
        nodes.motors.forEach((motor, i) => {
            motor.frequency.setTargetAtTime(base * MOTOR_RATIOS[i], now, 0.05);
        });

        // Open the lowpass as the motors work harder — more bite under load.
        nodes.motorFilter.frequency.setTargetAtTime(
            420 + thr * 2100,
            now,
            0.06,
        );

        // Blade-pass tremolo: two blades per rev turns rotor rad/s into Hz.
        const bladeHz = clamp(rotorSpeed / Math.PI, 4, 40);
        nodes.lfo.frequency.setTargetAtTime(bladeHz, now, 0.08);

        // Prop wash / air rush swells with throttle and forward speed.
        const wash = 0.02 + thr * 0.1 + clamp(groundSpeed / 8, 0, 1) * 0.06;
        nodes.noiseGain.gain.setTargetAtTime(wash * audible, now, 0.08);
        nodes.noiseFilter.frequency.setTargetAtTime(600 + thr * 1400, now, 0.1);

        const target = this.running ? audible * 0.42 : 0;
        nodes.master.gain.setTargetAtTime(target, now, 0.09);
    }

    /** Tear down the audio graph entirely (component unmount). */
    release(): void {
        this.running = false;

        if (this.nodes) {
            try {
                this.nodes.motors.forEach((motor) => motor.stop());
                this.nodes.lfo.stop();
                this.nodes.noise.stop();
                void this.nodes.context.close();
            } catch {
                // Already closed / never resumed.
            }

            this.nodes = null;
        }
    }

    private silence(): void {
        const nodes = this.nodes;

        if (!nodes) {
            return;
        }

        nodes.master.gain.setTargetAtTime(0, nodes.context.currentTime, 0.12);
    }

    private ensureNodes(): EngineNodes | null {
        if (this.nodes) {
            return this.nodes;
        }

        const Ctor =
            window.AudioContext ??
            (window as unknown as { webkitAudioContext: typeof AudioContext })
                .webkitAudioContext;

        if (!Ctor) {
            return null;
        }

        const context = new Ctor();

        const master = context.createGain();
        master.gain.value = 0;
        master.connect(context.destination);

        // Tremolo stage: LFO drives this gain around 1.0 for the motor buzz.
        const tremolo = context.createGain();
        tremolo.gain.value = 1;
        tremolo.connect(master);

        const lfo = context.createOscillator();
        lfo.type = 'sine';
        lfo.frequency.value = 12;
        const lfoDepth = context.createGain();
        lfoDepth.gain.value = 0.3;
        lfo.connect(lfoDepth);
        lfoDepth.connect(tremolo.gain);
        lfo.start();

        // Four motors through a shared lowpass so the saws read as muffled.
        const motorFilter = context.createBiquadFilter();
        motorFilter.type = 'lowpass';
        motorFilter.frequency.value = 600;
        motorFilter.Q.value = 0.7;
        motorFilter.connect(tremolo);

        const motors = MOTOR_RATIOS.map((ratio) => {
            const osc = context.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.value = 80 * ratio;

            const gain = context.createGain();
            gain.gain.value = 0.16;
            osc.connect(gain);
            gain.connect(motorFilter);
            osc.start();

            return osc;
        });

        // Broadband prop-wash noise through a bandpass.
        const noiseBuffer = context.createBuffer(
            1,
            context.sampleRate * 2,
            context.sampleRate,
        );
        const channel = noiseBuffer.getChannelData(0);

        for (let i = 0; i < channel.length; i++) {
            channel[i] = Math.random() * 2 - 1;
        }

        const noise = context.createBufferSource();
        noise.buffer = noiseBuffer;
        noise.loop = true;

        const noiseFilter = context.createBiquadFilter();
        noiseFilter.type = 'bandpass';
        noiseFilter.frequency.value = 900;
        noiseFilter.Q.value = 0.6;

        const noiseGain = context.createGain();
        noiseGain.gain.value = 0;

        noise.connect(noiseFilter);
        noiseFilter.connect(noiseGain);
        noiseGain.connect(master);
        noise.start();

        this.nodes = {
            context,
            master,
            tremolo,
            motorFilter,
            motors,
            lfo,
            noiseGain,
            noiseFilter,
            noise,
        };

        return this.nodes;
    }
}

/** Shared instance: the physics loop drives it, the toolbar toggles it. */
export const droneEngine = new DroneEngineAudio();
