import { Head, setLayoutProps } from '@inertiajs/react';
import { Play as PlayIcon, RotateCcw, Square } from 'lucide-react';
import { useState } from 'react';
import { BriefingPanel } from '@/components/simulator/briefing-panel';
import { CodeEditor } from '@/components/simulator/code-editor';
import { ConsolePanel } from '@/components/simulator/console-panel';
import { ResultModal } from '@/components/simulator/result-modal';
import { SimulatorCanvas } from '@/components/simulator/simulator-canvas';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    SimulatorSession,
    useSimulatorResult,
    useSimulatorRunning,
} from '@/lib/simulator/session';
import { store as storeAttempt } from '@/routes/challenges/attempts';
import { show as showCourse } from '@/routes/courses';
import type {
    ChallengeDetail,
    ChallengeProgress,
    CourseDetail,
} from '@/types/simulator';

type PlayProps = {
    course: CourseDetail;
    challenge: ChallengeDetail;
    progress: ChallengeProgress;
};

export default function Play({ course, challenge, progress }: PlayProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Courses', href: '/courses' },
            { title: course.title, href: showCourse(course.slug) },
            { title: challenge.title, href: '#' },
        ],
    });

    const [code, setCode] = useState(
        progress.savedCode || challenge.starterCode,
    );
    const [session] = useState(() => new SimulatorSession());
    const isRunning = useSimulatorRunning(session);
    const result = useSimulatorResult(session);

    const attemptUrl = storeAttempt.url([course.slug, challenge.slug]);

    return (
        <>
            <Head title={`${challenge.title} · ${course.title}`} />

            <div className="grid h-[calc(100vh-4rem)] grid-cols-1 gap-4 p-4 lg:grid-cols-[280px_1fr_1fr]">
                <div className="min-w-0 overflow-y-auto rounded-xl border p-4">
                    <BriefingPanel challenge={challenge} />

                    <div className="mt-4 flex flex-wrap gap-2">
                        <Badge variant="outline" className="capitalize">
                            {progress.status.replace('_', ' ')}
                        </Badge>
                        {progress.bestScore > 0 && (
                            <Badge variant="outline">
                                Best: {progress.bestScore}
                            </Badge>
                        )}
                        {progress.stars > 0 && (
                            <Badge variant="outline">
                                {'★'.repeat(progress.stars)}
                            </Badge>
                        )}
                    </div>
                </div>

                <div className="flex min-w-0 flex-col gap-3">
                    <div className="flex items-center gap-2">
                        <Button
                            size="sm"
                            disabled={isRunning}
                            onClick={() => session.run(code)}
                        >
                            <PlayIcon /> Run
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!isRunning}
                            onClick={() => session.stop()}
                        >
                            <Square /> Stop
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setCode(challenge.starterCode)}
                        >
                            <RotateCcw /> Reset code
                        </Button>
                    </div>

                    <div className="min-h-0 flex-1 overflow-hidden rounded-xl border">
                        <CodeEditor
                            value={code}
                            onChange={setCode}
                            readOnly={isRunning}
                        />
                    </div>

                    <div className="h-40">
                        <ConsolePanel session={session} />
                    </div>
                </div>

                <div className="min-w-0 overflow-hidden rounded-xl border">
                    <SimulatorCanvas
                        session={session}
                        environment={challenge.environment}
                        successCriteria={challenge.successCriteria}
                        maxScore={challenge.maxScore}
                        attemptUrl={attemptUrl}
                    />
                </div>
            </div>

            <ResultModal
                result={result}
                onClose={() => session.clearResult()}
                onRetry={() => session.run(code)}
            />
        </>
    );
}
