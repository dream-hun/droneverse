import { Head, Link, router } from '@inertiajs/react';
import { Camera, MapPin, Trash2 } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { show as showChallenge } from '@/routes/challenges';
import { destroy as destroyPhoto, index as photosIndex } from '@/routes/photos';
import type { DronePhotoSummary } from '@/types/simulator';

type PhotosIndexProps = {
    photos: DronePhotoSummary[];
    page: number;
    lastPage: number;
    total: number;
};

function formatTakenAt(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function PhotoCard({ photo }: { photo: DronePhotoSummary }) {
    return (
        <div className="group overflow-hidden rounded-xl border bg-card">
            <div className="relative aspect-video bg-muted">
                <img
                    src={photo.url}
                    alt={photo.label ?? 'Drone photo'}
                    loading="lazy"
                    className="h-full w-full object-cover"
                />
                <Button
                    size="icon"
                    variant="destructive"
                    className="absolute top-2 right-2 size-7 opacity-0 transition-opacity group-hover:opacity-100"
                    title="Delete photo"
                    onClick={() =>
                        router.delete(destroyPhoto.url(photo.id), {
                            preserveScroll: true,
                        })
                    }
                >
                    <Trash2 />
                </Button>
                {photo.label && (
                    <Badge
                        variant="secondary"
                        className="absolute bottom-2 left-2 max-w-[80%] truncate"
                    >
                        {photo.label}
                    </Badge>
                )}
            </div>
            <div className="space-y-1 p-3 text-sm">
                <div className="flex items-center justify-between gap-2">
                    {photo.challengeTitle &&
                    photo.courseSlug &&
                    photo.challengeSlug ? (
                        <Link
                            href={showChallenge([
                                photo.courseSlug,
                                photo.challengeSlug,
                            ])}
                            className="truncate font-medium hover:underline"
                        >
                            {photo.challengeTitle}
                        </Link>
                    ) : (
                        <span className="truncate font-medium">
                            {photo.challengeTitle ?? 'Free flight'}
                        </span>
                    )}
                </div>
                <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                    <span>{formatTakenAt(photo.takenAt)}</span>
                    {photo.position && (
                        <span className="flex items-center gap-1 font-mono">
                            <MapPin className="size-3" />
                            {photo.position.x.toFixed(0)},{' '}
                            {photo.position.z.toFixed(0)} ·{' '}
                            {photo.position.y.toFixed(1)}m
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function PhotosIndex({
    photos,
    page,
    lastPage,
    total,
}: PhotosIndexProps) {
    return (
        <>
            <Head title="Photo Log" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Photo Log"
                    description={`Every shot captured with drone.takePhoto() lands here${total > 0 ? ` — ${total} photo${total === 1 ? '' : 's'} so far` : ''}.`}
                />

                {photos.length > 0 ? (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {photos.map((photo) => (
                                <PhotoCard key={photo.id} photo={photo} />
                            ))}
                        </div>

                        {lastPage > 1 && (
                            <div className="flex items-center justify-center gap-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={page <= 1}
                                    asChild={page > 1}
                                >
                                    {page > 1 ? (
                                        <Link
                                            href={photosIndex.url({
                                                query: { page: page - 1 },
                                            })}
                                            preserveScroll
                                        >
                                            Previous
                                        </Link>
                                    ) : (
                                        <span>Previous</span>
                                    )}
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    Page {page} of {lastPage}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={page >= lastPage}
                                    asChild={page < lastPage}
                                >
                                    {page < lastPage ? (
                                        <Link
                                            href={photosIndex.url({
                                                query: { page: page + 1 },
                                            })}
                                            preserveScroll
                                        >
                                            Next
                                        </Link>
                                    ) : (
                                        <span>Next</span>
                                    )}
                                </Button>
                            </div>
                        )}
                    </>
                ) : (
                    <EmptyState>
                        <EmptyStateIcon>
                            <Camera />
                        </EmptyStateIcon>
                        <EmptyStateTitle>No photos yet</EmptyStateTitle>
                        <EmptyStateDescription>
                            Fly a City Operations mission and call{' '}
                            <code className="font-mono">
                                await drone.takePhoto('my-shot')
                            </code>{' '}
                            to start filling your log.
                        </EmptyStateDescription>
                    </EmptyState>
                )}
            </div>
        </>
    );
}

PhotosIndex.layout = {
    breadcrumbs: [{ title: 'Photo Log', href: photosIndex() }],
};
