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

    /**
     * Decide who may read the application's logs.
     *
     * opcodesio/log-viewer serves thirteen routes — reading log contents,
     * downloading whole files, and deleting files and folders — and its own
     * AuthorizeLogViewer middleware only guards them when four conditions hold
     * at once, one of which is `App::isProduction()`. That is a literal
     * `APP_ENV === 'production'` comparison, so every staging, demo, preview and
     * uat environment served all thirteen to anonymous visitors: full stack
     * traces, Creem webhook payloads, session identifiers and pilots'
     * email addresses, plus the ability to delete the evidence afterwards.
     *
     * Defining this gate is what replaces that. The middleware skips its
     * environment-dependent `abort(403)` once a `viewLogViewer` gate exists and
     * then calls `LogViewer::auth()`, which runs `Gate::authorize('viewLogViewer')`
     * unconditionally — so authorization stops depending on the value of
     * APP_ENV and starts depending on who is asking, in every environment.
     *
     * Guests are denied before the allowlist is consulted: `Gate::authorize()`
     * evaluates an ability typed `?User` for unauthenticated callers too, and
     * an empty allowlist compared against a null email must not become a way in.
     */
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

    /**
     * Register one Gate ability per plan feature.
     *
     * Going through the Gate rather than exposing bespoke checks means routes
     * can use `->middleware('can:mission_builder')`, Blade and React can ask
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
