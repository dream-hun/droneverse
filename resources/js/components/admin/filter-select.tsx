import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Option } from '@/types/admin';

/** Radix refuses an empty item value, so "no filter" needs a name of its own. */
const ALL = '__all__';

type FilterSelectProps = {
    value: string | null;
    options: Option[];
    onChange: (value: string | null) => void;
    /** What choosing nothing means — "All roles", "Any status". */
    allLabel: string;
    label: string;
};

export function FilterSelect({
    value,
    options,
    onChange,
    allLabel,
    label,
}: FilterSelectProps) {
    return (
        <Select
            value={value ?? ALL}
            onValueChange={(next) => onChange(next === ALL ? null : next)}
        >
            <SelectTrigger aria-label={label} className="w-full sm:w-44">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
