<?php

declare(strict_types=1);

use Source\Exceptions\FileChanged;
use Source\Source;
use Source\Tests\Conformance\Backends;

/*
 * The same tests run against the in-memory fake and against a live server.
 * The live run is skipped unless SOURCE_TEST_URL, SOURCE_TEST_TOKEN,
 * SOURCE_TEST_NAMESPACE and SOURCE_TEST_REPO are set.
 */

dataset('source backends', ['fake', 'live']);

it('round-trips a plain put and get', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    expect($disk->put($path, "# Plan\n\nShip it.\n"))->toBeTrue()
        ->and($disk->get($path))->toBe("# Plan\n\nShip it.\n")
        ->and($disk->exists($path))->toBeTrue()
        ->and($disk->fileExists($path))->toBeTrue()
        ->and($disk->size($path))->toBe(strlen("# Plan\n\nShip it.\n"))
        ->and($disk->lastModified($path))->toBeGreaterThan(0);

    $file = $disk->read($path);

    expect($file->path)->toBe($path)
        ->and($file->contents)->toBe("# Plan\n\nShip it.\n")
        ->and($file->version)->toMatch('/^[0-9a-f]{40}$/')
        ->and($file->size)->toBe(strlen($file->contents))
        ->and($disk->version($path))->toBe($file->version);
})->with('source backends');

it('lets a plain write win over a concurrent change', function (string $backend) {
    [$disk, $other, $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "first\n");
    $other->put($path, "theirs\n");

    // A plain write does not care what is there: it reads the current
    // version, writes against it and wins.
    expect($disk->put($path, "mine\n"))->toBeTrue()
        ->and($disk->get($path))->toBe("mine\n");
})->with('source backends');

it('refuses a stale strict write with FileChanged but no contents', function (string $backend) {
    [$disk, $other, $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "theirs-once\n");
    $version = $disk->version($path);

    $other->put($path, "a secret the loser must not see\n");

    try {
        $disk->ifUnchanged($version)->put($path, "mine\n");

        $this->fail('A stale strict write was accepted.');
    } catch (FileChanged $e) {
        expect($e->paths)->toBe([$path])
            ->and($e->changedBy($path))->not->toBeNull()
            ->and($e->changedBy($path)->commitOid)->toMatch('/^[0-9a-f]{40}$/');

        // The refusal never hands over the new contents or the version.
        $payload = json_encode($e->detailsPayload());

        expect($payload)->not->toContain('a secret the loser must not see')
            ->and($e->detailsPayload())->not->toHaveKey('version')
            ->and($e->detailsPayload()['paths'][0])->not->toHaveKey('oid');
    }

    expect($disk->get($path))->toBe("a secret the loser must not see\n");
})->with('source backends');

it('refuses ifMissing on a path that already exists', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "existing\n");

    expect(fn () => $disk->ifMissing()->put($path, "nope\n"))
        ->toThrow(FileChanged::class);

    expect($disk->get($path))->toBe("existing\n");
})->with('source backends');

it('creates a missing file with ifMissing', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/new.md";

    $disk->ifMissing()->put($path, "created\n");

    expect($disk->get($path))->toBe("created\n");
})->with('source backends');

it('sends a batch commit all or nothing', function (string $backend) {
    [$disk, $other, $prefix] = Backends::make($this, $backend);
    $a = "{$prefix}/notes/a.md";
    $b = "{$prefix}/notes/b.md";

    $disk->put($a, "a-original\n");
    $disk->put($b, "b-original\n");

    $stale = $disk->version($a);
    $other->put($a, "a-theirs\n");

    expect(fn () => $disk->commit('Mixed batch', function ($files) use ($a, $b, $stale) {
        $files->ifUnchanged($stale)->put($a, "a-mine\n");
        $files->put($b, "b-mine\n");
    }))->toThrow(FileChanged::class);

    // The stale strict change refused the whole batch: the plain change on b
    // did not land either.
    expect($disk->get($a))->toBe("a-theirs\n")
        ->and($disk->get($b))->toBe("b-original\n");
})->with('source backends');

it('sends nothing when the commit closure throws', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "original\n");

    expect(function () use ($disk, $path) {
        $disk->commit('Never lands', function ($files) use ($path) {
            $files->put($path, "pending\n");

            throw new RuntimeException('app error');
        });
    })->toThrow(RuntimeException::class, 'app error');

    expect($disk->get($path))->toBe("original\n");
})->with('source backends');

it('treats identical content as the same version', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "same\n");
    $first = $disk->version($path);

    $disk->put($path, "same\n");

    expect($disk->version($path))->toBe($first);
})->with('source backends');

it('deletes a file', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/old.md";

    $disk->put($path, "old\n");

    expect($disk->delete($path))->toBeTrue()
        ->and($disk->fileExists($path))->toBeFalse()
        ->and($disk->exists($path))->toBeFalse()
        ->and($disk->version($path))->toBeNull();
})->with('source backends');

it('lists files and directories from the tree', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $disk->put("{$prefix}/a.md", "a\n");
    $disk->put("{$prefix}/b.md", "b\n");
    $disk->put("{$prefix}/sub/c.md", "c\n");

    expect($disk->files($prefix))->toBe(["{$prefix}/a.md", "{$prefix}/b.md"])
        ->and($disk->allFiles($prefix))->toBe([
            "{$prefix}/a.md",
            "{$prefix}/b.md",
            "{$prefix}/sub/c.md",
        ])
        ->and($disk->directories($prefix))->toBe(["{$prefix}/sub"])
        ->and($disk->allDirectories($prefix))->toBe(["{$prefix}/sub"])
        ->and($disk->directoryExists("{$prefix}/sub"))->toBeTrue();
})->with('source backends');

it('moves a file in one commit', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $from = "{$prefix}/notes/a.md";
    $to = "{$prefix}/notes/b.md";

    $disk->put($from, "moving\n");
    $version = $disk->version($from);

    expect($disk->move($from, $to))->toBeTrue()
        ->and($disk->fileExists($from))->toBeFalse()
        ->and($disk->get($to))->toBe("moving\n")
        ->and($disk->version($to))->toBe($version);

    if ($backend === 'fake') {
        Source::assertCommitted("Move {$from} to {$to}");
    }
})->with('source backends');
