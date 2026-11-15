<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Source\BlobUpload;
use Source\Exceptions\NotFound;
use Source\Exceptions\OidMismatch;
use Source\Exceptions\StagingFull;
use Source\Http\HttpClient;
use Source\Support\Payload;
use Source\Uploads\PendingBlob;

/*
 * `POST /blobs` on the wire: the file is the streamed body, its
 * oid travels beside it, and the answer is checked. The adapter is also where
 * staging_full and oid_mismatch become typed exceptions.
 */

function blobClient(array $config = [], ?Closure $pool = null): HttpClient
{
    return new HttpClient(array_replace([
        'url' => 'https://source.test',
        'token' => 'asm_tok_test',
        'timeout' => 5,
        'upload_timeout' => 60,
    ], $config), pool: $pool);
}

function blobResponse(string $oid, int $size, int $status = 201, string $expires = '2026-11-15T10:00:00.000Z')
{
    return Http::response([
        'oid' => $oid,
        'size' => $size,
        'expires_at' => $expires,
    ], $status);
}

function blobError(string $code, array $details = [], int $status = 422)
{
    return Http::response([
        'error' => [
            'code' => $code,
            'message' => 'It was refused.',
            'details' => (object) $details,
            'request_id' => '01JCH8REQUESTID',
        ],
    ], $status);
}

it('uploads one blob with the bytes as a streamed body and the oid in a header', function () {
    $body = "some bytes\n";
    $oid = Payload::blobOid($body);

    Http::fake(fn () => blobResponse($oid, strlen($body)));

    $result = blobClient()->uploadBlob('acme', 'workspace', Payload::from($body), $oid);

    expect($result)->toBeInstanceOf(BlobUpload::class)
        ->and($result->oid)->toBe($oid)
        ->and($result->size)->toBe(strlen($body))
        ->and($result->expiresAt)->toBe('2026-11-15T10:00:00.000Z')
        ->and($result->existing)->toBeFalse();

    Http::assertSent(function (Request $request) use ($body, $oid) {
        return $request->method() === 'POST'
            && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/blobs'
            && $request->hasHeader('Content-Type', 'application/octet-stream')
            && $request->hasHeader('Content-Length', (string) strlen($body))
            && $request->hasHeader('X-Source-Oid', $oid)
            // curl waits for 100-continue, so a refusal costs no body.
            && $request->hasHeader('Expect', '100-Continue')
            // The upload is idempotent by content; a key would make the server
            // read the body before it could refuse a busy one.
            && ! $request->hasHeader('Idempotency-Key')
            && $request->hasHeader('Authorization', 'Bearer asm_tok_test')
            && $request->body() === $body;
    });
});

it('accepts a 200 as an upload the repo already held', function () {
    $oid = Payload::blobOid('same');

    Http::fake(fn () => blobResponse($oid, 4, status: 200));

    $result = blobClient()->uploadBlob('acme', 'workspace', Payload::from('same'), $oid);

    expect($result->existing)->toBeTrue()
        ->and($result->oid)->toBe($oid);
});

it('refuses an answer whose oid is not the one we sent', function () {
    Http::fake(fn () => blobResponse(str_repeat('a', 40), 4));

    expect(fn () => blobClient()->uploadBlob('acme', 'workspace', Payload::from('same'), str_repeat('b', 40)))
        ->toThrow(OidMismatch::class);
});

it('maps 409 staging_full to StagingFull', function () {
    Http::fake(fn () => blobError('staging_full', [
        'scope' => 'repo',
        'limit_bytes' => 1024,
        'staged_bytes' => 2048,
        'next_expiry_at' => '2026-11-15T10:00:00.000Z',
    ], 409));

    try {
        blobClient()->uploadBlob('acme', 'workspace', Payload::from('x'), Payload::blobOid('x'));
        $this->fail('The staging cap accepted the upload.');
    } catch (StagingFull $e) {
        expect($e->errorCode)->toBe('staging_full')
            ->and($e->scope)->toBe('repo')
            ->and($e->limitBytes)->toBe(1024)
            ->and($e->stagedBytes)->toBe(2048)
            ->and($e->nextExpiryAt)->toBe('2026-11-15T10:00:00.000Z');
    }
});

it('maps 422 oid_mismatch to OidMismatch', function () {
    Http::fake(fn () => blobError('oid_mismatch', [
        'expected_oid' => str_repeat('a', 40),
        'actual_oid' => str_repeat('b', 40),
    ]));

    try {
        blobClient()->uploadBlob('acme', 'workspace', Payload::from('x'), str_repeat('a', 40));
        $this->fail('A mismatched upload was accepted.');
    } catch (OidMismatch $e) {
        expect($e->expectedOid)->toBe(str_repeat('a', 40))
            ->and($e->actualOid)->toBe(str_repeat('b', 40));
    }
});

it('recognises the router 404 that means the server has no /blobs', function () {
    Http::fake(fn () => blobError('not_found', ['resource' => 'path', 'id' => '/v1/namespaces/acme/repos/workspace/blobs'], 404));

    try {
        blobClient()->uploadBlob('acme', 'workspace', Payload::from('x'), Payload::blobOid('x'));
        $this->fail('A missing route accepted the upload.');
    } catch (NotFound $e) {
        expect($e->resource())->toBe('path');
    }
});

it('sends a wave through one pool with several uploads in flight', function () {
    $seen = [];
    $pool = function (callable $callback, int $concurrency) use (&$seen) {
        $seen[] = $concurrency;

        return Http::pool($callback, $concurrency);
    };

    Http::fake(function (Request $request) {
        $oid = $request->header('X-Source-Oid')[0];

        return blobResponse($oid, 1);
    });

    $blobs = [];

    for ($i = 0; $i < 6; $i++) {
        $contents = 'blob '.$i;
        $blobs[] = new PendingBlob('file-'.$i, Payload::from($contents), Payload::blobOid($contents));
    }

    $results = blobClient(pool: $pool)->uploadBlobs('acme', 'workspace', $blobs, 4);

    expect($seen)->toBe([4])
        ->and($results)->toHaveCount(6);

    foreach ($blobs as $blob) {
        expect($results[$blob->key])->toBeInstanceOf(BlobUpload::class)
            ->and($results[$blob->key]->oid)->toBe($blob->oid);
    }

    Http::assertSentCount(6);
});

it('streams a 32 MiB upload without buffering it', function () {
    $size = 32 * 1024 * 1024;

    $stream = fopen('php://temp/maxmemory:1048576', 'r+');
    $chunk = str_repeat('a', 1024 * 1024);

    for ($written = 0; $written < $size; $written += strlen($chunk)) {
        fwrite($stream, $chunk);
    }

    rewind($stream);
    unset($chunk);

    $payload = Payload::from($stream);
    $oid = $payload->oid();

    Http::fake(fn () => blobResponse($oid, $size));

    $before = memory_get_peak_usage(true);

    blobClient()->uploadBlob('acme', 'workspace', $payload, $oid);

    $peak = memory_get_peak_usage(true) - $before;

    // Reading the file into a string would cost the file's size; streaming it
    // costs a buffer.
    expect($peak)->toBeLessThan(8 * 1024 * 1024);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->hasHeader('Content-Length', (string) $size)
        && $request->hasHeader('X-Source-Oid', $oid)
        && $request->hasHeader('Content-Type', 'application/octet-stream'));
});
