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
 *
 * `docs` is the reference manual, which is public for the same reason the rest
 * of this list is: it is read before anybody has an account.
 *
 * `courses/` is the whole subtree: the catalog, a course and its written guide
 * are all open to guests, all linked from the marketing header, and all
 * indexed. The signed-in pilot reads them in the same chrome — they are one
 * page each, not two, and a mission opens the cockpit from there.
 *
 * resources/views/app.blade.php repeats this list for the same reason it
 * exists, and has to be changed with it: the pages below are dark whatever the
 * visitor prefers, and only the server can put that on `<html>` before the
 * first paint.
 */
export function bringsOwnChrome(component: string): boolean {
    return (
        component === 'welcome' ||
        component === 'pricing' ||
        component === 'docs' ||
        component.startsWith('legal/') ||
        component.startsWith('courses/')
    );
}
