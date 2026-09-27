<?php

declare(strict_types=1);

use App\Actions\FormatMoney;
use Tests\TestCase;

uses(TestCase::class);

test('minor units become money a person can read', function (): void {
    $money = new FormatMoney;

    expect($money->handle(1900, 'usd'))->toBe('$19.00')
        ->and($money->handle(1900, 'USD', minFractionDigits: 0))->toBe('$19');
});

test('how far the point moves is a property of the currency', function (): void {
    $money = new FormatMoney;

    expect($money->handle(1900, 'JPY'))->toBe('¥1,900')
        ->and($money->handle(19000, 'KWD'))->toContain('19.000');
});

test('an amount in a currency the formatter cannot price falls back to the bare code', function (): void {
    expect((new FormatMoney)->handle(1900, 'US'))->toBe('US 19.00');
});

test('a blank locale formats in english', function (): void {
    config(['plans.currency_locale' => '']);

    expect((new FormatMoney)->handle(1900, 'USD'))->toBe('$19.00');
});
