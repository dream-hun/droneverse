import { CodeBlock } from '@/components/docs/code-block';
import { Prose } from '@/components/docs/prose';
import { Badge } from '@/components/ui/badge';
import type { CommandDoc, CommandGroup } from '@/types/docs';

/**
 * The commands a course is built on, described from config/drone-api.php.
 *
 * Grouped by what they do rather than listed alphabetically: a pilot reading
 * this is looking for "how do I turn", not for the letter T. Each entry is
 * the signature, one sentence on what it does, its arguments, what it comes
 * back with, and the things that are only obvious after you have flown into
 * something.
 */
export function CommandReference({ groups }: { groups: CommandGroup[] }) {
    return (
        <div className="space-y-8">
            {groups.map((group) => (
                <section key={group.key} className="space-y-4">
                    <h3 className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                        {group.label}
                    </h3>

                    <CommandList commands={group.commands} />
                </section>
            ))}
        </div>
    );
}

/**
 * The commands of one group, with no heading of their own.
 *
 * Split out because the manual at /docs gives each group a numbered section of
 * its own and does not want a second heading inside it — and because both
 * pages then describe a command in exactly the same markup.
 */
export function CommandList({ commands }: { commands: CommandDoc[] }) {
    return (
        <ul className="space-y-4">
            {commands.map((command) => (
                <li
                    key={command.name}
                    id={`command-${command.name}`}
                    className="scroll-mt-20 border-l-2 border-border pl-4"
                >
                    <CodeBlock
                        code={command.signature}
                        className="bg-transparent p-0 text-sm"
                        label={`${command.name} signature`}
                    />

                    <p className="mt-2 text-sm text-pretty text-muted-foreground">
                        <Prose text={command.summary} />
                    </p>

                    {command.params.length > 0 && (
                        <dl className="mt-3 space-y-1.5 text-sm">
                            {command.params.map((param) => (
                                <div
                                    key={param.name}
                                    className="flex flex-col gap-x-2 sm:flex-row"
                                >
                                    <dt className="flex shrink-0 items-center gap-1.5 font-mono text-xs">
                                        <span>{param.name}</span>
                                        <span className="text-muted-foreground">
                                            {param.type}
                                        </span>
                                        {param.optional && (
                                            <Badge
                                                variant="outline"
                                                className="font-sans"
                                            >
                                                optional
                                            </Badge>
                                        )}
                                    </dt>
                                    <dd className="text-muted-foreground">
                                        <Prose text={param.description} />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}

                    {command.returns && (
                        <p className="mt-3 text-sm">
                            <span className="font-medium">Returns: </span>
                            <span className="text-muted-foreground">
                                <Prose text={command.returns} />
                            </span>
                        </p>
                    )}

                    {command.notes.length > 0 && (
                        <ul className="mt-3 space-y-1.5 text-sm text-muted-foreground">
                            {command.notes.map((note) => (
                                <li
                                    key={note}
                                    className="relative pl-4 text-pretty before:absolute before:left-0 before:text-primary before:content-['—']"
                                >
                                    <Prose text={note} />
                                </li>
                            ))}
                        </ul>
                    )}
                </li>
            ))}
        </ul>
    );
}
