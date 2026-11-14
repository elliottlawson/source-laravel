<?php

declare(strict_types=1);

use Source\Exceptions\Forbidden;
use Source\Exceptions\MergeConflict;
use Source\Http\HttpClient;
use Source\Source;
use Source\SourceFilesystem;
use Source\Tests\Conformance\Backends;
use Source\Tests\TestCase;

/*
 * Repos and branches, against the in-memory fake and against a live server.
 *
 * Every test writes under its own path prefix and gives its branches their
 * own names, because the live run shares one repo between tests.
 */

dataset('source backends', ['fake', 'live']);

/**
 * A branch disk, or a skip when the live token cannot write branch refs.
 *
 * Creating a session mints a token for the branch, and the server refuses to
 * narrow nothing: a token scoped to `main` cannot create a branch at all
 * (403 forbidden). That is a credential limit, not a client failure, so the
 * live case says what it needs instead of failing.
 */
function branchDisk(TestCase $test, SourceFilesystem $disk, string $name, string $backend): SourceFilesystem
{
    try {
        return $disk->branch($name);
    } catch (Forbidden $e) {
        if ($backend === 'live') {
            $test->markTestSkipped(
                'The live conformance token cannot write branch refs, so branches cannot be tested: '.$e->getMessage(),
            );
        }

        throw $e;
    }
}

it('scopes repo() to the other repo', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $other = $backend === 'live' ? Backends::otherRepo() : 'workspace-other';

    if ($other === null) {
        $this->markTestSkipped('Set SOURCE_TEST_REPO_OTHER to a second repo in the same namespace to run this live case.');
    }

    $path = "{$prefix}/notes/plan.md";

    $disk->put($path, "original\n");

    $switched = $disk->repo($other);
    $switched->put($path, "other repo\n");

    expect($switched->get($path))->toBe("other repo\n")
        ->and($disk->get($path))->toBe("original\n")
        ->and($switched->store()->repo())->toBe($other)
        ->and($disk->store()->repo())->not->toBe($other)
        ->and($switched->store()->ref())->toBe($disk->store()->ref());
})->with('source backends');

it('writes on a branch and merges it cleanly', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/plan.md";
    $target = $disk->store()->ref();

    $disk->put($path, "original\n");

    $draft = branchDisk($this, $disk, "{$prefix}-clean", $backend);

    expect($draft->sessionId())->not->toBeNull()
        ->and($draft->store()->ref())->toBe("{$prefix}-clean")
        ->and($draft->get($path))->toBe("original\n");

    $draft->put($path, "draft\n");

    // The branch is isolated from the branch it forked from.
    expect($disk->get($path))->toBe("original\n")
        ->and($draft->get($path))->toBe("draft\n");

    $merge = $draft->mergeInto($target);

    expect($merge->target)->toBe(HttpClient::shortRef($target))
        ->and($merge->state)->toBe('merged')
        ->and($merge->fastForward)->toBeTrue()
        ->and($disk->get($path))->toBe("draft\n");

    // branch() on the same name finds the existing session instead of
    // creating a second one.
    expect($draft->branch("{$prefix}-clean")->sessionId())->toBe($draft->sessionId());
})->with('source backends');

it('reports a conflicting merge and resolves it', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/conflict.md";
    $target = $disk->store()->ref();

    $disk->put($path, "base\n");

    $draft = branchDisk($this, $disk, "{$prefix}-conflict", $backend);

    $draft->put($path, "theirs\n");
    $disk->put($path, "ours\n");

    try {
        $draft->mergeInto($target);

        $this->fail('A conflicting merge was reported as clean.');
    } catch (MergeConflict $e) {
        expect($e->paths())->toBe([$path])
            ->and($e->mergeId())->not->toBeNull()
            ->and($e->target())->toBe(HttpClient::shortRef($target))
            ->and($e->conflicts())->toHaveCount(1)
            ->and($e->details['merge_base_oid'])->toMatch('/^[0-9a-f]{40}$/');

        $resolved = $e->resolve([$path => 'theirs']);

        expect($resolved->state)->toBe('resolved')
            ->and($resolved->id)->not->toBeNull();
    }

    // The target did not move on resolve; the merge commit is on the session
    // branch, so the next merge is a fast-forward.
    expect($disk->get($path))->toBe("ours\n");

    $merge = $draft->mergeInto($target);

    expect($merge->fastForward)->toBeTrue()
        ->and($disk->get($path))->toBe("theirs\n");
})->with('source backends');

it('aborts a conflicting merge and keeps the target', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);
    $path = "{$prefix}/notes/abort.md";
    $target = $disk->store()->ref();

    $disk->put($path, "base\n");

    $draft = branchDisk($this, $disk, "{$prefix}-abort", $backend);

    $draft->put($path, "theirs\n");
    $disk->put($path, "ours\n");

    try {
        $draft->mergeInto($target);

        $this->fail('A conflicting merge was reported as clean.');
    } catch (MergeConflict $e) {
        $e->abort();
    }

    expect($disk->get($path))->toBe("ours\n");

    // The session is open again, so the branch still writes.
    $draft->put($path, "later\n");

    expect($draft->get($path))->toBe("later\n")
        ->and($disk->get($path))->toBe("ours\n");

    if ($backend === 'fake') {
        Source::assertCommitted("Update {$path}");
    }
})->with('source backends');
