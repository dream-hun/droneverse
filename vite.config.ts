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
        /**
         * Above the one chunk that legitimately sits over Vite's 500 kB
         * default, so the warning goes back to meaning something.
         *
         * That chunk is three.js, entire, at ~884 kB. It cannot be made
         * smaller: react-three-fiber registers the whole library as its JSX
         * element catalogue (`extend(THREE)` over a namespace import), which
         * is what lets `<mesh>` and `<boxGeometry>` resolve at all. A
         * namespace consumed dynamically defeats tree-shaking outright — no
         * bundler can prove which exports are unused — so the animation
         * system, the audio graph and every geometry the simulator never
         * builds ship along with the renderer.
         *
         * What is under our control is already done: it loads only with the
         * 3D viewport, behind a dynamic import, and the marketing showcase
         * and the simulator share the one copy.
         *
         * The headroom here is deliberately thin. A warning that fires on
         * every build is one nobody reads, and the point of keeping this
         * limit close is that the next chunk to cross it will be a real
         * regression.
         */
        chunkSizeWarningLimit: 1000,
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
