import { PerspectiveCamera } from 'three';
import type { Scene, WebGLRenderer } from 'three';
import type { Vector3 } from './commands';
import { forwardVector } from './physics';

export const PHOTO_MAX_WIDTH = 1280;
export const PHOTO_JPEG_QUALITY = 0.85;
const PHOTO_FOV = 68;
/** Slight fixed gimbal down-tilt, matching the FPV nose camera's framing. */
const GIMBAL_PITCH_RAD = -0.1;

export type CapturedPhoto = {
    dataUrl: string;
    width: number;
    height: number;
};

/**
 * The drone's stills camera. Called from inside useFrame: it renders the
 * scene once from the airframe's nose into the WebGL canvas, copies the
 * frame into a capped-size 2D canvas, and encodes a JPEG data URL. R3F's
 * own render pass runs after the frame callbacks, immediately repainting
 * the pilot view, so the photo render is never visible on screen.
 */
export class DronePhotoCamera {
    private camera = new PerspectiveCamera(PHOTO_FOV, 16 / 9, 0.1, 500);
    private output: HTMLCanvasElement | null = null;

    capture(
        gl: WebGLRenderer,
        scene: Scene,
        position: Vector3,
        yaw: number,
        far: number,
    ): CapturedPhoto | null {
        const source = gl.domElement;

        if (source.width === 0 || source.height === 0) {
            return null;
        }

        const forward = forwardVector(yaw);
        this.camera.aspect = source.width / source.height;
        this.camera.far = far;
        this.camera.position.set(
            position.x + forward.x * 0.3,
            position.y + 0.08,
            position.z + forward.z * 0.3,
        );
        this.camera.rotation.set(GIMBAL_PITCH_RAD, yaw, 0, 'YXZ');
        this.camera.updateProjectionMatrix();

        gl.render(scene, this.camera);

        const width = Math.min(PHOTO_MAX_WIDTH, source.width);
        const height = Math.max(
            1,
            Math.round((width * source.height) / source.width),
        );

        this.output ??= document.createElement('canvas');
        this.output.width = width;
        this.output.height = height;

        const context = this.output.getContext('2d');

        if (!context) {
            return null;
        }

        context.drawImage(source, 0, 0, width, height);

        try {
            return {
                dataUrl: this.output.toDataURL(
                    'image/jpeg',
                    PHOTO_JPEG_QUALITY,
                ),
                width,
                height,
            };
        } catch {
            return null;
        }
    }

    release(): void {
        this.output = null;
    }
}
