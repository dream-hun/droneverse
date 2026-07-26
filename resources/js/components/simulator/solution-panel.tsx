import { BookOpen, Lock } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import type { ChallengeSolution } from '@/types/simulator';

type SolutionPanelProps = {
    solution: ChallengeSolution;
    attempts: number;
    onLoad: (code: string) => void;
};

/**
 * Reference-solution panel for the mission briefing column.
 *
 * While the solution is locked it only shows how far off the unlock is —
 * the source itself never reaches the browser until the server sends it.
 */
export function SolutionPanel({
    solution,
    attempts,
    onLoad,
}: SolutionPanelProps) {
    const [open, setOpen] = useState(false);

    if (!solution.exists) {
        return null;
    }

    if (!solution.unlocked || solution.code === null) {
        const flown = Math.min(attempts, solution.attemptsRequired);
        const remaining = solution.attemptsRequired - flown;

        return (
            <div className="mt-4 rounded-lg border border-dashed p-3">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <Lock className="size-4 text-muted-foreground" />
                    Reference solution
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    Unlocks when you complete the mission, or after{' '}
                    {remaining === 1 ? '1 more run' : `${remaining} more runs`}.
                </p>
                <Progress
                    className="mt-3"
                    value={(flown / solution.attemptsRequired) * 100}
                />
                <p className="mt-1.5 text-xs text-muted-foreground">
                    {flown} of {solution.attemptsRequired} runs flown
                </p>
            </div>
        );
    }

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                className="mt-4 w-full"
                onClick={() => setOpen(true)}
            >
                <BookOpen /> Reference solution
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Reference solution</DialogTitle>
                        <DialogDescription>
                            One complete flight plan that scores this mission.
                            It is not the only way to fly it — compare it with
                            your own run.
                        </DialogDescription>
                    </DialogHeader>

                    <pre className="max-h-[55vh] overflow-auto rounded-md bg-muted p-4 text-xs leading-relaxed">
                        <code>{solution.code}</code>
                    </pre>

                    <DialogFooter showCloseButton>
                        <Button
                            onClick={() => {
                                onLoad(solution.code ?? '');
                                setOpen(false);
                            }}
                        >
                            Load into editor
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
