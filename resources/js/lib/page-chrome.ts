/**
 * Whether a page draws its own header and footer.
 *
 * The public side of the site is not the app: it has a marketing header, a
 * marketing footer and no sidebar, and the sidebar shell it would otherwise be
 * wrapped in reads `auth.user` on the assumption that someone is signed in.
 * Give a guest that shell and the page breaks in the browser, which is exactly
 * the failure a server-side test cannot see — `assertInertia` is satisfied by
 * the component name long before React decides what to wrap it in.
 *
 * So the list lives here, on its own, where a test can hold it to account.
 * Adding a public page means adding it here in the same breath.
 */
export function bringsOwnChrome(component: string): boolean {
    return (
        component === 'welcome' ||
        component === 'pricing' ||
        component.startsWith('legal/')
    );
}
