<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every feature test gets the application test case and a migrated database.
| Both were declared file by file before the suite moved to Pest — a class
| that extended TestCase and a `use RefreshDatabase` on the line below it —
| and every one of them declared the same two things. Stated once here they
| stay stated: a new feature test file cannot forget the database and then
| pass by reading rows a neighbouring test left behind.
|
| Unit tests are deliberately left out. The three files under tests/Unit do
| not agree on what they need — one wants the database, one only wants the
| application, and ScoringPolicyTest wants neither — so each says so itself
| with its own `uses()` line rather than inheriting a default that two of
| them would have to undo.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
