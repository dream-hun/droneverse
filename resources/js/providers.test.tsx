// @vitest-environment jsdom

import { readFileSync } from 'node:fs';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Providers } from '@/providers';

/**
 * The flash listener is Inertia's; the providers only have to mount it.
 *
 * Hoisted because `vi.mock` factories are lifted above the module body, so a
 * plain `const` declared here would not exist yet when the factory runs.
 */
const { routerOn } = vi.hoisted(() => ({
    routerOn: vi.fn(() => () => undefined),
}));

vi.mock('@inertiajs/react', () => ({
    router: { on: routerOn },
}));

beforeEach(() => {
    // The toaster asks the browser whether the pilot prefers reduced motion,
    // and jsdom has no answer to give.
    vi.stubGlobal('matchMedia', () => ({
        matches: false,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    }));
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Providers', () => {
    it('renders the page it wraps', () => {
        render(
            <Providers>
                <p>Mission control</p>
            </Providers>,
        );

        expect(screen.getByText('Mission control')).toBeDefined();
    });

    /**
     * The reason this component exists at all. `withApp` used to return the
     * bare provider tree; it became a component so `useFlashToast` had
     * somewhere to live, and a controller's flashed toast reaches the screen
     * only if that subscription is actually mounted. Without this assertion
     * the hook could be deleted outright and every test here would still
     * pass.
     */
    it('subscribes to the flash event Inertia raises', () => {
        render(
            <Providers>
                <p>Mission control</p>
            </Providers>,
        );

        expect(routerOn).toHaveBeenCalledWith('flash', expect.any(Function));
    });
});

/**
 * The declaration shapes `react-refresh/babel` registers as components.
 *
 * Anchored to the start of a line so a component mentioned inside a string or
 * a comment does not count, and capitalised because that is the heuristic
 * react-refresh itself uses to decide what is a component.
 */
const COMPONENT_DECLARATIONS = [
    // function App() {} — with any combination of export, default and async.
    /^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*[A-Z]/m,
    // const/let/var App = … — arrow functions and function expressions alike.
    /^\s*(?:export\s+)?(?:const|let|var)\s+[A-Z]\w*\s*=/m,
    // class App extends Component {} — still registered, still a boundary.
    /^\s*(?:export\s+)?(?:default\s+)?class\s+[A-Z]/m,
];

describe('the Inertia entry', () => {
    /**
     * Why a test reads a file instead of rendering something.
     *
     * React Refresh treats any module that declares a component as a refresh
     * boundary, and rolldown's transform gives a boundary a self-import at a
     * timestamped URL — `./app.tsx?t=1786780229455`. Blade's `@vite` tag
     * loads the entry without that timestamp, so the two URLs are two modules
     * and the entry's body runs twice: two `createInertiaApp()` calls, a
     * second React root laid over the first, and a discarded R3F canvas that
     * takes its WebGL context down half a second later.
     *
     * There is nothing to observe from inside the app — the damage is done by
     * the dev server, to the file that boots it. What can be pinned is the
     * shape that avoids it, which is why `Providers` lives in its own module.
     */
    it('declares no components, so it is never a refresh boundary', () => {
        // Relative to the repo root, which is where vitest resolves its own
        // `include` globs from and so where it is always launched.
        const entry = readFileSync('resources/js/app.tsx', 'utf8');

        for (const pattern of COMPONENT_DECLARATIONS) {
            expect(entry).not.toMatch(pattern);
        }
    });

    /**
     * The guard above is a negative assertion against a file that passes
     * today, which is the shape of test that quietly stops testing anything.
     * Its first version matched only `function X` and `const X =`, so
     * `export default function App() {}` — the likeliest thing anybody would
     * paste into an entry file — walked straight past it, as did `let`, `var`
     * and class components. Every shape react-refresh registers is listed
     * here so the patterns are held to catching them.
     */
    it.each([
        ['a function declaration', 'function App() {}'],
        ['an exported function', 'export function App() {}'],
        ['a default-exported function', 'export default function App() {}'],
        ['an async function', 'export async function App() {}'],
        ['a const arrow', 'const App = () => null;'],
        ['an exported const arrow', 'export const App = () => null;'],
        ['a let binding', 'let App = () => null;'],
        ['a var binding', 'var App = function () {};'],
        ['a class component', 'class App extends Component {}'],
        [
            'an exported class component',
            'export class App extends Component {}',
        ],
        [
            'a default-exported class component',
            'export default class App extends Component {}',
        ],
    ])('catches %s', (_label, declaration) => {
        expect(
            COMPONENT_DECLARATIONS.some((pattern) => pattern.test(declaration)),
        ).toBe(true);
    });

    // The entry's own shapes, which must keep passing: `withApp` is an object
    // method, not a declaration, and react-refresh does not register it.
    it.each([
        ['an object method returning JSX', '    withApp(app) {'],
        ['a lowercase binding', "const appName = 'DroneVerse';"],
        ['an import of a component', 'import AppLayout from "@/layouts";'],
    ])('does not flag %s', (_label, line) => {
        expect(
            COMPONENT_DECLARATIONS.some((pattern) => pattern.test(line)),
        ).toBe(false);
    });
});
