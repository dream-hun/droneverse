export * from '@dimforge/rapier3d';

/**
 * Stand-in for `@dimforge/rapier3d-compat`, which the Vite config aliases
 * here so the physics engine's WebAssembly ships as a real `.wasm` asset
 * instead of two megabytes of base64 inlined into the simulator's bundle.
 *
 * The two packages are the same generated bindings built two ways. The
 * compat build exists for environments without bundler WASM support: it
 * embeds the binary as base64 and exposes `init()` to decode it — which is
 * why it costs a third more bytes than the binary itself, forfeits the
 * browser's compile-during-download path, and re-ships the engine with
 * every JS bundle revision. The bundler build imports the `.wasm` file
 * directly and initializes on import, so by the time this module has
 * loaded there is nothing left to init.
 *
 * `@react-three/rapier` still calls `init()` on whatever it imports, so
 * the name has to exist; it just has nothing to do.
 */
export async function init(): Promise<void> {
    // Initialization happened when the module graph loaded the .wasm import.
}
