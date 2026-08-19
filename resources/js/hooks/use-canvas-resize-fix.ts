import { useEffect } from 'react';
import type { RefObject } from 'react';

/**
 * @react-three/fiber's own ResizeObserver-based auto-sizing can miss the
 * container's real dimensions on first mount inside a CSS grid/flex layout
 * (observed in practice: the canvas gets stuck at the browser's 300x150
 * default). A `resize` event forces it to re-measure and match the
 * container's actual size, but only once its own listeners are attached —
 * a single same-tick nudge is too early, so retry a few times over the
 * first second, then nudge once for each later container resize (e.g. the
 * sidebar toggling). The observer callback is deferred to the next paint:
 * dispatching a resize synchronously from an observer can retrigger that
 * observer before the browser has delivered the previous notification.
 */
export function useCanvasResizeFix(
    containerRef: RefObject<HTMLDivElement | null>,
) {
    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        let frame: number | null = null;
        let width = container.clientWidth;
        let height = container.clientHeight;

        const nudge = () => window.dispatchEvent(new Event('resize'));
        const nudgeAfterLayout = () => {
            if (frame !== null) {
                return;
            }

            frame = window.requestAnimationFrame(() => {
                frame = null;
                nudge();
            });
        };

        const timeouts = [0, 100, 300, 600, 1000].map((delay) =>
            window.setTimeout(nudge, delay),
        );
        const observer = new ResizeObserver(([entry]) => {
            const nextWidth = entry.contentRect.width;
            const nextHeight = entry.contentRect.height;

            if (nextWidth === width && nextHeight === height) {
                return;
            }

            width = nextWidth;
            height = nextHeight;
            nudgeAfterLayout();
        });
        observer.observe(container);

        return () => {
            timeouts.forEach((timeout) => window.clearTimeout(timeout));

            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }

            observer.disconnect();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}
