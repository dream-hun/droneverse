import type { DroneCommand } from './commands';

/**
 * Spoken feedback for the drone as it carries out each action. Uses the
 * browser's built-in SpeechSynthesis API so no audio assets ship with the
 * bundle; on browsers without it (or with speech disabled) every call is a
 * silent no-op.
 *
 * The physics loop announces a command the moment it begins, and because
 * user code advances only when the drone has physically finished each
 * command, announcements stay in step with the flight rather than racing
 * ahead of it. Sensor queries (getPosition, getBattery, ...) are silent —
 * they read state without commanding the airframe.
 */

const STORAGE_KEY = 'droneverse:voice-enabled';

function round(value: number): number {
    return Math.round(value * 10) / 10;
}

/** Maps an action command to a short cockpit callout, or null to stay silent. */
function phraseFor(command: DroneCommand): string | null {
    switch (command.type) {
        case 'takeoff':
            return command.altitude !== undefined
                ? `Taking off. Climbing to ${round(command.altitude)} meters.`
                : 'Taking off.';
        case 'land':
            return 'Landing sequence engaged.';
        case 'moveForward':
            return `Moving forward ${round(command.distance)} meters.`;
        case 'moveTo':
            return 'Proceeding to waypoint.';
        case 'turn': {
            const direction = command.degrees >= 0 ? 'right' : 'left';

            return `Turning ${direction} ${round(Math.abs(command.degrees))} degrees.`;
        }
        case 'hover':
            return `Holding position for ${round(command.seconds)} seconds.`;
        case 'setAltitude':
            return `Adjusting altitude to ${round(command.altitude)} meters.`;
        case 'setSpeed':
            return `Cruise speed set to ${round(command.speed)}.`;
        case 'scan':
            return 'Scanning for objects.';
        case 'takePhoto':
            return command.label
                ? `Capturing photo: ${command.label}.`
                : 'Capturing photo.';
        default:
            return null;
    }
}

export type DroneVoiceEvent =
    'armed' | 'complete' | 'aborted' | 'fault' | 'washed';

const EVENT_PHRASES: Record<DroneVoiceEvent, string> = {
    armed: 'Systems online. Ready for flight.',
    complete: 'Mission complete.',
    aborted: 'Flight aborted.',
    fault: 'Fault detected. Holding.',
    washed: 'Wash cycle complete.',
};

class DroneVoice {
    private synth: SpeechSynthesis | null =
        typeof window !== 'undefined' && 'speechSynthesis' in window
            ? window.speechSynthesis
            : null;
    private voice: SpeechSynthesisVoice | null = null;
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

        if (this.synth) {
            this.pickVoice();
            // Voices load asynchronously in most browsers.
            this.synth.addEventListener?.('voiceschanged', () =>
                this.pickVoice(),
            );
        }
    }

    isSupported(): boolean {
        return this.synth !== null;
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
            this.synth?.cancel();
        }

        this.listeners.forEach((listener) => listener());
    }

    toggle(): void {
        this.setEnabled(!this.enabled);
    }

    /** Announce the action a command is about to perform. */
    announceCommand(command: DroneCommand): void {
        this.speak(phraseFor(command));
    }

    /** Announce a run-level event (arming, completion, abort, fault). */
    announceEvent(event: DroneVoiceEvent): void {
        this.speak(EVENT_PHRASES[event]);
    }

    /** Silence anything currently being spoken. */
    cancel(): void {
        this.synth?.cancel();
    }

    private speak(text: string | null): void {
        if (!text || !this.enabled || !this.synth) {
            return;
        }

        const utterance = new SpeechSynthesisUtterance(text);
        // A slightly low, flat delivery reads as a machine callout.
        utterance.rate = 1.05;
        utterance.pitch = 0.7;
        utterance.volume = 0.9;

        if (this.voice) {
            utterance.voice = this.voice;
        }

        this.synth.speak(utterance);
    }

    private pickVoice(): void {
        if (!this.synth) {
            return;
        }

        const voices = this.synth.getVoices();

        if (voices.length === 0) {
            return;
        }

        // Prefer an English voice that hints at a synthetic timbre, then any
        // English voice, then whatever the platform offers.
        this.voice =
            voices.find((voice) =>
                /google|zira|david|daniel|samantha/i.test(voice.name),
            ) ??
            voices.find((voice) => voice.lang.startsWith('en')) ??
            voices[0];
    }
}

/** Shared instance: the physics loop speaks, the toolbar toggles. */
export const droneVoice = new DroneVoice();
