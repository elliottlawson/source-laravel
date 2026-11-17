<?php

declare(strict_types=1);

use Source\Source;
use Source\Support\Payload;
use Source\Tests\Conformance\Backends;

/*
 * A multi-file commit is one commit whose files travel as their own blob
 * uploads first. The same tests run against the fake and a live
 * server; the live run is skipped unless the SOURCE_TEST_* variables are set.
 *
 * What can be asserted differs by backend. The fake exposes the wire: how many
 * uploads happened, whether a change was an oid reference, how many commits
 * landed. A live server exposes only the round trip and the commit that
 * resulted, so the wire assertions are fake-only.
 */

dataset('blob upload backends', ['fake', 'live']);

/**
 * A deterministic body of the given size, above the 64 KiB inline threshold.
 */
function blobConformanceBody(string $seed, int $size = 96 * 1024): string
{
    return substr(str_repeat($seed, intdiv($size, strlen($seed)) + 1), 0, $size);
}

it('uploads several large files and lands them as one commit', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $files = [
        "{$prefix}/assets/a.bin" => blobConformanceBody('a'),
        "{$prefix}/assets/b.bin" => blobConformanceBody('b'),
        "{$prefix}/assets/c.bin" => blobConformanceBody('c'),
    ];

    $before = $backend === 'fake' ? count(Source::fake()->commits()) : 0;

    $result = $disk->commit('Upload three assets', function ($pending) use ($files) {
        foreach ($files as $path => $body) {
            $pending->ifMissing()->put($path, $body);
        }
    });

    expect($result->commitOid)->not->toBe('');

    foreach ($files as $path => $body) {
        expect($disk->size($path))->toBe(strlen($body))
            ->and(sha1($disk->get($path)))->toBe(sha1($body))
            ->and($disk->read($path)->version)->toBe(Payload::blobOid($body));
    }

    if ($backend === 'fake') {
        // One commit, not one per file.
        expect(Source::fake()->commits())->toHaveCount($before + 1);

        foreach (array_keys($files) as $path) {
            Source::assertBlobUploaded($path);
            Source::assertReferenced($path);
        }
    }

    foreach (array_keys($files) as $path) {
        $disk->delete($path);
    }
})->with('blob upload backends');

it('uploads nothing new when the same content is committed again', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $path = "{$prefix}/assets/repeated.bin";
    $body = blobConformanceBody('r');

    $disk->commit('First', function ($pending) use ($path, $body, $prefix) {
        $pending->ifMissing()->put($path, $body);
        $pending->ifMissing()->put("{$prefix}/notes/marker.txt", "one\n");
    });

    $uploads = $backend === 'fake' ? Source::fake()->blobUploadCount() : 0;
    $version = $disk->version($path);

    $disk->commit('Second', function ($pending) use ($path, $body, $version, $prefix) {
        $pending->ifUnchanged($version)->put($path, $body);
        $pending->ifUnchanged(Payload::blobOid("one\n"))->put("{$prefix}/notes/marker.txt", "two\n");
    });

    expect($disk->get($path))->toBe($body);

    if ($backend === 'fake') {
        // The bytes are already reachable, so `objects/present` named the blob
        // and the commit referenced it: no upload, no bytes.
        expect(Source::fake()->blobUploadCount())->toBe($uploads);
        Source::assertReferenced($path);
    }
    // A live server exposes neither the upload nor the commit body, so the
    // round trip above is the observable half there.

    $disk->delete($path);
    $disk->delete("{$prefix}/notes/marker.txt");
})->with('blob upload backends');

it('re-uploads bytes whose staging grace period expired before the commit', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    if ($backend === 'live') {
        // The live server's grace period is 24 hours and cannot be expired on
        // demand through the API, so this case exists only against the fake.
        $this->markTestSkipped('The live server cannot expire staged uploads on demand.');
    }

    $path = "{$prefix}/assets/expiring.bin";
    $body = blobConformanceBody('e');
    $oid = Payload::blobOid($body);

    $expired = false;

    Source::fake()->beforeWrite(function ($fake) use (&$expired) {
        if (! $expired) {
            $expired = true;
            $fake->expireUploads();
        }
    });

    $disk->commit('After expiry', function ($pending) use ($path, $body, $prefix) {
        $pending->ifMissing()->put($path, $body);
        $pending->ifMissing()->put("{$prefix}/notes/marker.txt", "one\n");
    });

    // The first upload expired before the commit named it, the commit was
    // refused with unknown_object, and the driver sent the bytes again once.
    expect($disk->get($path))->toBe($body)
        ->and($disk->read($path)->version)->toBe($oid)
        ->and(Source::fake()->blobUploadCount())->toBe(2)
        ->and(Source::fake()->hasBlobUploaded($oid))->toBeTrue();

    Source::assertReferenced($path);

    $disk->delete($path);
    $disk->delete("{$prefix}/notes/marker.txt");
})->with('blob upload backends');
