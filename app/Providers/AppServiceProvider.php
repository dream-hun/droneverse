<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Feature;
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
        $this->registerLogViewerGate();
    }


    private function registerLogViewerGate(): void
    {
        Gate::define('viewLogViewer', static function (?User $user): bool {
            if (!$user instanceof User) {
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
                static fn(User $user): bool => $user->hasFeature($feature),
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

        Model::preventLazyLoading(!app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn(): ?Password => app()->isProduction()
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
