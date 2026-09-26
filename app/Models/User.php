<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\Billable;
use App\Concerns\HasPlan;
use App\Enums\AdminPermission;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * MustVerifyEmail below is the contract, not the trait. The methods it names —
 * hasVerifiedEmail, markEmailAsVerified, sendEmailVerificationNotification —
 * already arrive through Illuminate\Foundation\Auth\User, which uses the trait
 * of the same name. Only the interface was missing, and everything that
 * enforces verification tests for the interface rather than for the methods:
 * Illuminate\Auth\Middleware\EnsureEmailIsVerified gates on
 * `$user instanceof MustVerifyEmail`, and Laravel's
 * SendEmailVerificationNotification listener checks the same thing before
 * mailing a newly registered user.
 *
 * Without it both did nothing, quietly. `verified` admitted every request it
 * was asked to gate, registration sent no verification mail at all, and
 * clearing `email_verified_at` on an email change — which ProfileController
 * still does — cost the account no access. Nothing failed; the routes, the
 * Fortify feature in config/fortify.php and the verification screens were all
 * present, and were the only evidence that any of it ran. Removing the
 * interface again would turn the feature off the same silent way, which is
 * what Tests\Feature\Auth\EmailVerificationTest asserts against directly.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $plan_override
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Collection<int, Subscription> $subscriptions
 * @property-read Collection<int, Order> $orders
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, ChallengeRun> $challengeRuns
 * @property-read Collection<int, QuizAttempt> $quizAttempts
 * @property-read int|null $challenge_runs_count
 */
#[Hidden(['password', 'plan_override', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use Billable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPlan;
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    /**
     * An account is addressed publicly by its uuid, never by its id.
     *
     * The admin area is the only place a user appears in a URL, and the
     * reasoning is the one {@see DronePhoto} gives: the integer key stays
     * what every foreign key points at, but it does not leave the server.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Overridden because HasUuids assumes the uuid *is* the primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * The admin permissions this account holds, whichever route they came by.
     *
     * Asked through the Gate rather than read off the roles, so the answer is
     * the one every admin route will give — including the `admin` role's
     * blanket grant, which has no rows behind it to read.
     *
     * @return array<int, AdminPermission>
     */
    public function adminPermissions(): array
    {
        return array_values(array_filter(
            AdminPermission::cases(),
            fn (AdminPermission $permission): bool => $this->can($permission->value),
        ));
    }

    /**
     * @return HasMany<UserChallengeProgress, $this>
     */
    public function challengeProgress(): HasMany
    {
        return $this->hasMany(UserChallengeProgress::class);
    }

    /**
     * @return HasMany<UserQuizProgress, $this>
     */
    public function quizProgress(): HasMany
    {
        return $this->hasMany(UserQuizProgress::class);
    }

    /**
     * @return HasMany<DronePhoto, $this>
     */
    public function dronePhotos(): HasMany
    {
        return $this->hasMany(DronePhoto::class);
    }

    /**
     * @return HasMany<ChallengeRun, $this>
     */
    public function challengeRuns(): HasMany
    {
        return $this->hasMany(ChallengeRun::class);
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
