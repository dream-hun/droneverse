/**
 * Mutable per-frame flight state shared between the physics loop (writer)
 * and the visual layers: the drone model (tilt, rotor speed, LEDs), the
 * camera rig, and the telemetry HUD. It is written inside useFrame and read
 * outside React's render cycle (useFrame or polling), never during render,
 * so plain mutation is safe and allocation-free.
 */
export type FlightVisualState = {
    /** Visual body tilt in radians, expressed directly as rotation.x/.z values. */
    pitch: number;
    roll: number;
    /** Visual rotor spin rate in rad/s. */
    rotorSpeed: number;
    /** 0..1 collective throttle estimate driving rotors and battery drain. */
    throttle: number;
    armed: boolean;
    airborne: boolean;
    /** Height above ground in meters (0 when resting on the pad). */
    altitude: number;
    groundSpeed: number;
    verticalSpeed: number;
    /** Compass heading, 0-360 with 0 at the field's -Z axis. */
    headingDeg: number;
    batteryPct: number;
    windSpeed: number;
    windHeadingDeg: number;
    /** Short status label for the HUD (STANDBY, SPOOL UP, ENROUTE, ...). */
    mode: string;
};

export function createFlightVisualState(): FlightVisualState {
    return {
        pitch: 0,
        roll: 0,
        rotorSpeed: 0,
        throttle: 0,
        armed: false,
        airborne: false,
        altitude: 0,
        groundSpeed: 0,
        verticalSpeed: 0,
        headingDeg: 0,
        batteryPct: 100,
        windSpeed: 0,
        windHeadingDeg: 0,
        mode: 'STANDBY',
    };
}

/** Reset for a fresh run; batteries never start at a perfectly full charge. */
export function resetFlightVisualState(state: FlightVisualState): void {
    state.pitch = 0;
    state.roll = 0;
    state.throttle = 0;
    state.armed = true;
    state.airborne = false;
    state.altitude = 0;
    state.groundSpeed = 0;
    state.verticalSpeed = 0;
    state.batteryPct = 100 - Math.random() * 3;
    state.mode = 'ARMED';
}
