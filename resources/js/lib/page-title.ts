/**
 * The document title for a page: its own title with the site name after it.
 *
 * A title that already names the site is left as it is. The server writes the
 * finished, branded title into the `<head>` of every page (see
 * App\Actions\BuildPageHead), and Inertia runs that title through this
 * callback too, so branding it again would print
 * "Pricing - DroneVerse - DroneVerse".
 */
export function pageTitle(title: string, siteName: string): string {
    if (!title) {
        return siteName;
    }

    return title.includes(siteName) ? title : `${title} - ${siteName}`;
}
