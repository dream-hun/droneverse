import { useEffect } from 'react';
import type { RefObject } from 'react';

/**
 * @react-three/fiber's own ResizeObserver-based auto-sizing can miss the
 * container's real dimensions on first mount inside a CSS grid/flex layout
 * (observed in practice: the canvas gets stuck at the browser's 300x150
 * default). A `resize` event forces it to re-measure and match the
 * container's actual size, but only once its own listeners are attached —
 * a single same-tick nudge is too early, so retry a few times over the
 * first second, then keep nudging on later container resizes (e.g. the
 * sidebar toggling).
 */
export function useCanvasResizeFix(
    containerRef: RefObject<HTMLDivElement | null>,
) {
    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const nudge = () => window.dispatchEvent(new Event('resize'));

        const timeouts = [0, 100, 300, 600, 1000].map((delay) =>
            window.setTimeout(nudge, delay),
        );
        const observer = new ResizeObserver(nudge);
        observer.observe(container);

        return () => {
            timeouts.forEach((timeout) => window.clearTimeout(timeout));
            observer.disconnect();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}
