<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DronePhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
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
