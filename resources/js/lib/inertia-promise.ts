import type { router } from '@inertiajs/react';

type VisitOptions = NonNullable<Parameters<typeof router.delete>[1]>;

/**
 * Wrap an Inertia visit so a component can await it.
 *
 * `ConfirmDialog` and `FormDialog` drive their pending and close behaviour off
 * a promise, and Inertia's API is callbacks. The part worth having in one
 * place is the `onFinish` net: a promise settles once, so it only takes effect
 * when neither `onSuccess` nor `onError` fired — an aborted or superseded
 * visit — which is exactly the case that otherwise leaves a dialog spinning
 * with no way out but a reload.
 *
 * ```ts
 * visitAsPromise((options) => router.delete(destroy.url(id), options))
 * ```
 */
export function visitAsPromise(
    visit: (options: VisitOptions) => void,
    { onSuccess, onError, onFinish, ...options }: VisitOptions = {},
    failureMessage = 'The request did not go through',
): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        visit({
            preserveScroll: true,
            ...options,
            onSuccess: (page) => {
                onSuccess?.(page);
                resolve();
            },
            onError: (errors) => {
                onError?.(errors);
                reject(new Error(failureMessage));
            },
            onFinish: (visitEvent) => {
                onFinish?.(visitEvent);
                reject(new Error(failureMessage));
            },
        });
    });
}
