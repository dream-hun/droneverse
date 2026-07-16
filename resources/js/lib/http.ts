function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Fire-and-forget JSON POST outside the Inertia visit lifecycle (used to submit
 * simulator run results without unmounting the canvas/worker). Laravel's default
 * CSRF cookie is read directly rather than routing through an Inertia visit.
 */
export async function postJson<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(
            `Request to ${url} failed with status ${response.status}`,
        );
    }

    return response.json() as Promise<T>;
}
