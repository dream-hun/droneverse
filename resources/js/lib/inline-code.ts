/**
 * Splits documentation prose into plain runs and `backticked` code spans.
 *
 * The guides in config/course-docs.php are written the way anybody writes
 * technical prose — `await drone.takeoff()` in backticks — and without this
 * the backticks reach the page as backticks. That is the whole scope: one
 * inline construct, no blocks, no emphasis, no links. Anything more and the
 * right answer is a real Markdown renderer rather than a bigger regex.
 *
 * An unclosed backtick is left as literal text rather than swallowing the
 * rest of the sentence into a code span, because a typo in a guide should
 * look like a typo and not like a formatting bug.
 */

export type ProseSegment = {
    text: string;
    code: boolean;
};

/*
 * Pairs only: a backtick, at least one non-backtick character, a backtick.
 * An odd one left over matches nothing and falls through as plain text.
 */
const INLINE_CODE = /`([^`]+)`/g;

export function splitInlineCode(text: string): ProseSegment[] {
    const segments: ProseSegment[] = [];
    let lastIndex = 0;

    INLINE_CODE.lastIndex = 0;

    let match = INLINE_CODE.exec(text);

    while (match !== null) {
        if (match.index > lastIndex) {
            segments.push({
                text: text.slice(lastIndex, match.index),
                code: false,
            });
        }

        segments.push({ text: match[1], code: true });

        lastIndex = match.index + match[0].length;
        match = INLINE_CODE.exec(text);
    }

    if (lastIndex < text.length) {
        segments.push({ text: text.slice(lastIndex), code: false });
    }

    return segments;
}
