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
use LemonSqueezy\Laravel\LemonSqueezy;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The three billing tables are versioned in database/migrations like
         * every other table this application owns, so the package must not also
         * load its own copies from the vendor directory — the same schema would
         * be created twice, and a schema we cannot edit is a schema we cannot
         * evolve.
         */
        LemonSqueezy::ignoreMigrations();

        /*
         * The package registers its webhook route only wrapped in
         * `if (config('lemon-squeezy.signing_secret'))` for the signature
         * middleware, so an environment that forgets the secret would serve an
         * unauthenticated endpoint that grants paid plans. routes/billing.php
         * registers the route itself with a signature check that is not
         * conditional on configuration. See VerifyLemonSqueezyWebhookSignature.
         */
        LemonSqueezy::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerFeatureGates();
    }

    /**
     * Register one Gate ability per plan feature.
     *
     * Going through the Gate rather than exposing bespoke checks means routes
     * can use `->middleware('can:python_runtime')`, Blade and React can ask
     * `$user->can(...)`, and every gated capability answers the same way. The
     * abilities are named after the enum values, so adding a Feature case
     * registers its gate with no further wiring.
     */
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
