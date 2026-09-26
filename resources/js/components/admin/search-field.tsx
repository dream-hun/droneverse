import { Search } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';

type SearchFieldProps = {
    value: string;
    onSearch: (value: string) => void;
    placeholder: string;
    /** Names the field for screen readers; the placeholder is not a label. */
    label: string;
};

/**
 * A search box that asks the server when the reader presses Enter.
 *
 * On submit rather than on every keystroke: each search is a page visit, and a
 * visit per letter typed is a request per letter for an answer nobody reads.
 */
export function SearchField({
    value,
    onSearch,
    placeholder,
    label,
}: SearchFieldProps) {
    const [draft, setDraft] = useState(value);

    return (
        <form
            role="search"
            className="relative w-full sm:max-w-xs"
            onSubmit={(event) => {
                event.preventDefault();
                onSearch(draft.trim());
            }}
        >
            <Search
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                type="search"
                aria-label={label}
                placeholder={placeholder}
                value={draft}
                onChange={(event) => {
                    setDraft(event.target.value);

                    // Clearing the box is a search too: it should bring the
                    // whole list back without an Enter nobody thinks to press.
                    if (event.target.value === '' && value !== '') {
                        onSearch('');
                    }
                }}
                className="pl-8"
            />
        </form>
    );
}
