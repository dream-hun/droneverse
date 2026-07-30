import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Deliberately not an extension of vite.config.ts.
 *
 * That config exists to build the app: it runs wayfinder (which shells out to
 * `php artisan`), compiles Tailwind, puts the React compiler in the Babel
 * chain, and swaps the physics engine for a WASM shim. None of that is
 * involved in exercising a plain TypeScript module, and all of it would make
 * the suite fail for reasons unrelated to the code under test. The `@` alias
 * is the only thing tests need from it, so that is the only thing repeated.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts', 'resources/js/**/*.test.tsx'],
    },
});
