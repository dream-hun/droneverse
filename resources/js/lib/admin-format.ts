/**
 * Formatting for the admin area's numbers and moments.
 *
 * Money is not here: every amount arrives already formatted by
 * App\Actions\FormatMoney, which knows each currency's minor units. What is
 * here is everything the server sends raw — timestamps, byte counts, ratios —
 * and the one rule that runs through all of it: a value that is missing
 * renders as a dash, never as a zero, because "not reported" and "none" are
 * different things to be told on a monitoring screen.
 */

export const MISSING = '—';

function parse(iso: string | null | undefined): Date | null {
    if (!iso) {
        return null;
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** `25 Sep 2026, 14:05` in the viewer's locale. */
export function formatDateTime(iso: string | null | undefined): string {
    const date = parse(iso);

    return date
        ? date.toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : MISSING;
}

/** `25 Sep 2026` in the viewer's locale. */
export function formatDate(iso: string | null | undefined): string {
    const date = parse(iso);

    return date
        ? date.toLocaleDateString(undefined, { dateStyle: 'medium' })
        : MISSING;
}

const RELATIVE_STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 7],
    ['week', 4.34524],
    ['month', 12],
    ['year', Number.POSITIVE_INFINITY],
];

/**
 * `3 minutes ago`, walking up the units until the number is small.
 *
 * `now` is a parameter so the arithmetic is testable without a fake clock.
 */
export function formatRelative(
    iso: string | null | undefined,
    now: Date = new Date(),
): string {
    const date = parse(iso);

    if (!date) {
        return MISSING;
    }

    let value = (date.getTime() - now.getTime()) / 1000;

    if (Math.abs(value) < 45) {
        return 'just now';
    }

    const formatter = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
    });

    for (const [unit, size] of RELATIVE_STEPS) {
        if (Math.abs(value) < size) {
            return formatter.format(Math.round(value), unit);
        }

        value /= size;
    }

    return formatter.format(Math.round(value), 'year');
}

/** Binary units, one decimal: `1.5 MB`. */
export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) {
        return MISSING;
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const power =
        bytes > 0
            ? Math.min(
                  Math.floor(Math.log(bytes) / Math.log(1024)),
                  units.length - 1,
              )
            : 0;

    return `${(bytes / 1024 ** power).toFixed(power === 0 ? 0 : 1)} ${units[power]}`;
}

/** A 0–1 ratio as a whole percentage: `0.873` → `87%`. */
export function formatPercent(ratio: number | null | undefined): string {
    if (ratio === null || ratio === undefined || !Number.isFinite(ratio)) {
        return MISSING;
    }

    return `${Math.round(ratio * 100)}%`;
}

/** Grouped digits, or a dash for a count nobody could take. */
export function formatCount(count: number | null | undefined): string {
    if (count === null || count === undefined || !Number.isFinite(count)) {
        return MISSING;
    }

    return count.toLocaleString();
}

/** Seconds as the coarsest unit that still reads as a duration. */
export function formatDuration(seconds: number | null | undefined): string {
    if (
        seconds === null ||
        seconds === undefined ||
        !Number.isFinite(seconds)
    ) {
        return MISSING;
    }

    if (seconds < 60) {
        return `${Math.round(seconds)}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${(seconds / 3600).toFixed(1)}h`;
    }

    return `${(seconds / 86400).toFixed(1)}d`;
}

/** Creem's wire status, spelled for a person: `scheduled_cancel` → `Scheduled cancel`. */
export function humanizeStatus(status: string): string {
    const words = status.replaceAll('_', ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A URL slug suggested from a title: `Hover & Land` → `hover-land`.
 *
 * Only a suggestion — the server holds the real rule (lower-case letters,
 * numbers and single hyphens) and the author can type over this.
 */
export function slugify(title: string): string {
    return title
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
