<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DronePhoto;
use Illuminate\Support\Collection;

/**
 * A saved drone photo as the photo log renders it.
 *
 * The challenge a photo was taken on can be retired out from under it, so
 * every field reached through that relation is optional here rather than
 * assumed present. Mirrors the `DronePhotoSummary` type in
 * resources/js/types/simulator.ts.
 */
final class DronePhotoResource
{
    /**
     * @param  Collection<int, DronePhoto>  $photos  with `challenge.course` eager loaded
     * @return array<int, array{id: int, url: string, label: string|null, challengeTitle: string|null, courseSlug: string|null, challengeSlug: string|null, position: array{x: float, y: float, z: float, headingDeg: float}|null, takenAt: string}>
     */
    public static function collection(Collection $photos): array
    {
        return $photos->map(fn (DronePhoto $photo): array => self::one($photo))->all();
    }

    /**
     * @return array{id: int, url: string, label: string|null, challengeTitle: string|null, courseSlug: string|null, challengeSlug: string|null, position: array{x: float, y: float, z: float, headingDeg: float}|null, takenAt: string}
     */
    public static function one(DronePhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => $photo->url(),
            'label' => $photo->label,
            'challengeTitle' => $photo->challenge?->title,
            'courseSlug' => $photo->challenge?->course?->slug,
            'challengeSlug' => $photo->challenge?->slug,
            'position' => $photo->position,
            'takenAt' => $photo->created_at?->toIso8601String() ?? '',
        ];
    }
}
