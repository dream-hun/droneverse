import { fileURLToPath } from 'node:url';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        rolldownOptions: {
            output: {
                /**
                 * Keeps every chunk under Vite's 500 kB warning threshold.
                 *
                 * three.js cannot be tree-shaken here: react-three-fiber
                 * registers the whole library as its JSX element catalogue
                 * (`extend(THREE)` over a namespace import), which is what
                 * lets `<mesh>` and `<boxGeometry>` resolve at all, and a
                 * namespace consumed dynamically defeats tree-shaking
                 * outright. All of it ships whether or not the simulator
                 * builds a lathe.
                 *
                 * It does not all have to ship as one file, though. three
                 * splits its own distribution in two — `three.core.js` holds
                 * the maths, geometry and scene graph, `three.module.js` the
                 * renderer, materials and shader library — and the second
                 * imports the first, so they are separate modules that
                 * happened to land in one chunk. Naming them here keeps them
                 * apart, and pulls the scene libraries stacked on top into a
                 * chunk of their own.
                 *
                 * Splitting costs nothing at runtime: the same bytes over
                 * the same number of round trips, all four fetched in
                 * parallel behind the viewport's dynamic import. It buys
                 * finer cache granularity — a three.js patch release no
                 * longer invalidates drei — and it keeps the size warning
                 * meaningful, so the next chunk to cross 500 kB is a real
                 * regression rather than this one again.
                 */
                codeSplitting: {
                    groups: [
                        {
                            name: 'three-core',
                            test: /three[\\/]build[\\/]three\.core\.js/,
                        },
                        {
                            name: 'three-renderer',
                            test: /three[\\/]build[\\/]three\.module\.js/,
                        },
                        {
                            name: 'three-scene',
                            test: /@react-three[\\/](fiber|drei)/,
                        },
                    ],
                },
            },
        },
    },
    optimizeDeps: {
        /**
         * Leaves the physics engine's generated bindings out of the dev
         * server's dependency pre-bundle, because there must only ever be one
         * copy of them.
         *
         * wasm-bindgen splits the engine in two: a `.wasm` binary, and a
         * JavaScript glue module holding the heap that hands objects between
         * the two sides. The binary's imports are wired to the glue module the
         * browser loads for it — the real file in node_modules. Pre-bundling
         * inlines a second copy of that glue into the optimized
         * `@react-three/rapier` chunk, with a second, empty heap, and the app
         * calls through that one: it registers `performance` in heap A, hands
         * the index to the binary, and the binary reads it back out of heap B.
         * Every step of the world then threw `getObject(...).now is not a
         * function`, thousands of times a second, and the viewport locked up.
         *
         * Excluded, the chunk imports the same glue the binary does. This
         * costs nothing in production, where the whole graph is bundled at
         * once and the question of a second copy never comes up.
         */
        exclude: ['@dimforge/rapier3d'],
    },
    resolve: {
        alias: {
            // The physics engine's WASM ships as a real .wasm asset instead
            // of base64 inlined into the simulator bundle; see the shim.
            '@dimforge/rapier3d-compat': fileURLToPath(
                new URL(
                    './resources/js/lib/simulator/rapier-compat-shim.ts',
                    import.meta.url,
                ),
            ),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Inter', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
