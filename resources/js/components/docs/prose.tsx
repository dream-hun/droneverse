import { Fragment } from 'react';
import { splitInlineCode } from '@/lib/inline-code';

/**
 * A line of documentation prose, with its `backticked` spans set as code.
 *
 * Renders a fragment rather than an element of its own, so the caller keeps
 * whatever `<p>`, `<li>` or `<dd>` the text belongs in and this does not add
 * a wrapper to every sentence on the page.
 */
export function Prose({ text }: { text: string }) {
    return (
        <>
            {splitInlineCode(text).map((segment, index) =>
                segment.code ? (
                    <code
                        key={index}
                        className="bg-muted px-1 py-0.5 font-mono text-[0.9em] text-foreground"
                    >
                        {segment.text}
                    </code>
                ) : (
                    <Fragment key={index}>{segment.text}</Fragment>
                ),
            )}
        </>
    );
}
