import { Head, Link, router } from '@inertiajs/react';
import { Camera, ExternalLink, MapPin, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataBoundary } from '@/components/data-boundary';
import { PageHeader } from '@/components/page-header';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { visitAsPromise } from '@/lib/inertia-promise';
import type { RowAction } from '@/lib/row-actions';
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

function PhotoGridSkeleton() {
    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 8 }, (_, index) => (
                <div
                    key={index}
                    className="overflow-hidden rounded-xl border bg-card"
                >
                    <Skeleton className="aspect-video rounded-none" />
                    <div className="space-y-2 p-3">
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-3 w-1/2" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function PhotoCard({ photo }: { photo: DronePhotoSummary }) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const actions: RowAction[] = [
        ...(photo.courseSlug && photo.challengeSlug
            ? [
                  {
                      label: 'Open mission',
                      icon: <ExternalLink />,
                      href: showChallenge([
                          photo.courseSlug,
                          photo.challengeSlug,
                      ]),
                  },
              ]
            : []),
        {
            label: 'Delete photo',
            icon: <Trash2 />,
            variant: 'destructive' as const,
            group: 'danger',
            // Opens the dialog rather than deleting: the menu unmounts on
            // select, so a dialog rendered inside it would never show.
            onSelect: () => setConfirmingDelete(true),
        },
    ];

    return (
        <div className="group overflow-hidden rounded-xl border bg-card">
            <div className="relative aspect-video bg-muted">
                <img
                    src={photo.url}
                    alt={photo.label ?? 'Drone photo'}
                    loading="lazy"
                    className="h-full w-full object-cover"
                />
                <div className="absolute top-2 right-2">
                    <RowActions
                        actions={actions}
                        label={`Actions for ${photo.label ?? 'this photo'}`}
                        className="bg-background/80 backdrop-blur-sm hover:bg-background"
                    />
                </div>
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

            <ConfirmDialog
                open={confirmingDelete}
                onOpenChange={setConfirmingDelete}
                destructive
                title="Delete this photo?"
                description="The shot is removed from your log for good. Flying the mission again will not bring it back."
                confirmLabel="Delete photo"
                pendingLabel="Deleting"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(destroyPhoto.url(photo.id), options),
                        {},
                        'The photo was not deleted',
                    )
                }
            />
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

                <DataBoundary
                    data={photos}
                    skeleton={<PhotoGridSkeleton />}
                    empty={
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
                    }
                >
                    {(loaded) => (
                        <div className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {loaded.map((photo) => (
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
                        </div>
                    )}
                </DataBoundary>
            </div>
        </>
    );
}

PhotosIndex.layout = {
    breadcrumbs: [{ title: 'Photo Log', href: photosIndex() }],
};
