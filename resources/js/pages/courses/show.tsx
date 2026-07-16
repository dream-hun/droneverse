import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { CheckCircle2, Circle, PlayCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showChallenge } from '@/routes/challenges';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import type {
    ChallengeStatus,
    ChallengeSummary,
    CourseDetail,
} from '@/types/simulator';

type CourseShowProps = {
    course: CourseDetail;
    challenges: ChallengeSummary[];
};

const STATUS_ICON: Record<ChallengeStatus, typeof Circle> = {
    not_started: Circle,
    in_progress: PlayCircle,
    completed: CheckCircle2,
};

export default function CourseShow({ course, challenges }: CourseShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Courses', href: coursesIndex() },
            { title: course.title, href: showCourse(course.slug) },
        ],
    });

    return (
        <>
            <Head title={course.title} />

            <div className="space-y-6 p-4">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-semibold">
                            {course.title}
                        </h1>
                        <Badge variant="outline" className="capitalize">
                            {course.difficulty}
                        </Badge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {course.description}
                    </p>
                </div>

                <div className="space-y-3">
                    {challenges.map((challenge, index) => {
                        const Icon = STATUS_ICON[challenge.status];

                        return (
                            <Link
                                key={challenge.slug}
                                href={showChallenge([
                                    course.slug,
                                    challenge.slug,
                                ])}
                            >
                                <Card className="transition-shadow hover:shadow-md">
                                    <CardHeader className="flex-row items-center justify-between space-y-0">
                                        <div className="flex items-center gap-3">
                                            <Icon className="size-5 shrink-0 text-muted-foreground" />
                                            <div>
                                                <CardTitle className="text-base">
                                                    {index + 1}.{' '}
                                                    {challenge.title}
                                                </CardTitle>
                                                <p className="text-xs text-muted-foreground capitalize">
                                                    {challenge.difficulty}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {challenge.stars > 0 && (
                                                <Badge variant="outline">
                                                    {'★'.repeat(
                                                        challenge.stars,
                                                    )}
                                                </Badge>
                                            )}
                                            {challenge.bestScore > 0 && (
                                                <Badge variant="outline">
                                                    {challenge.bestScore}
                                                </Badge>
                                            )}
                                        </div>
                                    </CardHeader>
                                </Card>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </>
    );
}
