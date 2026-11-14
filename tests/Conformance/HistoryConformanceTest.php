<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use Source\DiffFile;
use Source\Exceptions\FileChanged;
use Source\Exceptions\NotFound;
use Source\Exceptions\ReadOnlyDisk;
use Source\Source;
use Source\Tests\Conformance\Backends;

/*
 * History, reading the past, diffs and reverts, against the in-memory fake
 * and against a live server. Each test writes under its own path prefix.
 */

dataset('source backends', ['fake', 'live']);

it('returns the log newest first with metadata parsed back', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "v1\n");

    $result = $disk->commit('Draft the plan', function ($files) use ($path) {
        $files->put($path, "v2\n");
    }, meta: ['run' => '01JRUN', 'step' => 'draft']);

    $commits = $disk->history($path)->take(5)->all();

    expect($commits[0]->oid)->toBe($result->commitOid)
        ->and($commits[0]->message)->toBe('Draft the plan')
        ->and($commits[0]->subject())->toBe('Draft the plan')
        ->and($commits[0]->meta)->toBe(['run' => '01JRUN', 'step' => 'draft'])
        ->and($commits[0]->at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($commits[0]->author?->email)->toContain('@')
        ->and($commits[0]->parents)->not->toBeEmpty()
        ->and(end($commits)->message)->toBe("Create {$path}");

    // The whole-repo log is newest first too, and it is a LazyCollection:
    // pages arrive as the caller iterates.
    expect($disk->history())->toBeInstanceOf(LazyCollection::class)
        ->and($disk->history()->first()->oid)->toBe($result->commitOid);
})->with('source backends');

it('reads an old version from at() and refuses every write', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "v1\n");
    $old = $disk->history($path)->first()->oid;

    $disk->put($path, "v2\n");

    $past = $disk->at($old);

    expect($past->isReadOnly())->toBeTrue()
        ->and($past->get($path))->toBe("v1\n")
        ->and($past->read($path)->contents)->toBe("v1\n")
        ->and($past->exists($path))->toBeTrue()
        ->and($past->files("{$prefix}/notes"))->toContain($path)
        ->and($past->directories($prefix))->toContain("{$prefix}/notes")
        ->and($disk->get($path))->toBe("v2\n");

    expect(fn () => $past->put($path, "nope\n"))->toThrow(ReadOnlyDisk::class)
        ->and(fn () => $past->delete($path))->toThrow(ReadOnlyDisk::class)
        ->and(fn () => $past->commit('Nope', fn ($files) => $files->put($path, "nope\n")))->toThrow(ReadOnlyDisk::class);

    expect($disk->get($path))->toBe("v2\n");
})->with('source backends');

it('resolves at() by ref and by date', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "now\n");

    expect($disk->at($disk->store()->ref())->get($path))->toBe("now\n")
        ->and($disk->at(CarbonImmutable::now()->addMinute())->get($path))->toBe("now\n")
        ->and($disk->at('2999-01-01')->get($path))->toBe("now\n");

    expect(fn () => $disk->at('2000-01-01'))->toThrow(NotFound::class);
})->with('source backends');

it('lists the changed paths between two commits', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $modified = "{$prefix}/a.md";
    $deleted = "{$prefix}/b.md";
    $added = "{$prefix}/c.md";

    $disk->put($modified, "a1\n");
    $disk->put($deleted, "b1\n");

    $from = $disk->history()->first()->oid;

    $result = $disk->commit('Change three things', function ($files) use ($modified, $deleted, $added) {
        $files->put($modified, "a2\n");
        $files->delete($deleted);
        $files->put($added, "c1\n");
    });

    $diff = $disk->diff($from, $result->commitOid);

    expect($diff->from)->toBe($from)
        ->and($diff->to)->toBe($result->commitOid)
        ->and($diff->paths())->toBe([$modified, $deleted, $added])
        ->and($diff->file($modified)->status)->toBe(DiffFile::MODIFIED)
        ->and($diff->file($modified)->additions)->toBeGreaterThan(0)
        ->and($diff->file($modified)->deletions)->toBeGreaterThan(0)
        ->and($diff->file($modified)->hunks)->not->toBeEmpty()
        ->and($diff->file($deleted)->status)->toBe(DiffFile::DELETED)
        ->and($diff->file($added)->status)->toBe(DiffFile::ADDED)
        ->and($disk->diffText($from, $result->commitOid))->toContain($modified);
})->with('source backends');

it('reverts a commit with one new commit', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $modified = "{$prefix}/notes/keep.md";
    $deleted = "{$prefix}/notes/gone.md";
    $added = "{$prefix}/notes/new.md";

    $disk->put($modified, "keep v1\n");
    $disk->put($deleted, "gone v1\n");

    $change = $disk->commit('Rework the notes', function ($files) use ($modified, $deleted, $added) {
        $files->put($modified, "keep v2\n");
        $files->delete($deleted);
        $files->put($added, "new v1\n");
    });

    $revert = $disk->revert($change->commitOid);

    expect($disk->get($modified))->toBe("keep v1\n")
        ->and($disk->fileExists($deleted))->toBeTrue()
        ->and($disk->get($deleted))->toBe("gone v1\n")
        ->and($disk->fileExists($added))->toBeFalse();

    $head = $disk->history()->first();

    expect($head->oid)->toBe($revert->commitOid)
        ->and($head->message)->toBe('Revert "Rework the notes"')
        ->and($head->meta['Reverts'])->toBe($change->commitOid);

    if ($backend === 'fake') {
        Source::assertReverted($change->commitOid);
    }
})->with('source backends');

it('refuses the whole revert when a file changed since', function (string $backend) {
    [$disk, $other, $prefix] = Backends::make($this, $backend);
    $touched = "{$prefix}/notes/a.md";
    $untouched = "{$prefix}/notes/b.md";

    $disk->put($touched, "a1\n");
    $disk->put($untouched, "b1\n");

    $change = $disk->commit('Touch both', function ($files) use ($touched, $untouched) {
        $files->put($touched, "a2\n");
        $files->put($untouched, "b2\n");
    });

    $other->put($touched, "a3\n");

    try {
        $disk->revert($change->commitOid);

        $this->fail('A revert over a changed file was accepted.');
    } catch (FileChanged $e) {
        expect($e->paths)->toBe([$touched]);
    }

    // Nothing landed, and the driver did not retry: the last commit is still
    // the outside write.
    expect($disk->get($touched))->toBe("a3\n")
        ->and($disk->get($untouched))->toBe("b2\n")
        ->and($disk->history()->first()->message)->toBe("Update {$touched}");
})->with('source backends');
