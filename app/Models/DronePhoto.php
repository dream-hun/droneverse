<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DronePhotoFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $challenge_id
 * @property string|null $label
 * @property string $path
 * @property array{x: float, y: float, z: float, headingDeg: float}|null $position
 */
final class DronePhoto extends Model
{
    /** @use HasFactory<DronePhotoFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * How long a signed photo URL stays valid.
     *
     * Long enough to outlast browsing a page of the log, short enough that a
     * link which escapes — a shared screenshot, a referrer header, a synced
     * browser history — stops working while it is still an inconvenience
     * rather than a leak.
     */
    private const int URL_TTL_MINUTES = 30;

    /**
     * The disk a pilot's photos are written to and read back from.
     *
     * Resolved from config rather than named at each call site, so the action
     * that writes a file, the controller that deletes one and the URL built
     * here can never disagree about where the log lives. Which disk that is
     * belongs to the environment: object storage where the filesystem does
     * not survive a release, a local disk on a developer's machine.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk(config()->string('filesystems.photo_disk'));
    }

    /**
     * A photo is addressed publicly by its uuid, never by its id.
     *
     * The row keeps its auto-increment key — it is what the foreign keys and
     * the `(user_id, created_at)` index are built on, and nothing about that
     * needs to change. What changes is the identifier that leaves the
     * server: an id in a URL is a running count of every photo ever taken,
     * handed to anyone holding one of their own.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The columns Eloquent fills with a generated identifier on insert.
     *
     * Overridden because HasUuids assumes the uuid *is* the primary key.
     * Naming the column here instead keeps `id` an auto-incrementing
     * integer, and still gets the rest of the trait: generation on create,
     * and a route binding that rejects a malformed uuid without going to the
     * database for it.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * A short-lived signed URL to the stored image.
     *
     * Expiring rather than permanent because a photo is private to the pilot
     * who took it. `DronePhotoPolicy` guards the row, but a URL is not a row —
     * a permanent link is an unguarded copy of the photo that keeps working
     * for anyone who ever comes across it, long after the pilot deleted it
     * from their log or their access to the mission lapsed. The window is
     * sized to outlast reading a page of the log, not to be bookmarked.
     */
    public function url(): string
    {
        return self::disk()->temporaryUrl($this->path, now()->addMinutes(self::URL_TTL_MINUTES));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'array',
        ];
    }
}
