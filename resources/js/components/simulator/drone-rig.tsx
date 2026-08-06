import { RigidBody } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import type { RefObject } from 'react';
import { DroneModel } from '@/components/simulator/drone-model';
import { useDroneSimulation } from '@/hooks/use-drone-simulation';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import type { SimulatorSession } from '@/lib/simulator/session';
import type { DroneModelSummary } from '@/types/drone';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type DroneRigProps = {
    rigidBodyRef: RefObject<RapierRigidBody | null>;
    flightState: FlightVisualState;
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
    photoUrl: string;
    drone: DroneModelSummary;
};

export function DroneRig({
    rigidBodyRef,
    flightState,
    session,
    environment,
    successCriteria,
    maxScore,
    attemptUrl,
    photoUrl,
    drone,
}: DroneRigProps) {
    const { handleCollision, handleSensorEnter } = useDroneSimulation({
        rigidBodyRef,
        session,
        environment,
        successCriteria,
        maxScore,
        attemptUrl,
        photoUrl,
        flightState,
        drone,
    });

    return (
        <RigidBody
            /*
             * Keyed on the airframe so switching drones rebuilds the body
             * rather than re-rendering it. `colliders="cuboid"` measures the
             * mesh tree once, when the body mounts; a Freighter swapped in
             * under a Vector's collider would fly a 1.4 m airframe through
             * gates on a 0.5 m hitbox, and every collision the mission scores
             * would be measured against the drone the pilot used to have.
             */
            key={drone.id}
            ref={rigidBodyRef}
            type="dynamic"
            colliders="cuboid"
            gravityScale={0}
            linearDamping={4}
            angularDamping={4}
            enabledRotations={[false, true, false]}
            position={[
                environment.start.x,
                drone.flight.restHeight,
                environment.start.z,
            ]}
            userData={{ kind: 'drone' }}
            onCollisionEnter={({ other }) =>
                handleCollision(
                    other.rigidBodyObject?.userData?.kind as string | undefined,
                )
            }
            onIntersectionEnter={({ other }) =>
                handleSensorEnter(
                    other.rigidBodyObject?.userData?.kind as string | undefined,
                )
            }
        >
            <DroneModel flightState={flightState} drone={drone} />
        </RigidBody>
    );
}
