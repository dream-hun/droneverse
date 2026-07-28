## Eloquent
- Never use `$fillable` or `$guarded` fields. We run `Model:unguard()` application-wide.
- Use `@property-read` on every model class.

## Controller
- Always when working with controllers use `Actions` and `FormRequests` in order to have clean controller
## UI
- I always prefer to use modal on create,edit and delete.
- I always prefer using grouped menu on actions table.
- Toasts notification for user during crud operations.

## General Guidelines
- Don't include any superfluous PHP Annotations, except ones that start with `@` for typing variables.
- Never use migration down method.
- We use ` public function getRouteKeyName(): string
    {
        return 'uuid';
    }` on every model

# App/Actions guidelines

- This application uses the Action pattern and prefers for much logic to live in reusable and composable Action classes.
- Actions live in `app/Actions`, they are named based on what they do, with no suffix.
- Actions will be called from many different places: jobs, commands, HTTP requests, API requests, MCP requests, and more.
- Create dedicated Action classes for business logic with a single `handle()` method.
- Inject dependencies via constructor using private properties.
- Create new actions with `php artisan make:action "{name}" --no-interaction`
- Wrap complex operations in `DB::transaction()` within actions when multiple models are involved.
- Some actions won't require dependencies via `__construct` and they can use just the `handle()` method.

@boostsnippet('Example action class', 'php')
<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class CreateFavorite
{
    public function __construct(private FavoriteService $favorites)
    {
        //
    }

    public function handle(User $user, string $favorite): bool
    {
        return $this->favorites->add($user, $favorite);
    }
}
@endboostsnippet