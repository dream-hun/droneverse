import { describe, expect, it } from 'vitest';
import { isEmptyData, isRefreshing, resolveDataState } from '@/lib/data-state';

/**
 * The rules behind every skeleton and empty state in the app.
 *
 * The cases worth pinning are the three that look alike and are not: a prop
 * that has not arrived, a prop that arrived holding nothing, and a refresh
 * failing over data already on screen. Confusing the first two is what makes
 * an empty state flash before the rows land; confusing the third with a page
 * failure is what blanks a page the user was reading.
 */
describe('resolveDataState', () => {
    it('treats an undefined prop as still loading, not as empty', () => {
        expect(resolveDataState({ data: undefined })).toBe('loading');
    });

    it('treats null as loaded and absent', () => {
        expect(resolveDataState({ data: null })).toBe('empty');
    });

    it('reports an empty collection as empty, not as loading', () => {
        expect(resolveDataState({ data: [] })).toBe('empty');
    });

    it('reads through a Laravel paginator to its page of results', () => {
        expect(resolveDataState({ data: { data: [] } })).toBe('empty');
        expect(resolveDataState({ data: { data: [{ id: 1 }] } })).toBe('ready');
    });

    it('fails only while there is nothing to show', () => {
        expect(resolveDataState({ data: undefined, error: new Error() })).toBe(
            'error',
        );
        expect(resolveDataState({ data: null, error: new Error() })).toBe(
            'error',
        );
    });

    it('keeps rendering data when a refresh over it fails', () => {
        expect(
            resolveDataState({ data: [{ id: 1 }], error: new Error() }),
        ).toBe('ready');
    });

    it('keeps rendering data while a refresh is in flight', () => {
        expect(resolveDataState({ data: [{ id: 1 }], isLoading: true })).toBe(
            'ready',
        );
    });
});

describe('isEmptyData', () => {
    it('counts only collections as emptiable', () => {
        expect(isEmptyData([])).toBe(true);
        expect(isEmptyData([1])).toBe(false);
    });

    it('does not call a loaded record empty, however little it holds', () => {
        expect(isEmptyData({})).toBe(false);
        expect(isEmptyData(0)).toBe(false);
        expect(isEmptyData('')).toBe(false);
    });
});

describe('isRefreshing', () => {
    it('is a refresh only when there is something to refresh', () => {
        expect(isRefreshing({ data: [1], isLoading: true })).toBe(true);
        expect(isRefreshing({ data: undefined, isLoading: true })).toBe(false);
        expect(isRefreshing({ data: [1], isLoading: false })).toBe(false);
    });
});
