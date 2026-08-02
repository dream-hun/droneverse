<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DronePhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
#[Fillable(['user_id', 'challenge_id', 'label', 'path', 'position'])]
final class DronePhoto extends Model
{
    /** @use HasFactory<DronePhotoFactory> */
    use HasFactory;

    use HasUuids;

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
     * Public URL of the stored image on the public disk.
     */
    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
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
