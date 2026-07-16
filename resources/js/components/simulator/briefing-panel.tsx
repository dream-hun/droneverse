import { Badge } from '@/components/ui/badge';
import type { ChallengeDetail } from '@/types/simulator';

export function BriefingPanel({ challenge }: { challenge: ChallengeDetail }) {
    return (
        <div className="space-y-3">
            <Badge variant="secondary" className="capitalize">
                {challenge.difficulty}
            </Badge>
            <h2 className="text-lg font-semibold">{challenge.title}</h2>
            <p className="text-sm whitespace-pre-line text-muted-foreground">
                {challenge.briefing}
            </p>
        </div>
    );
}
