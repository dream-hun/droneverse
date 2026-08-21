<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the catalogue every environment needs, and nothing else.
     *
     * Content only, deliberately. This is the seeder a deployment runs, so two
     * things have to stay true of it: nothing here may need a development
     * dependency, and nothing here may create an account.
     *
     * The stock `User::factory()->create()` line that used to sit at the top
     * broke both. Laravel defines `fake()` only when fakerphp/faker can be
     * found — `if (! function_exists('fake') && class_exists(\Faker\Factory::class))`
     * — and Faker is a dev dependency, so on an install made with `--no-dev`
     * this seeder died on its first statement with "Call to undefined function
     * Database\Factories\fake()", before a single course was written.
     *
     * Which was the lesser of the two problems. Had it run, it would have left
     * a `test@example.com` account with a known password on a public site, and
     * nothing about the seeder said so. Guarding it on `app()->isProduction()`
     * would not have fixed that either: that is a literal `APP_ENV ===
     * 'production'` comparison, so every staging, demo and preview deployment
     * would still have got the account. See the log viewer gate in
     * App\Providers\AppServiceProvider for where that reasoning has already
     * cost this application once.
     *
     * Somebody who wants a local account registers through the form, or makes
     * one in tinker where the intent is visible.
     *
     * All three seeders below write every row with `updateOrCreate`, so this is
     * safe to run on each release: the catalogue is brought up to date rather
     * than duplicated.
     */
    public function run(): void
    {
        $this->call(DroneSeeder::class);
        $this->call(CourseSeeder::class);
        // After CourseSeeder: every quiz attaches to a course by slug, and
        // skips itself if that course is not there yet.
        $this->call(QuizSeeder::class);
    }
}
