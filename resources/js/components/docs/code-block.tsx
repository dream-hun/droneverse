import { tokenizeJs } from '@/lib/highlight-js';
import type { CodeToken, TokenKind } from '@/lib/highlight-js';
import { cn } from '@/lib/utils';

/**
 * Colours for each kind of token, as utility classes rather than as theme
 * tokens.
 *
 * A code block is the one place in the application that needs more than the
 * six semantic colours the palette defines, and inventing `--color-syntax-*`
 * for it would add a light and a dark value to the theme for every kind. The
 * pairs below are picked to hold their contrast in both.
 */
const TOKEN_CLASSES: Record<TokenKind, string> = {
    plain: '',
    comment: 'text-muted-foreground italic',
    string: 'text-emerald-700 dark:text-emerald-400',
    number: 'text-amber-700 dark:text-amber-400',
    keyword: 'text-violet-700 dark:text-violet-400',
    function: 'text-sky-700 dark:text-sky-400',
};

function Token({ token }: { token: CodeToken }) {
    const className = TOKEN_CLASSES[token.kind];

    return className ? (
        <span className={className}>{token.text}</span>
    ) : (
        <>{token.text}</>
    );
}

/**
 * A read-only, syntax-coloured block of JavaScript.
 *
 * Plain `<pre>` rather than the Monaco instance the mission editor uses: this
 * is prose, it is never edited, and mounting an editor per example would put
 * a megabyte of editor and a dozen web workers on a page that only has to be
 * read. Wrapping is off and the block scrolls on its own axis, because a
 * wrapped line of code silently changes what the indentation means.
 */
export function CodeBlock({
    code,
    className,
    label,
}: {
    code: string;
    className?: string;
    /** Accessible name, e.g. the title of the example this belongs to. */
    label?: string;
}) {
    const tokens = tokenizeJs(code);

    return (
        <pre
            aria-label={label}
            tabIndex={0}
            className={cn(
                'overflow-x-auto bg-muted/50 p-4 font-mono text-xs/relaxed focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                className,
            )}
        >
            <code>
                {tokens.map((token, index) => (
                    <Token key={index} token={token} />
                ))}
            </code>
        </pre>
    );
}
