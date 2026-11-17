<?php

declare(strict_types=1);

use Source\Uploads\AdaptiveConcurrency;

/*
 * The client lowers concurrency on a refusal and raises it again
 * only when the server is keeping up. The rules are pure arithmetic, so they are
 * tested without a transport.
 */

it('starts at four and holds after the first clean wave', function () {
    $adaptive = new AdaptiveConcurrency;

    expect($adaptive->current())->toBe(4)
        ->and($adaptive->recordWave(1_000, 1.0))->toBe(4);
});

it('adds one when a clean wave beats the previous throughput', function () {
    $adaptive = new AdaptiveConcurrency;

    $adaptive->recordWave(1_000, 1.0);

    expect($adaptive->recordWave(2_000, 1.0))->toBe(5);
});

it('holds when the throughput is lower', function () {
    $adaptive = new AdaptiveConcurrency;

    $adaptive->recordWave(2_000, 1.0);

    expect($adaptive->recordWave(1_000, 1.0))->toBe(4);
});

it('holds when the throughput is exactly the same', function () {
    $adaptive = new AdaptiveConcurrency;

    $adaptive->recordWave(2_000, 1.0);

    expect($adaptive->recordWave(2_000, 1.0))->toBe(4);
});

it('never grows past the maximum', function () {
    $adaptive = new AdaptiveConcurrency(start: 4, max: 6);

    $adaptive->recordWave(100, 1.0);
    $adaptive->recordWave(200, 1.0);
    $adaptive->recordWave(300, 1.0);
    $adaptive->recordWave(400, 1.0);

    expect($adaptive->current())->toBe(6);
});

it('halves on a refusal, never below one', function () {
    $adaptive = new AdaptiveConcurrency;

    expect($adaptive->halve())->toBe(2)
        ->and($adaptive->halve())->toBe(1)
        ->and($adaptive->halve())->toBe(1);
});

it('rounds a halved odd number down', function () {
    $adaptive = new AdaptiveConcurrency(start: 5);

    expect($adaptive->halve())->toBe(2);
});

it('clamps the starting concurrency into the allowed range', function () {
    expect((new AdaptiveConcurrency(start: 99, max: 8))->current())->toBe(8)
        ->and((new AdaptiveConcurrency(start: 0))->current())->toBe(1);
});

it('refuses a range that cannot work', function () {
    expect(fn () => new AdaptiveConcurrency(max: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AdaptiveConcurrency(start: 4, max: 2, min: 3))->toThrow(InvalidArgumentException::class);
});
