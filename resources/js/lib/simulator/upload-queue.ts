/**
 * Runs background uploads one at a time.
 *
 * A mission can fire the shutter several times in quick succession, and each
 * frame is a megabyte-plus data URL. Posting them all the moment they are
 * captured puts every one on the wire at once, which is the worst case for
 * both the pilot's uplink and the server handling the run. Queueing them
 * costs nothing — the flight never waits on the network either way — and
 * turns a burst into a trickle.
 *
 * Failures are contained: a rejected task settles the queue so the ones
 * behind it still run, and the caller still sees its own rejection.
 */
export type UploadQueue = {
    enqueue: <T>(task: () => Promise<T>) => Promise<T>;
};

export function createUploadQueue(): UploadQueue {
    let tail: Promise<unknown> = Promise.resolve();

    return {
        enqueue<T>(task: () => Promise<T>): Promise<T> {
            const result = tail.then(task);

            tail = result.catch(() => undefined);

            return result;
        },
    };
}
