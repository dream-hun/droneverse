import type { ObstacleConfig } from '@/types/simulator';

export type ObstacleKind =
    'building' | 'crate' | 'pylon' | 'wall' | 'cylinder' | 'block';

/**
 * Decides how a raw collision box should look — and how `drone.scan()`
 * reports it. Sizes come straight from the challenge config, so the same
 * footprint always reads the same way: tall chunky boxes become glazed
 * towers, small ones become cargo crates, thin tall posts get hazard
 * stripes, and long thin boxes become concrete walls. The physics collider
 * is unchanged regardless of which visual wins.
 */
export function classifyObstacle(obstacle: ObstacleConfig): ObstacleKind {
    if (obstacle.type === 'cylinder') {
        return 'cylinder';
    }

    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const footprint = Math.min(sx, sz);
    const span = Math.max(sx, sz);

    if (sy >= 4 && footprint >= 2.5) {
        return 'building';
    }

    if (footprint <= 1.2 && span >= 3) {
        return 'wall';
    }

    if (footprint <= 1.6 && sy >= 2) {
        return 'pylon';
    }

    if (sy <= 2.5 && sx <= 2.2 && sz <= 2.2) {
        return 'crate';
    }

    return 'block';
}
