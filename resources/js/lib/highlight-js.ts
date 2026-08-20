/**
 * A very small JavaScript tokenizer, for rendering the documentation's code
 * examples.
 *
 * Deliberately not a parser and deliberately not a dependency. The examples
 * are short, hand-written and all of one dialect — the flight programs a
 * pilot types into the mission editor — so what is needed is comments,
 * strings, numbers, keywords and call names picked out of otherwise plain
 * text. A syntax highlighting library is several hundred kilobytes to do
 * that, on a page whose whole job is to be readable quickly.
 *
 * What it does not handle, because nothing in the examples uses it: regular
 * expression literals (a `/` is plain text here), and the interpolated
 * expressions inside a template literal, which are coloured as part of the
 * string. Both degrade to plain text rather than to a wrong colour.
 */

export type TokenKind =
    'plain' | 'comment' | 'string' | 'number' | 'keyword' | 'function';

export type CodeToken = {
    text: string;
    kind: TokenKind;
};

const KEYWORDS = new Set([
    'async',
    'await',
    'break',
    'case',
    'catch',
    'class',
    'const',
    'continue',
    'default',
    'delete',
    'do',
    'else',
    'export',
    'extends',
    'false',
    'finally',
    'for',
    'function',
    'if',
    'import',
    'in',
    'instanceof',
    'let',
    'new',
    'null',
    'of',
    'return',
    'switch',
    'this',
    'throw',
    'true',
    'try',
    'typeof',
    'undefined',
    'var',
    'void',
    'while',
    'yield',
]);

/*
 * One pass, four alternatives, in the order they have to win at a shared
 * position: a comment, then any of the three string forms, then a number,
 * then a bare identifier. Positions are scanned left to right, so a `//`
 * inside a string never opens a comment — the string starts earlier and
 * matches first.
 */
const PATTERN =
    /(\/\/[^\n]*|\/\*[\s\S]*?\*\/)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`)|(\b\d+(?:\.\d+)?\b)|([A-Za-z_$][\w$]*)/g;

/**
 * Splits source into coloured runs. Every character of the input appears in
 * exactly one token, so joining the texts back together reproduces the
 * source exactly — which is what the copy button relies on.
 */
export function tokenizeJs(source: string): CodeToken[] {
    const tokens: CodeToken[] = [];
    let lastIndex = 0;

    // `exec` in a loop rather than `matchAll`, because the gaps between
    // matches are tokens too and only the indices give us those.
    PATTERN.lastIndex = 0;

    let match = PATTERN.exec(source);

    while (match !== null) {
        if (match.index > lastIndex) {
            tokens.push({
                text: source.slice(lastIndex, match.index),
                kind: 'plain',
            });
        }

        const [text, comment, string, number, identifier] = match;

        if (comment !== undefined) {
            tokens.push({ text, kind: 'comment' });
        } else if (string !== undefined) {
            tokens.push({ text, kind: 'string' });
        } else if (number !== undefined) {
            tokens.push({ text, kind: 'number' });
        } else if (identifier !== undefined) {
            tokens.push({
                text,
                kind: identifierKind(source, match.index, identifier),
            });
        }

        lastIndex = match.index + text.length;
        match = PATTERN.exec(source);
    }

    if (lastIndex < source.length) {
        tokens.push({ text: source.slice(lastIndex), kind: 'plain' });
    }

    return tokens;
}

/**
 * A keyword, the name of something being called, or plain text.
 *
 * "Being called" is decided by the next non-space character being an open
 * paren, which is what makes `drone.takeoff(` read as a call and `contact.x`
 * read as plain. It is a lie for `if (`, which is why keywords are checked
 * first and win.
 */
function identifierKind(
    source: string,
    index: number,
    identifier: string,
): TokenKind {
    if (KEYWORDS.has(identifier)) {
        return 'keyword';
    }

    const rest = source.slice(index + identifier.length);

    return /^\s*\(/.test(rest) ? 'function' : 'plain';
}
