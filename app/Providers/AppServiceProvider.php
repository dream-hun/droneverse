<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\AdminPermission;
use App\Enums\Feature;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerFeatureGates();
        $this->registerAdminGate();
        $this->registerLogViewerGate();
    }

    /**
     * The `admin` role holds every admin permission, rows or no rows.
     *
     * Everything else is spatie/laravel-permission as it ships: a role is
     * granted permissions, and the package's own Gate hook answers
     * `can:manage_users` from them. This covers the one role that is not
     * edited that way. A permission added to App\Enums\AdminPermission
     * reaches the admins who have to hand it out the moment it is deployed,
     * rather than after somebody remembers to tick it for them — and there is
     * no save of the role form that can lock the last admin out.
     *
     * Scoped to admin permissions on purpose. A blanket `before` returning
     * true would also answer every plan Feature gate, handing staff a Pro
     * subscription they never bought and muddying every question about what
     * a pilot's plan unlocks.
     */
    private function registerAdminGate(): void
    {
        Gate::before(static function (User $user, string $ability): ?bool {
            if (! AdminPermission::tryFrom($ability) instanceof AdminPermission) {
                return null;
            }

            return $user->hasRole(Role::ADMIN) ? true : null;
        });
    }

    private function registerLogViewerGate(): void
    {
        Gate::define('viewLogViewer', static function (?User $user): bool {
            if (! $user instanceof User) {
                return false;
            }

            $allowed = config('logging.viewer_emails');

            return is_array($allowed) && in_array($user->email, $allowed, true);
        });
    }

    private function registerFeatureGates(): void
    {
        foreach (Feature::cases() as $feature) {
            Gate::define(
                $feature->value,
                static fn (User $user): bool => $user->hasFeature($feature),
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Model::unguard();
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
