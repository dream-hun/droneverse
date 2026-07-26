import { Head, setLayoutProps } from '@inertiajs/react';
import {
    Fan,
    Play as PlayIcon,
    RotateCcw,
    Square,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { BriefingPanel } from '@/components/simulator/briefing-panel';
import { CodeEditor } from '@/components/simulator/code-editor';
import { ConsolePanel } from '@/components/simulator/console-panel';
import { ResultModal } from '@/components/simulator/result-modal';
import { SimulatorCanvas } from '@/components/simulator/simulator-canvas';
import { SolutionPanel } from '@/components/simulator/solution-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { droneEngine } from '@/lib/simulator/engine-audio';
import {
    SimulatorSession,
    useSimulatorResult,
    useSimulatorRunning,
} from '@/lib/simulator/session';
import { droneVoice } from '@/lib/simulator/voice';
import { store as storeAttempt } from '@/routes/challenges/attempts';
import { store as storePhoto } from '@/routes/challenges/photos';
import { show as showCourse } from '@/routes/courses';
import type {
    ChallengeDetail,
    ChallengeProgress,
    ChallengeSolution,
    CourseDetail,
} from '@/types/simulator';

type PlayProps = {
    course: CourseDetail;
    challenge: ChallengeDetail;
    progress: ChallengeProgress;
    solution: ChallengeSolution;
};

export default function Play({
    course,
    challenge,
    progress,
    solution,
}: PlayProps) {
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
    const voiceEnabled = useSyncExternalStore(
        droneVoice.subscribe,
        droneVoice.isEnabled,
        droneVoice.isEnabled,
    );
    const engineEnabled = useSyncExternalStore(
        droneEngine.subscribe,
        droneEngine.isEnabled,
        droneEngine.isEnabled,
    );

    const attemptUrl = storeAttempt.url([course.slug, challenge.slug]);
    const photoUrl = storePhoto.url([course.slug, challenge.slug]);

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

                    <SolutionPanel
                        solution={solution}
                        attempts={progress.attempts}
                        onLoad={setCode}
                    />
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
                        {droneEngine.isSupported() && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                aria-pressed={engineEnabled}
                                title={
                                    engineEnabled
                                        ? 'Mute motor sound'
                                        : 'Unmute motor sound'
                                }
                                onClick={() => droneEngine.toggle()}
                            >
                                <Fan
                                    className={
                                        engineEnabled ? '' : 'opacity-40'
                                    }
                                />
                                Motor
                            </Button>
                        )}
                        {droneVoice.isSupported() && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className={
                                    droneEngine.isSupported() ? '' : 'ml-auto'
                                }
                                aria-pressed={voiceEnabled}
                                title={
                                    voiceEnabled
                                        ? 'Mute cockpit callouts'
                                        : 'Unmute cockpit callouts'
                                }
                                onClick={() => droneVoice.toggle()}
                            >
                                {voiceEnabled ? <Volume2 /> : <VolumeX />}
                                Voice
                            </Button>
                        )}
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
                        photoUrl={photoUrl}
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
