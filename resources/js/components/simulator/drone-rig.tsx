import { RigidBody } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import type { RefObject } from 'react';
import { DroneModel } from '@/components/simulator/drone-model';
import { useDroneSimulation } from '@/hooks/use-drone-simulation';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import { REST_HEIGHT } from '@/lib/simulator/physics';
import type { SimulatorSession } from '@/lib/simulator/session';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type DroneRigProps = {
    rigidBodyRef: RefObject<RapierRigidBody | null>;
    flightState: FlightVisualState;
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
};

export function DroneRig({
    rigidBodyRef,
    flightState,
    session,
    environment,
    successCriteria,
    maxScore,
    attemptUrl,
}: DroneRigProps) {
    const { handleCollision } = useDroneSimulation({
        rigidBodyRef,
        session,
        environment,
        successCriteria,
        maxScore,
        attemptUrl,
        flightState,
    });

    return (
        <RigidBody
            ref={rigidBodyRef}
            type="dynamic"
            colliders="cuboid"
            gravityScale={0}
            linearDamping={4}
            angularDamping={4}
            enabledRotations={[false, true, false]}
            position={[environment.start.x, REST_HEIGHT, environment.start.z]}
            userData={{ kind: 'drone' }}
            onCollisionEnter={({ other }) =>
                handleCollision(
                    other.rigidBodyObject?.userData?.kind as string | undefined,
                )
            }
        >
            <DroneModel flightState={flightState} />
        </RigidBody>
    );
}
