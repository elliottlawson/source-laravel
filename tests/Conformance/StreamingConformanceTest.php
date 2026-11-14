<?php

declare(strict_types=1);

use Source\Source;
use Source\Support\Payload;
use Source\Tests\Conformance\Backends;

/*
 * The same tests run against the in-memory fake and against a live server.
 * The live run is skipped unless SOURCE_TEST_URL, SOURCE_TEST_TOKEN,
 * SOURCE_TEST_NAMESPACE and SOURCE_TEST_REPO are set.
 *
 * What can be asserted differs by backend: the wire (no bytes sent for a blob
 * the repo holds) is only observable on the fake, while the round trip and the
 * version rules are observable on both.
 */

dataset('source backends', ['fake', 'live']);

/**
 * A deterministic body of the given size with every byte value in it.
 */
function streamingBody(int $size): string
{
    $pattern = '';

    for ($byte = 0; $byte < 256; $byte++) {
        $pattern .= chr($byte);
    }

    return substr(str_repeat($pattern, intdiv($size, strlen($pattern)) + 1), 0, $size);
}

it('streams a multi-MiB file and reads it back identical', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $path = "{$prefix}/assets/stream.bin";
    $body = streamingBody(4 * 1024 * 1024);
    $expected = Payload::blobOid($body);

    $stream = fopen('php://temp/maxmemory:1048576', 'r+');
    fwrite($stream, $body);
    rewind($stream);

    expect($disk->writeStream($path, $stream))->toBeTrue()
        ->and($disk->size($path))->toBe(strlen($body));

    $file = $disk->read($path);

    expect(strlen($file->contents))->toBe(strlen($body))
        ->and(sha1($file->contents))->toBe(sha1($body))
        // The version is the Git blob id of the bytes, so a streamed write is
        // checksummed end to end.
        ->and($file->version)->toBe($expected);

    if ($backend === 'fake') {
        // On the fake the transport is inspectable: the bytes went as the body
        // of a raw PUT, not inside a commit body.
        Source::assertUploaded($path);

        expect(Source::fake()->uploadsOf($path))->toBe([strlen($body)]);
    }

    $disk->delete($path);
})->with('source backends');

it('round-trips binary content put as a string', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $path = "{$prefix}/assets/logo.bin";
    $body = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\xff\xfe\x00binary";

    $disk->put($path, $body);

    expect($disk->get($path))->toBe($body)
        ->and($disk->read($path)->version)->toBe(Payload::blobOid($body));

    $disk->delete($path);
})->with('source backends');

it('round-trips a file put from a stream', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $path = "{$prefix}/assets/from-stream.bin";
    $body = streamingBody(1024 * 1024);

    $stream = fopen('php://temp/maxmemory:131072', 'r+');
    fwrite($stream, $body);
    rewind($stream);

    expect($disk->put($path, $stream))->toBeTrue()
        ->and($disk->get($path))->toBe($body);

    if ($backend === 'fake') {
        Source::assertUploaded($path);
    }

    $disk->delete($path);
})->with('source backends');

it('commits identical content by oid instead of sending it again', function (string $backend) {
    [$disk, , $prefix] = Backends::make($this, $backend);

    $unchanged = "unchanged\n";
    $a = "{$prefix}/notes/a.md";
    $b = "{$prefix}/notes/b.md";

    $disk->put($a, $unchanged);
    $disk->put($b, "b-original\n");

    $disk->commit('Rewrite b only', function ($files) use ($a, $b, $unchanged) {
        $files->put($a, $unchanged);
        $files->put($b, "b-updated\n");
    });

    expect($disk->get($a))->toBe($unchanged)
        ->and($disk->get($b))->toBe("b-updated\n");

    if ($backend === 'fake') {
        // A batch commit that names a blob the repo holds: the change travels
        // as `{path, expected_oid, oid}` with no bytes. A live server exposes
        // no equivalent signal through the API — the commit body is not
        // readable back — so this half is asserted only on the fake.
        Source::assertReferenced($a);

        expect(Source::fake()->hasReferenced($b))->toBeFalse();
    }

    $disk->delete($a);
    $disk->delete($b);
})->with('source backends');
