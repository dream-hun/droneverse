import { Clock, error, setConsoleFunction, warn } from 'three';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { MockInstance } from 'vitest';
import { filterUpstreamThreeWarnings } from '@/lib/simulator/three-console';

/**
 * The Clock case is exercised by constructing three's own deprecated class
 * rather than by replaying the string it logs. That is the point of the test:
 * the filter matches a message three.js owns, so the installed three has to be
 * the thing that decides whether the filter still matches. When a release
 * rewords the notice — or drops Clock and the notice with it — this fails and
 * the filter gets revisited, instead of quietly passing while the console
 * fills up again.
 */
describe('filterUpstreamThreeWarnings', () => {
    let warnSpy: MockInstance;
    let errorSpy: MockInstance;

    beforeEach(() => {
        warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        errorSpy = vi
            .spyOn(console, 'error')
            .mockImplementation(() => undefined);
        filterUpstreamThreeWarnings();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('drops the Clock deprecation react-three-fiber cannot avoid', () => {
        new Clock();

        expect(warnSpy).not.toHaveBeenCalled();
    });

    /**
     * The control the assertion above needs to mean anything.
     *
     * `not.toHaveBeenCalled()` passes just as readily when the filter is
     * doing nothing as when it is doing its job — and one upstream change
     * produces exactly that: three keeps `Clock` but drops the deprecation
     * notice. (Dropping `Clock` itself is already caught, since the import
     * would fail.) Left alone, the module would quietly become a global
     * console patch installed for no reason, with a green test over it.
     *
     * So: prove the warning is still there to be filtered.
     */
    it('is filtering a warning three.js still raises', () => {
        // A passthrough in place of the filter, so three's message reaches
        // the spy exactly as it would with no hook installed at all.
        setConsoleFunction((type, message, ...params) => {
            console[type](message, ...params);
        });

        new Clock();

        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('THREE.Clock'),
        );
    });

    it('passes every other three.js warning through', () => {
        warn('WebGLRenderer: Context Lost.', { detail: 1 });

        expect(warnSpy).toHaveBeenCalledWith(
            'THREE.WebGLRenderer: Context Lost.',
            {
                detail: 1,
            },
        );
    });

    it('passes three.js errors through as errors', () => {
        error('WebGLProgram: Shader Error');

        expect(errorSpy).toHaveBeenCalledWith(
            'THREE.WebGLProgram: Shader Error',
        );
        expect(warnSpy).not.toHaveBeenCalled();
    });
});
