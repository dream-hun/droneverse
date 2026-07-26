import type { BufferGeometry, Material } from 'three';

/**
 * Shared store for the geometries and materials the simulator scene draws
 * with, alongside {@see ./texture-cache} for the textures they carry.
 *
 * A mission is built out of repeats. A car has six identical wheels, a
 * tower has four identical parapet walls, and a street has nine vehicles
 * drawn from seven paint colours. Declared inline, every one of those
 * meshes minted its own geometry and its own material: the busiest authored
 * mission came to 190 geometries and 186 materials for 222 meshes, which is
 * one of each per mesh and no sharing at all. Each is a separate GPU buffer,
 * a separate set of uniform uploads, and a separate state change in a scene
 * that redraws every frame and again for the shadow pass.
 *
 * The rules are the texture cache's rules, for the same reasons:
 *
 * - The key must capture everything that makes two entries differ. A wheel
 *   is a wheel, but a parapet is only shareable with a parapet of the same
 *   span, so dimensions belong in the key.
 * - Callers must never dispose or mutate what they are handed. Anything the
 *   frame loop animates does not belong here — it belongs to the component
 *   that animates it.
 *
 * Entries live until {@see releaseSceneResources} is called when the scene
 * tears down.
 */
const geometries = new Map<string, BufferGeometry>();
const materials = new Map<string, Material>();

export function sharedGeometry<T extends BufferGeometry>(
    key: string,
    create: () => T,
): T {
    const existing = geometries.get(key);

    if (existing) {
        return existing as T;
    }

    const geometry = create();
    geometries.set(key, geometry);

    return geometry;
}

export function sharedMaterial<T extends Material>(
    key: string,
    create: () => T,
): T {
    const existing = materials.get(key);

    if (existing) {
        return existing as T;
    }

    const material = create();
    materials.set(key, material);

    return material;
}

/** Drop every shared geometry and material; called when the scene unmounts. */
export function releaseSceneResources(): void {
    geometries.forEach((geometry) => geometry.dispose());
    geometries.clear();
    materials.forEach((material) => material.dispose());
    materials.clear();
}
