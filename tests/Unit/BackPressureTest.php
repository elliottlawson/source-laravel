<?php

declare(strict_types=1);

use Source\Exceptions\UploadsBusy;
use Source\Source;

/*
 * api.md 3.4.4 from the disk's side: the transport waits out uploads_busy, and
 * what escapes is the typed exception, not a Flysystem wrapper.
 */

it('waits out a busy server and lands the write', function () {
    $disk = fakeDisk();

    Source::fake()->simulateBusy(times: 2);

    expect($disk->put('notes/plan.md', "plan\n"))->toBeTrue()
        ->and($disk->get('notes/plan.md'))->toBe("plan\n");
});

it('throws UploadsBusy through the disk when the retries run out', function () {
    $disk = fakeDisk();

    Source::fake()->simulateBusy(times: 10);

    expect(fn () => $disk->put('notes/plan.md', "plan\n"))
        ->toThrow(UploadsBusy::class);
});

it('returns false for a busy write when throw is off', function () {
    config()->set('filesystems.disks.source_lax', [
        'driver' => 'source',
        'url' => 'https://source.test',
        'token' => 'asm_tok_test',
        'namespace' => 'acme',
        'repo' => 'workspace',
        'branch' => 'main',
        'throw' => false,
    ]);

    $disk = fakeDisk('source_lax');

    Source::fake()->simulateBusy(times: 10);

    expect($disk->put('notes/plan.md', "plan\n"))->toBeFalse();
});
