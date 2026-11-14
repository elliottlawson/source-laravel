<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Source\Author;
use Source\Change;
use Source\Exceptions\OidMismatch;
use Source\Exceptions\SourceException;
use Source\Exceptions\StagingFull;
use Source\Exceptions\UploadsBusy;
use Source\Http\HttpClient;
use Source\SourceStore;
use Source\Support\Payload;
use Source\Uploads\AdaptiveConcurrency;
use Source\Uploads\ParallelBlobUploads;

/*
 * The controller around `POST /blobs` (api.md 3.4.5): which files upload, the
 * waves and their adaptive concurrency, the `429` partial-wave retry, and the
 * two compatibility paths (unknown_object re-upload, a server older than the
 * endpoint).
 */

function blobStore(?AdaptiveConcurrency $adaptive = null, int $threshold = ParallelBlobUploads::DEFAULT_INLINE_THRESHOLD): SourceStore
{
    $client = new HttpClient([
        'url' => 'https://source.test',
        'token' => 'asm_tok_test',
        'timeout' => 5,
        'upload_timeout' => 60,
    ]);

    return new SourceStore(
        $client,
        'acme',
        'workspace',
        'main',
        new Author('Elliott', 'elliott@example.com'),
        uploader: new ParallelBlobUploads(
            client: $client,
            inlineThreshold: $threshold,
            concurrency: $adaptive ?? new AdaptiveConcurrency,
        ),
    );
}

function blobOkResponse(Request $request)
{
    $oid = $request->header('X-Source-Oid')[0];

    return Http::response([
        'oid' => $oid,
        'size' => (int) $request->header('Content-Length')[0],
        'expires_at' => '2026-11-15T10:00:00.000Z',
    ], 201);
}

function busyResponse(int $retryAfter = 1)
{
    return Http::response([
        'error' => [
            'code' => 'uploads_busy',
            'message' => 'Upload capacity is busy; retry after the delay.',
            'details' => ['scope' => 'repo', 'limit_bytes' => 512 << 20, 'in_flight_bytes' => 512 << 20, 'retry_after' => $retryAfter],
            'request_id' => '01JCH9',
        ],
    ], 429, ['Retry-After' => (string) $retryAfter]);
}

function blobCommitOkResponse(array $changes)
{
    return Http::response([
        'commit' => ['oid' => str_repeat('b', 40), 'base_oid' => str_repeat('c', 40)],
        'ref' => ['name' => 'main', 'target_oid' => str_repeat('b', 40)],
        'files' => array_map(fn (array $change) => [
            'path' => $change['path'],
            'oid' => $change['oid'] ?? Payload::blobOid((string) ($change['content'] ?? '')),
            'size' => strlen((string) ($change['content'] ?? '')),
            'mode' => '100644',
        ], $changes),
    ]);
}

it('uploads the files over 64 KiB and leaves the smaller ones inline', function () {
    $small = str_repeat('s', 65_535);
    $large = str_repeat('a', 65_536);
    $presentLarge = str_repeat('p', 70_000);
    $presentOid = Payload::blobOid($presentLarge);

    $presents = [];
    $uploads = [];
    $commits = [];

    Http::fake(function (Request $request) use (&$presents, &$uploads, &$commits, $presentOid) {
        if (str_contains($request->url(), '/objects/present')) {
            $presents[] = $request->data()['oids'];

            return Http::response(['present' => [$presentOid]]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $uploads[] = $request;

            return blobOkResponse($request);
        }

        $commits[] = $request->data();

        return blobCommitOkResponse($request->data()['changes']);
    });

    blobStore()->commit('Three files', function ($files) use ($small, $large, $presentLarge) {
        $files->ifMissing()->put('small.txt', $small);
        $files->ifMissing()->put('large.txt', $large);
        $files->ifMissing()->put('present.txt', $presentLarge);
    });

    // Only the two big files are worth asking about; the tiny one never leaves
    // the commit body.
    expect($presents)->toHaveCount(1)
        ->and($presents[0])->toHaveCount(2)
        ->and($presents[0])->toContain(Payload::blobOid($large))
        ->and($presents[0])->toContain($presentOid);

    expect($uploads)->toHaveCount(1)
        ->and($uploads[0]->hasHeader('X-Source-Oid', Payload::blobOid($large)))->toBeTrue()
        ->and($uploads[0]->hasHeader('Content-Length', '65536'))->toBeTrue();

    expect($commits)->toHaveCount(1);

    $changes = $commits[0]['changes'];

    expect($changes[0])->toBe(['path' => 'small.txt', 'expected_oid' => '', 'content' => $small])
        ->and($changes[1])->toBe(['path' => 'large.txt', 'expected_oid' => '', 'oid' => Payload::blobOid($large)])
        ->and($changes[2])->toBe(['path' => 'present.txt', 'expected_oid' => '', 'oid' => $presentOid]);
});

it('uploads identical bytes once and names every path that produces them', function () {
    $body = str_repeat('d', 70_000);
    $oid = Payload::blobOid($body);

    $uploads = 0;
    $commits = [];

    Http::fake(function (Request $request) use (&$uploads, &$commits) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $uploads++;

            return blobOkResponse($request);
        }

        $commits[] = $request->data();

        return blobCommitOkResponse($request->data()['changes']);
    });

    blobStore()->commit('Duplicate bytes', function ($files) use ($body) {
        $files->ifMissing()->put('first.bin', $body);
        $files->ifMissing()->put('second.bin', $body);
    });

    expect($uploads)->toBe(1)
        ->and($commits[0]['changes'][0])->toBe(['path' => 'first.bin', 'expected_oid' => '', 'oid' => $oid])
        ->and($commits[0]['changes'][1])->toBe(['path' => 'second.bin', 'expected_oid' => '', 'oid' => $oid]);
});

it('re-uploads the refused paths after unknown_object and retries the commit once', function () {
    $body = str_repeat('u', 100_000);
    $oid = Payload::blobOid($body);

    $uploads = [];
    $presents = 0;
    $commits = 0;

    Http::fake(function (Request $request) use (&$uploads, &$presents, &$commits, $oid) {
        if (str_contains($request->url(), '/objects/present')) {
            $presents++;

            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $uploads[] = $request;

            return blobOkResponse($request);
        }

        $commits++;

        if ($commits === 1) {
            return Http::response([
                'error' => [
                    'code' => 'unknown_object',
                    'message' => 'The repo does not hold this object.',
                    'details' => ['paths' => [['path' => 'big.bin', 'oid' => $oid]]],
                    'request_id' => '01JCH9',
                ],
            ], 422);
        }

        return blobCommitOkResponse($request->data()['changes']);
    });

    blobStore()->commit('Big file', function ($files) use ($body) {
        $files->ifMissing()->put('big.bin', $body);
        $files->ifMissing()->put('note.txt', "note\n");
    });

    expect($uploads)->toHaveCount(2)
        ->and($uploads[0]->hasHeader('X-Source-Oid', $oid))->toBeTrue()
        ->and($uploads[1]->hasHeader('X-Source-Oid', $oid))->toBeTrue()
        ->and($commits)->toBe(2)
        // The forced path is not asked about again: present said what it said.
        ->and($presents)->toBe(1);
});

it('retries only the refused uploads of a wave after a 429', function () {
    $files = [
        'a.bin' => str_repeat('a', 70_000),
        'b.bin' => str_repeat('b', 70_000),
        'c.bin' => str_repeat('c', 70_000),
        'd.bin' => str_repeat('d', 70_000),
    ];

    $refused = Payload::blobOid($files['b.bin']);
    $attempts = [];
    $commits = [];

    Http::fake(function (Request $request) use (&$attempts, &$commits, $refused) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $oid = $request->header('X-Source-Oid')[0];
            $attempts[$oid] = ($attempts[$oid] ?? 0) + 1;

            if ($oid === $refused && $attempts[$oid] === 1) {
                return busyResponse(retryAfter: 3);
            }

            return blobOkResponse($request);
        }

        $commits[] = $request->data();

        return blobCommitOkResponse($request->data()['changes']);
    });

    $adaptive = new AdaptiveConcurrency;
    $store = blobStore($adaptive);

    $store->commit('Four files', function ($pending) use ($files) {
        foreach ($files as $path => $contents) {
            $pending->ifMissing()->put($path, $contents);
        }
    });

    expect($attempts[$refused])->toBe(2)
        ->and($adaptive->current())->toBe(2)
        // One wait for the whole wave, not one per refused upload.
        ->and($commits)->toHaveCount(1);

    foreach ($files as $contents) {
        $oid = Payload::blobOid($contents);
        $attempts[$oid] ??= 0;
        expect($attempts[$oid])->toBe($oid === $refused ? 2 : 1);
    }

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 3, 1);
});

it('gives up with UploadsBusy after four refused attempts', function () {
    $body = str_repeat('z', 70_000);
    $blobRequests = 0;

    Http::fake(function (Request $request) use (&$blobRequests) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $blobRequests++;

            return busyResponse(retryAfter: 1);
        }

        return blobCommitOkResponse($request->data()['changes']);
    });

    expect(fn () => blobStore()->commit('Busy', function ($files) use ($body) {
        $files->ifMissing()->put('big.bin', $body);
        $files->ifMissing()->put('note.txt', "note\n");
    }))
        ->toThrow(UploadsBusy::class);

    expect($blobRequests)->toBe(4);
    Sleep::assertSleptTimes(3);
});

it('retries an upload after a transport failure', function () {
    $body = str_repeat('r', 70_000);
    $blobRequests = 0;

    Http::fake(function (Request $request) use (&$blobRequests) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $blobRequests++;

            if ($blobRequests === 1) {
                return Http::response([
                    'error' => [
                        'code' => 'unavailable',
                        'message' => 'A dependency is unavailable; retry shortly.',
                        'details' => ['retry_after' => 1],
                        'request_id' => '01JCH9',
                    ],
                ], 503);
            }

            return blobOkResponse($request);
        }

        return blobCommitOkResponse($request->data()['changes']);
    });

    blobStore()->commit('Retry', function ($files) use ($body) {
        $files->ifMissing()->put('big.bin', $body);
        $files->ifMissing()->put('note.txt', "note\n");
    });

    expect($blobRequests)->toBe(2);
    // A transport retry is immediate; only a 429 carries a Retry-After.
    Sleep::assertSleptTimes(0);
});

it('throws when a transport failure keeps happening', function () {
    $body = str_repeat('r', 70_000);
    $blobRequests = 0;

    Http::fake(function (Request $request) use (&$blobRequests) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $blobRequests++;

            return Http::response([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An internal error occurred.',
                    'details' => [],
                    'request_id' => '01JCH9',
                ],
            ], 500);
        }

        return blobCommitOkResponse($request->data()['changes']);
    });

    expect(fn () => blobStore()->commit('Fail', function ($files) use ($body) {
        $files->ifMissing()->put('big.bin', $body);
        $files->ifMissing()->put('note.txt', "note\n");
    }))
        ->toThrow(SourceException::class);

    expect($blobRequests)->toBe(4);
});

it('never retries staging_full', function () {
    $body = str_repeat('x', 70_000);

    $blobRequests = 0;
    $commits = 0;

    Http::fake(function (Request $request) use (&$blobRequests, &$commits) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $blobRequests++;

            return Http::response([
                'error' => [
                    'code' => 'staging_full',
                    'message' => 'Uploaded-but-uncommitted bytes would exceed the staging cap.',
                    'details' => ['scope' => 'server', 'limit_bytes' => 1, 'staged_bytes' => 2, 'next_expiry_at' => null],
                    'request_id' => '01JCH9',
                ],
            ], 409);
        }

        $commits++;

        return blobCommitOkResponse($request->data()['changes']);
    });

    expect(fn () => blobStore()->commit('Full', function ($files) use ($body) {
        $files->ifMissing()->put('big.bin', $body);
        $files->ifMissing()->put('note.txt', "note\n");
    }))
        ->toThrow(StagingFull::class);

    expect($blobRequests)->toBe(1)
        ->and($commits)->toBe(0);
});

it('never retries oid_mismatch', function () {
    $body = str_repeat('x', 70_000);
    $blobRequests = 0;

    Http::fake(function (Request $request) use (&$blobRequests) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $blobRequests++;

            return Http::response([
                'error' => [
                    'code' => 'oid_mismatch',
                    'message' => 'The uploaded bytes do not hash to X-Source-Oid.',
                    'details' => ['expected_oid' => str_repeat('a', 40), 'actual_oid' => str_repeat('b', 40)],
                    'request_id' => '01JCH9',
                ],
            ], 422);
        }

        return blobCommitOkResponse($request->data()['changes']);
    });

    expect(fn () => blobStore()->commit('Mismatch', function ($files) use ($body) {
        $files->ifMissing()->put('big.bin', $body);
        $files->ifMissing()->put('note.txt', "note\n");
    }))
        ->toThrow(OidMismatch::class);

    expect($blobRequests)->toBe(1);
});

it('rewinds a stream before retrying a refused upload', function () {
    $body = str_repeat('w', 70_000);

    $stream = fopen('php://temp/maxmemory:1048576', 'r+');
    fwrite($stream, $body);
    rewind($stream);

    $payload = Payload::from($stream);
    $oid = $payload->oid();

    $attempts = 0;
    $bodies = [];

    Http::fake(function (Request $request) use (&$attempts, &$bodies) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        $attempts++;
        $bodies[] = $request->body();

        return $attempts === 1 ? busyResponse(retryAfter: 1) : blobOkResponse($request);
    });

    $client = new HttpClient(['url' => 'https://source.test', 'token' => 'asm_tok_test']);

    $prepared = (new ParallelBlobUploads($client))
        ->prepare('acme', 'workspace', 'main', [
            Change::stream('big.bin', '', $payload),
        ]);

    expect($attempts)->toBe(2)
        // The same bytes, from the first one: a retry is not a truncated body.
        ->and(strlen($bodies[0]))->toBe(strlen($body))
        ->and($bodies[1])->toBe($bodies[0])
        ->and($prepared[0]->isReference())->toBeTrue()
        ->and($prepared[0]->oid)->toBe($oid);
});

it('sends bytes inline for this and later commits when the server has no /blobs', function () {
    $first = str_repeat('1', 70_000);
    $second = str_repeat('2', 70_000);
    $firstOid = Payload::blobOid($first);

    $blobRequests = 0;
    $commits = [];

    Http::fake(function (Request $request) use (&$blobRequests, &$commits) {
        if (str_contains($request->url(), '/objects/present')) {
            return Http::response(['present' => []]);
        }

        if (str_contains($request->url(), '/blobs')) {
            $blobRequests++;

            return Http::response([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                    'details' => ['resource' => 'path', 'id' => '/v1/namespaces/acme/repos/workspace/blobs'],
                    'request_id' => '01JCH9',
                ],
            ], 404);
        }

        $commits[] = $request->data();

        return blobCommitOkResponse($request->data()['changes']);
    });

    $store = blobStore();

    $store->commit('First', function ($files) use ($first) {
        $files->ifMissing()->put('big.bin', $first);
        $files->ifMissing()->put('note.txt', "note\n");
    });
    $store->commit('Second', function ($files) use ($firstOid, $second) {
        $files->ifUnchanged($firstOid)->put('big.bin', $second);
        $files->ifUnchanged(Payload::blobOid("note\n"))->put('note.txt', "note two\n");
    });

    expect($blobRequests)->toBe(1)
        ->and($commits)->toHaveCount(2)
        // The old server gets the bytes in the commit body, which is what the
        // package did before /blobs existed.
        ->and($commits[0]['changes'][0])->toBe(['path' => 'big.bin', 'expected_oid' => '', 'content' => $first])
        ->and($commits[1]['changes'][0])->toBe(['path' => 'big.bin', 'expected_oid' => $firstOid, 'content' => $second]);
});
