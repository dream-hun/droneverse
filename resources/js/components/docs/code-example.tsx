import { Check, Copy } from 'lucide-react';
import { toast } from 'sonner';
import { CodeBlock } from '@/components/docs/code-block';
import { Prose } from '@/components/docs/prose';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import type { CodeExample as CodeExampleData } from '@/types/docs';

/**
 * One worked example: what it demonstrates, the program itself, and a way to
 * get it into the editor.
 *
 * The copy button is the point of the page. These examples exist to be lifted
 * into a mission and run, and a pilot retyping thirty lines out of a browser
 * is a pilot who stops reading the documentation.
 *
 * The heading carries the example's slug as its id, so a section of the guide
 * can be linked to directly — which is what somebody answering a question in
 * a forum actually needs.
 */
export function CodeExample({ example }: { example: CodeExampleData }) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === example.code;

    async function handleCopy() {
        if (await copy(example.code)) {
            toast.success('Example copied', {
                description: 'Paste it into the mission editor and hit Run.',
            });

            return;
        }

        toast.error('Could not copy', {
            description: 'Select the code and copy it by hand.',
        });
    }

    return (
        <article className="space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1">
                    <h3
                        id={example.slug}
                        className="scroll-mt-20 font-medium tracking-tight"
                    >
                        {example.title}
                    </h3>
                    <p className="max-w-2xl text-sm text-pretty text-muted-foreground">
                        <Prose text={example.description} />
                    </p>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    onClick={handleCopy}
                >
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>

            <CodeBlock code={example.code} label={example.title} />
        </article>
    );
}
