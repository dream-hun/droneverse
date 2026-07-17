import { useFrame } from '@react-three/fiber';
import type { RapierRigidBody } from '@react-three/rapier';
import { useMemo } from 'react';
import type { RefObject } from 'react';
import { PerspectiveCamera, Vector3 } from 'three';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import { forwardVector, quaternionYaw } from '@/lib/simulator/physics';

export type CameraMode = 'orbit' | 'follow' | 'fpv';

export const DEFAULT_FOV = 50;
const FOLLOW_FOV = 55;
const FPV_FOV = 78;

type CameraRigProps = {
    mode: CameraMode;
    rigidBodyRef: RefObject<RapierRigidBody | null>;
    flightState: FlightVisualState;
};

/**
 * Drives the camera in the two piloted views: a damped chase camera behind
 * the drone, and an FPV view from the airframe's nose that inherits part of
 * the body tilt. In orbit mode the rig only restores the default field of
 * view and lets OrbitControls own the camera.
 */
export function CameraRig({ mode, rigidBodyRef, flightState }: CameraRigProps) {
    const temp = useMemo(
        () => ({ desired: new Vector3(), look: new Vector3() }),
        [],
    );

    useFrame((state, dt) => {
        const camera = state.camera;

        if (!(camera instanceof PerspectiveCamera)) {
            return;
        }

        if (mode === 'orbit') {
            if (camera.fov !== DEFAULT_FOV) {
                camera.fov = DEFAULT_FOV;
                camera.updateProjectionMatrix();
            }

            return;
        }

        const body = rigidBodyRef.current;

        if (!body) {
            return;
        }

        const position = body.translation();
        const yaw = quaternionYaw(body.rotation());
        const forward = forwardVector(yaw);
        let targetFov = FOLLOW_FOV;

        if (mode === 'follow') {
            temp.desired.set(
                position.x - forward.x * 4.6,
                position.y + 2.1,
                position.z - forward.z * 4.6,
            );
            camera.position.lerp(temp.desired, 1 - Math.exp(-dt * 3));
            temp.look.set(
                position.x + forward.x * 1.5,
                position.y + 0.35,
                position.z + forward.z * 1.5,
            );
            camera.lookAt(temp.look);
        } else {
            targetFov = FPV_FOV;
            camera.position.set(
                position.x + forward.x * 0.3,
                position.y + 0.08,
                position.z + forward.z * 0.3,
            );
            camera.rotation.set(
                flightState.pitch * 0.55 - 0.05,
                yaw,
                flightState.roll * 0.5,
                'YXZ',
            );
        }

        if (Math.abs(camera.fov - targetFov) > 0.1) {
            camera.fov += (targetFov - camera.fov) * Math.min(1, dt * 5);
            camera.updateProjectionMatrix();
        }
    });

    return null;
}
