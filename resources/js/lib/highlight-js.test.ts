import { describe, expect, it } from 'vitest';
import { tokenizeJs } from './highlight-js';
import type { CodeToken } from './highlight-js';

/** The property the copy button depends on: nothing is lost or duplicated. */
function rejoin(tokens: CodeToken[]): string {
    return tokens.map((token) => token.text).join('');
}

function kindOf(tokens: CodeToken[], text: string): string | undefined {
    return tokens.find((token) => token.text === text)?.kind;
}

describe('tokenizeJs', () => {
    it('reproduces the source exactly', () => {
        const source = [
            'async function main(drone) {',
            '    // climb to 3 m',
            '    await drone.takeoff(3);',
            "    console.log('up', { x: 1.5 });",
            '}',
        ].join('\n');

        expect(rejoin(tokenizeJs(source))).toBe(source);
    });

    it('marks keywords, calls, numbers and strings', () => {
        const tokens = tokenizeJs("await drone.takeoff(1.5); log('hi');");

        expect(kindOf(tokens, 'await')).toBe('keyword');
        expect(kindOf(tokens, 'takeoff')).toBe('function');
        expect(kindOf(tokens, '1.5')).toBe('number');
        expect(kindOf(tokens, "'hi'")).toBe('string');
    });

    it('leaves plain property access alone', () => {
        const tokens = tokenizeJs('const y = contact.distance;');

        expect(kindOf(tokens, 'distance')).toBe('plain');
        expect(kindOf(tokens, 'const')).toBe('keyword');
    });

    it('prefers a keyword over a call for control flow', () => {
        const tokens = tokenizeJs('if (ready) { for (const a of b) {} }');

        expect(kindOf(tokens, 'if')).toBe('keyword');
        expect(kindOf(tokens, 'for')).toBe('keyword');
        expect(kindOf(tokens, 'of')).toBe('keyword');
    });

    it('does not open a comment inside a string', () => {
        const tokens = tokenizeJs("const url = 'https://example.test/x';");

        expect(kindOf(tokens, "'https://example.test/x'")).toBe('string');
        expect(tokens.some((token) => token.kind === 'comment')).toBe(false);
    });

    it('takes a block comment whole, including the code-like text in it', () => {
        const tokens = tokenizeJs('/* await drone.land(); */ const a = 1;');

        expect(kindOf(tokens, '/* await drone.land(); */')).toBe('comment');
        expect(kindOf(tokens, 'const')).toBe('keyword');
    });

    it('handles a template literal as one string', () => {
        const tokens = tokenizeJs('takePhoto(`survey z=${z}`);');

        expect(kindOf(tokens, '`survey z=${z}`')).toBe('string');
    });

    it('returns a single plain token for text with nothing to colour', () => {
        expect(tokenizeJs('  + - ')).toEqual([
            { text: '  + - ', kind: 'plain' },
        ]);
    });

    it('handles an empty string', () => {
        expect(tokenizeJs('')).toEqual([]);
    });
});
