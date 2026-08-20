import { describe, expect, it } from 'vitest';
import { splitInlineCode } from './inline-code';

describe('splitInlineCode', () => {
    it('pulls a code span out of a sentence', () => {
        expect(splitInlineCode('Call `drone.land()` to finish.')).toEqual([
            { text: 'Call ', code: false },
            { text: 'drone.land()', code: true },
            { text: ' to finish.', code: false },
        ]);
    });

    it('handles several spans', () => {
        const segments = splitInlineCode('`turn()` then `moveForward()`.');

        expect(segments.filter((segment) => segment.code)).toEqual([
            { text: 'turn()', code: true },
            { text: 'moveForward()', code: true },
        ]);
    });

    it('leaves prose without backticks in one piece', () => {
        expect(splitInlineCode('Nothing to mark up here.')).toEqual([
            { text: 'Nothing to mark up here.', code: false },
        ]);
    });

    it('leaves an unclosed backtick as literal text', () => {
        expect(splitInlineCode('An ` on its own.')).toEqual([
            { text: 'An ` on its own.', code: false },
        ]);
    });

    it('does not make an empty span out of two backticks', () => {
        expect(splitInlineCode('a `` b')).toEqual([
            { text: 'a `` b', code: false },
        ]);
    });

    it('handles a span at either end', () => {
        expect(splitInlineCode('`await` it')).toEqual([
            { text: 'await', code: true },
            { text: ' it', code: false },
        ]);

        expect(splitInlineCode('it is `await`')).toEqual([
            { text: 'it is ', code: false },
            { text: 'await', code: true },
        ]);
    });

    it('loses no text', () => {
        const source = 'Use `a`, then `b`, but never `c` twice.';
        const rejoined = splitInlineCode(source)
            .map((segment) =>
                segment.code ? `\`${segment.text}\`` : segment.text,
            )
            .join('');

        expect(rejoined).toBe(source);
    });

    it('handles an empty string', () => {
        expect(splitInlineCode('')).toEqual([]);
    });
});
