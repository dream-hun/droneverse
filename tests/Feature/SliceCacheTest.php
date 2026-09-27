<?php

declare(strict_types=1);

use App\Queries\Support\SliceCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/*
 * The two outcomes of queueing behind another reader's build, which one
 * process can only reach by standing in for the other: the lock is replaced,
 * and what the other reader would have done happens while "waiting" on it.
 */

test('a reader queued behind a build takes the value that build stored', function (): void {
    $slices = new SliceCache('test', 60);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andReturnUsing(function (int $seconds, Closure $callback) use ($slices): mixed {
        $slices->remember('board', ['course:1'], fn (): string => 'built by the first reader', shared: false);

        return $callback();
    });
    cacheLockedBy($lock);

    $value = $slices->remember('board', ['course:1'], fn (): string => 'built again');

    expect($value)->toBe('built by the first reader');
});

test('a reader that waits too long for a build does the build itself', function (): void {
    $slices = new SliceCache('test', 60);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    cacheLockedBy($lock);

    $value = $slices->remember('board', ['course:1'], fn (): string => 'built without the lock');

    expect($value)->toBe('built without the lock')
        ->and($slices->remember('board', ['course:1'], fn (): string => 'built again'))->toBe('built without the lock');
});

/**
 * The live cache, with every lock it hands out replaced by `$lock`.
 */
function cacheLockedBy(MockInterface $lock): void
{
    $cache = Mockery::mock(Cache::getFacadeRoot());
    $cache->shouldReceive('lock')->andReturn($lock);
    Cache::swap($cache);
}
