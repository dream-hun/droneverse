<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * The two readings every part of the admin system report needs.
 *
 * Both exist because that page has to render when things are broken: a
 * config value somebody has unset or emptied still needs a label, and an
 * exception still needs a line of text that fits a table cell rather than a
 * page of stack.
 */
trait DescribesSystem
{
    /**
     * A config value as a string, or the default when it is unset, empty or
     * not a string at all.
     */
    private function configString(string $key, string $default = ''): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function firstLine(string $text): string
    {
        return Str::limit(mb_trim(strtok($text, "\n") ?: $text), 300);
    }
}
