/**
 * The fleet, as the server sends it.
 *
 * Mirrors App\Http\Resources\DroneModelResource, which is built from the two
 * JSON columns on `drone_models`. The array shapes pinned in
 * App\Models\DroneModel's docblock are the other half of this contract — the
 * same arrangement `EnvironmentConfig` already has with
 * `challenges.environment`.
 */

/**
 * The performance envelope: everything that makes one airframe fly
 * differently from another.
 *
 * Read by the control loop in `@/lib/simulator/physics`, which carries it on
 * the `ControlState` for a run. Every field here was a module constant in
 * that file before the fleet had more than one member, and the HX-6
 * Surveyor's values are still exactly those constants.
 */
export type DroneFlightSpec = {
    /** Cruise speed a run starts at, in m/s. `drone.setSpeed()` moves it. */
    cruiseSpeed: number;
    /** Floor and ceiling `drone.setSpeed()` is clamped to. */
    minCruiseSpeed: number;
    maxCruiseSpeed: number;
    maxClimbRate: number;
    maxDescentRate: number;
    horizontalAcceleration: number;
    verticalAcceleration: number;
    /** Deceleration the approach braking curve is planned against. */
    brakingAcceleration: number;
    maxYawRate: number;
    yawAcceleration: number;
    /** Radians of tilt the airframe will lean into an acceleration. */
    maxTilt: number;
    /** Motor spool-up on the pad before a takeoff climb begins. */
    spoolSeconds: number;
    /**
     * Height the body centre parks at when landed, in meters.
     *
     * The one number both halves of a drone need: the physics parks the body
     * at it and the landing gear has to reach exactly that far down, which is
     * why the geometry derives from it rather than the other way round.
     */
    restHeight: number;
    /** Battery percent per second with motors idle. */
    batteryIdleDrain: number;
    /** Additional battery percent per second at full throttle. */
    batteryThrottleDrain: number;
};

/**
 * The dimensions the airframe is drawn from.
 *
 * Read by `@/lib/simulator/airframe`, which derives the rest of the mesh
 * tree's geometry from these, and checked against the physical invariants in
 * `airframe.test.ts` for every drone in the fleet.
 */
export type DroneAirframeSpec = {
    /** Motor count. Four, six or eight, evenly spaced around the ring. */
    rotors: number;
    /** Circumradius of the prism core the booms leave from. */
    bodyRadius: number;
    /** Distance from the airframe's centre to each motor shaft. */
    motorReach: number;
    /** Radius of the disc a spinning rotor sweeps. */
    propRadius: number;
    /** One two-blade prop, tip to tip. */
    bladeLength: number;
    legLength: number;
    /**
     * Where the landing legs hang, as a fraction of the motor reach.
     *
     * Per-drone rather than derived because how far inboard the rotor discs
     * come depends on how tightly the ring is packed: an eight-rotor
     * airframe needs its legs further out than a hex to stay clear of them.
     */
    legReachRatio: number;
    /** Rotor speed at full throttle, in rad/s. Visual only. */
    rotorMaxSpeed: number;
    /** Body paint. */
    livery: string;
    /** Dome, canopy and trim colour. */
    accent: string;
};

/** One airframe in the fleet, as the cockpit picker lists it. */
export type DroneModelSummary = {
    /** The drone's uuid — the only identifier the server will act on. */
    id: string;
    slug: string;
    name: string;
    class: string;
    classLabel: string;
    summary: string;
    /** The airframe every mission in the catalogue is balanced against. */
    isDefault: boolean;
    flight: DroneFlightSpec;
    airframe: DroneAirframeSpec;
};
