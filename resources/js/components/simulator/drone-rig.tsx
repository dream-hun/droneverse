import { RigidBody } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import { useRef } from 'react';
import { DroneModel } from '@/components/simulator/drone-model';
import { useDroneSimulation } from '@/hooks/use-drone-simulation';
import { REST_HEIGHT } from '@/lib/simulator/physics';
import type { SimulatorSession } from '@/lib/simulator/session';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type DroneRigProps = {
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
};

export function DroneRig({
    session,
    environment,
    successCriteria,
    maxScore,
    attemptUrl,
}: DroneRigProps) {
    const rigidBodyRef = useRef<RapierRigidBody>(null);
    const { handleCollision } = useDroneSimulation({
        rigidBodyRef,
        session,
        environment,
        successCriteria,
        maxScore,
        attemptUrl,
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
            <DroneModel />
        </RigidBody>
    );
}
