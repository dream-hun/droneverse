import type { ReactNode } from 'react';
import { StarRating } from '@/components/star-rating';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { RunResult } from '@/types/simulator';

type ResultModalProps = {
    result: RunResult | null;
    onClose: () => void;
    onRetry: () => void;
};

function Stat({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="rounded-md border p-3">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-lg font-semibold">{value}</div>
        </div>
    );
}

export function ResultModal({ result, onClose, onRetry }: ResultModalProps) {
    return (
        <Dialog
            open={result !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {result?.completed
                            ? 'Challenge complete!'
                            : 'Run finished'}
                    </DialogTitle>
                    <DialogDescription>
                        {result?.timedOut
                            ? 'You ran out of time.'
                            : result?.completed
                              ? 'Nice flying — your progress has been saved.'
                              : 'Not quite there yet — check the objectives and try again.'}
                    </DialogDescription>
                </DialogHeader>

                {result && (
                    <div className="grid grid-cols-2 gap-3 text-sm">
                        <Stat label="Score" value={`${result.score}`} />
                        <Stat
                            label="Stars"
                            value={
                                <StarRating value={result.stars} size="lg" />
                            }
                        />
                        <Stat
                            label="Waypoints"
                            value={`${result.waypointsHit}/${result.waypointsTotal}`}
                        />
                        <Stat
                            label="Collisions"
                            value={`${result.collisions}`}
                        />
                        {result.photoTargetsTotal > 0 && (
                            <Stat
                                label="Photo targets"
                                value={`${result.photoTargetsHit}/${result.photoTargetsTotal}`}
                            />
                        )}
                        {result.photoTargetsTotal === 0 &&
                            result.photosMissing > 0 && (
                                <Stat
                                    label="Photos"
                                    value={`${result.photosTaken} (${result.photosMissing} more needed)`}
                                />
                            )}
                        {result.photoTargetsTotal === 0 &&
                            result.photosMissing === 0 &&
                            result.photosTaken > 0 && (
                                <Stat
                                    label="Photos"
                                    value={`${result.photosTaken}`}
                                />
                            )}
                        {result.washRequired && (
                            <Stat
                                label="Drone wash"
                                value={
                                    result.washed ? 'Complete' : 'Skipped'
                                }
                            />
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <Button onClick={onRetry}>Try again</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
