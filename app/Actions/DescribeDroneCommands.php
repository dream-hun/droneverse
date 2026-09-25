<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Arr;

/**
 * The named `drone.*` commands, described from the API reference and broken
 * under the reference's own headings.
 *
 * The one place config/drone-api.php is turned into something renderable, so
 * a course guide naming eight commands and the manual naming all fifteen
 * describe each of them in identical words. A signature is written once.
 */
final readonly class DescribeDroneCommands
{
    /**
     * Ordered by the group list rather than by the order a caller happens to
     * name its commands in, so every reference table reads the same way
     * round: fly first, then sense, then shoot.
     *
     * A name with no entry in the reference is dropped rather than rendered
     * as a blank row. That is a typo in a course's `commands` list, and
     * CourseDocsTest fails on it — this is only what keeps the page from
     * showing the mistake to a pilot in the meantime.
     *
     * @param  array<int, string>  $names
     * @return array<int, array{key: string, label: string, commands: array<int, array<string, mixed>>}>
     */
    public function handle(array $names): array
    {
        $reference = config()->array('drone-api.commands', []);
        $labels = config()->array('drone-api.groups', []);
        $groups = [];

        foreach (array_keys($labels) as $key) {
            $commands = [];

            foreach ($names as $name) {
                $command = $reference[$name] ?? null;

                if (! is_array($command)) {
                    continue;
                }

                if (($command['group'] ?? null) !== $key) {
                    continue;
                }

                $commands[] = [
                    'name' => $name,
                    'signature' => Arr::string($command, 'signature', ''),
                    'summary' => Arr::string($command, 'summary', ''),
                    'params' => array_values(Arr::array($command, 'params', [])),
                    'returns' => $command['returns'] ?? null,
                    'notes' => array_values(Arr::array($command, 'notes', [])),
                ];
            }

            if ($commands === []) {
                continue;
            }

            $groups[] = [
                'key' => (string) $key,
                'label' => Arr::string($labels, $key),
                'commands' => $commands,
            ];
        }

        return $groups;
    }

    /**
     * Every command the reference describes, in the order it declares them.
     *
     * @return array<int, string>
     */
    public function everyName(): array
    {
        return array_keys((array) config('drone-api.commands', []));
    }
}
