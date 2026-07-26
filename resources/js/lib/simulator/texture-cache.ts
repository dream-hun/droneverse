import type { CanvasTexture } from 'three';

/**
 * Shared store for the simulator's procedural textures.
 *
 * Rasterizing a canvas texture is not cheap — a facade paints thousands of
 * rectangles — and the scene is full of repeats: a row of identical crates,
 * a wall of identical concrete panels. Without this, each one paints its own
 * copy of the same pixels and hands the GPU its own upload.
 *
 * The key must capture everything that makes two textures differ, including
 * mutable properties like `repeat`. Textures are shared, so a caller that
 * mutated one would be mutating every other user of it; baking those values
 * into the key instead means callers with different needs simply get
 * different textures.
 *
 * Entries live until {@see releaseTextureCache} is called when the scene
 * tears down. Callers must never dispose what they are handed — they do not
 * own it.
 */
const cache = new Map<string, CanvasTexture>();

export function cachedTexture(
    key: string,
    create: () => CanvasTexture,
): CanvasTexture {
    const existing = cache.get(key);

    if (existing) {
        return existing;
    }

    const texture = create();
    cache.set(key, texture);

    return texture;
}

/** Drop every cached texture; called when the simulator scene unmounts. */
export function releaseTextureCache(): void {
    cache.forEach((texture) => texture.dispose());
    cache.clear();
}
