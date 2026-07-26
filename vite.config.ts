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
                advancedChunks: {
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
                bunny('Instrument Sans', {
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
