<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Creem gives up on a webhook after five attempts inside a day, so a renewal
 * delivered into an outage is otherwise lost for good. This reads the
 * subscriptions and payments back from Creem and writes whatever was missed.
 */
Schedule::command('creem:reconcile')
    ->hourly()
    ->withoutOverlapping();
