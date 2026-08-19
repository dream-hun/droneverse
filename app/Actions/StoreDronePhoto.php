<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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
     * Two writes that have to agree — a file on a disk and a row pointing at
     * it — and a quota that decides whether either happens. All three are
     * arranged so that no failure leaves the pilot's log describing something
     * that is not there:
     *
     * 1. **The quota is counted under a lock on the pilot's own row.** A
     *    count followed by an insert is a check-then-act, and the simulator
     *    uploads photos from a queue that can have several in flight at once
     *    — so a pilot sitting on the limit could put as many photos past it
     *    as they had requests in the air. Locking the user row serializes
     *    that pilot's uploads against each other and nobody else's.
     *
     * 2. **The file is written inside the transaction.** It is the ordering
     *    with no bad outcome: a failed write rolls the row back, and a row
     *    that commits is a row whose bytes are already on the disk. Writing
     *    the row first would leave a log entry pointing at an image that
     *    never arrived, which is what a pilot sees as a broken photo.
     *
     * 3. **A file whose row never lands is deleted.** The one thing the
     *    transaction cannot undo is the write to the disk, so a failure after
     *    it — including a commit that does not complete — sweeps the object
     *    up on the way out. The worst remaining case is a crash between the
     *    two, which costs an orphaned file nobody can reach rather than a
     *    photo log that lies.
     *
     * @param  array{image: string, label: string|null, position: array{x: float, y: float, z: float, headingDeg: float}|null}  $photo
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function handle(User $user, Challenge $challenge, array $photo): DronePhoto
    {
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

        $written = false;

        try {
            return DB::transaction(function () use ($user, $challenge, $photo, $path, $binary, &$written): DronePhoto {
                /*
                 * The pilot's own row, held for the rest of the transaction.
                 * Nothing else in the application locks a user for writing,
                 * so the only thing this can wait on is the same pilot's own
                 * concurrent upload — which is exactly the thing the count
                 * below has to be protected from.
                 */
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                if ($user->dronePhotos()->count() >= self::MAX_PHOTOS_PER_USER) {
                    throw ValidationException::withMessages([
                        'image' => 'Your photo log is full. Delete some photos and try again.',
                    ]);
                }

                // `put` reports failure by returning false rather than by
                // throwing, and an unchecked false is the one path that ends
                // with a committed row pointing at nothing.
                $stored = DronePhoto::disk()->put($path, $binary);
                $written = $stored !== false;

                if ($stored === false) {
                    throw ValidationException::withMessages([
                        'image' => 'The photo could not be saved. Try again.',
                    ]);
                }

                return DronePhoto::query()->create([
                    'user_id' => $user->id,
                    'challenge_id' => $challenge->id,
                    'label' => $photo['label'],
                    'path' => $path,
                    'position' => $photo['position'],
                ]);
            });
        } catch (Throwable $throwable) {
            // The row is gone with the transaction; the object is not, and
            // nothing else knows it is there to come back for.
            if ($written) {
                DronePhoto::disk()->delete($path);
            }

            throw $throwable;
        }
    }
}
