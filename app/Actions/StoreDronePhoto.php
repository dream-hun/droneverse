<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StoreDronePhoto
{
    /** A generous ceiling so one pilot cannot fill the disk. */
    private const int MAX_PHOTOS_PER_USER = 500;

    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Decode a simulator camera frame and file it in the pilot's photo log.
     *
     * The request only validates the data-URL shape; this re-decodes the
     * payload and confirms the bytes really are a JPEG/PNG before anything
     * touches the disk.
     *
     * @param  array{image: string, label: string|null, position: array{x: float, y: float, z: float, headingDeg: float}|null}  $photo
     *
     * @throws ValidationException
     */
    public function handle(User $user, Challenge $challenge, array $photo): DronePhoto
    {
        if ($user->dronePhotos()->count() >= self::MAX_PHOTOS_PER_USER) {
            throw ValidationException::withMessages([
                'image' => 'Your photo log is full. Delete some photos and try again.',
            ]);
        }

        $binary = base64_decode(Str::after($photo['image'], 'base64,'), true);

        if ($binary === false) {
            throw ValidationException::withMessages([
                'image' => 'The photo could not be decoded.',
            ]);
        }

        $info = @getimagesizefromstring($binary);
        $mime = $info['mime'] ?? null;

        if (! is_string($mime) || ! array_key_exists($mime, self::EXTENSIONS)) {
            throw ValidationException::withMessages([
                'image' => 'The photo is not a valid JPEG or PNG image.',
            ]);
        }

        $path = sprintf(
            'drone-photos/%d/%s.%s',
            $user->id,
            Str::uuid(),
            self::EXTENSIONS[$mime],
        );

        DronePhoto::disk()->put($path, $binary);

        return DronePhoto::query()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'label' => $photo['label'],
            'path' => $path,
            'position' => $photo['position'],
        ]);
    }
}
