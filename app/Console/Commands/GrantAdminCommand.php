<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncAdminPermissions;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Give an existing account the `admin` role.
 *
 * The way in for the first administrator, who by definition has nobody to
 * grant it to them from the admin area. After that it is an alternative to
 * the users screen rather than a replacement for it.
 *
 * It names an account that already exists rather than creating one, so the
 * person being trusted has registered, verified an address and chosen their
 * own password — none of which a command line should do on their behalf.
 */
#[Description('Grant the admin role to the account with the given email address')]
#[Signature('admin:grant {email : The email address of an existing account}')]
final class GrantAdminCommand extends Command
{
    /**
     * @throws Throwable
     */
    public function handle(SyncAdminPermissions $sync): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->components->error('No account is registered to that address.');

            return self::FAILURE;
        }

        $role = $sync->handle();

        $user->assignRole($role);

        $this->components->info(sprintf('%s is now an admin.', $user->email));

        return self::SUCCESS;
    }
}
