<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a member of staff may do in the admin area.
 *
 * Stored as spatie/laravel-permission permissions under their own value and
 * granted through roles, so a route asks `can:manage_users` and the package's
 * Gate hook answers it. The cases are the whole vocabulary: a role is edited by
 * ticking some of them, and a permission is only ever created from one. One
 * named anywhere else would be a checkbox no route consults.
 *
 * These sit on a separate axis from App\Enums\Feature. A feature is something a
 * plan sells a pilot; a permission is something the business trusts a member
 * of staff with, and nobody can buy one.
 *
 * `AccessAdmin` is the door rather than a room: every admin route requires it,
 * and each section requires its own case on top. A role holding only
 * `ViewFinance` therefore reaches nothing — that is deliberate, because a
 * section a person cannot navigate to is not one they have been given.
 */
enum AdminPermission: string
{
    case AccessAdmin = 'access_admin';
    case ManageUsers = 'manage_users';
    case ManageRoles = 'manage_roles';
    case ManageCourses = 'manage_courses';
    case ViewFinance = 'view_finance';
    case ViewSystem = 'view_system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::AccessAdmin => 'Access the admin area',
            self::ManageUsers => 'Manage users',
            self::ManageRoles => 'Manage roles',
            self::ManageCourses => 'Manage courses and challenges',
            self::ViewFinance => 'View financial activity',
            self::ViewSystem => 'View system performance',
        };
    }

    /**
     * The line under the checkbox on the role form, saying what ticking it
     * actually hands over.
     */
    public function description(): string
    {
        return match ($this) {
            self::AccessAdmin => 'The overview and the activity feed. Every other permission needs this one as well.',
            self::ManageUsers => 'Create, edit and delete accounts, grant plans by hand and assign roles.',
            self::ManageRoles => 'Create and edit roles, and decide what each one may do.',
            self::ManageCourses => 'Author, publish and delete courses and the missions inside them.',
            self::ViewFinance => 'Revenue, orders, refunds and subscriptions, as Creem reported them.',
            self::ViewSystem => 'Health checks, queue backlog, failed jobs and database size.',
        };
    }
}
