<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Source\Author;
use Source\CommitResult;
use Source\Exceptions\Conflict;
use Source\Exceptions\FileChanged;
use Source\Exceptions\OidMismatch;
use Source\Http\Client;
use Source\Http\HttpClient;
use Source\Source;
use Source\SourceStore;
use Source\Support\Payload;

/*
 * A raw file write carries both halves of the idempotency contract:
 * `X-Source-Oid` with the Git blob id of the body, so the server
 * settles a replay and the stale-write check before it reads anything, and one
 * `Idempotency-Key` per attempt, reused by the transport across its own
 * retries.
 */

function idemStore(): SourceStore
{
    return new SourceStore(
        new HttpClient(['url' => 'https://source.test', 'token' => 'asm_tok_test', 'timeout' => 30]),
        'acme',
        'workspace',
        'main',
        new Author('Elliott', 'elliott@example.com'),
    );
}

/**
 * The `{commit, file, ref}` body a raw file write returns.
 */
function idemWrite(array $headers = [])
{
    return Http::response([
        'commit' => ['oid' => str_repeat('b', 40), 'base_oid' => str_repeat('c', 40)],
        'ref' => ['name' => 'main', 'target_oid' => str_repeat('b', 40)],
        'file' => ['path' => 'notes/plan.md', 'oid' => str_repeat('d', 40), 'size' => 5, 'mode' => '100644'],
    ], 200, $headers);
}

function idemHead(string $oid)
{
    return Http::response('', 200, [
        'X-Object-Oid' => $oid,
        'X-Object-Size' => '5',
        'X-Object-Mode' => '100644',
        'Last-Modified' => 'Sat, 14 Nov 2026 10:00:00 GMT',
    ]);
}

function idemChanged()
{
    return Http::response([
        'error' => [
            'code' => 'file_changed',
            'message' => 'The file changed since you read it.',
            'details' => [
                'ref' => 'main',
                'paths' => [['path' => 'notes/plan.md', 'expected_oid' => str_repeat('1', 40), 'changed_by' => [
                    'commit_oid' => str_repeat('9', 40),
                    'token_id' => '01JCH4',
                    'actor' => 'agent',
                    'at' => '2026-11-14T10:00:00.000Z',
                ]]],
            ],
            'request_id' => '01JCH9',
        ],
    ], 409);
}

function idemBusy()
{
    return Http::response([
        'error' => [
            'code' => 'uploads_busy',
            'message' => 'Upload capacity is busy; retry after the delay.',
            'details' => ['scope' => 'repo', 'limit_bytes' => 1024, 'in_flight_bytes' => 1024, 'retry_after' => 1],
            'request_id' => '01JCH9',
        ],
    ], 429, ['Retry-After' => '1']);
}

it('declares the blob oid and a uuid key on a raw put', function () {
    $puts = [];

    Http::fake(function (Request $request) use (&$puts) {
        if ($request->method() === 'HEAD') {
            return idemHead(str_repeat('1', 40));
        }

        $puts[] = $request;

        return idemWrite();
    });

    idemStore()->put('notes/plan.md', 'hello');

    expect($puts)->toHaveCount(1);

    $put = $puts[0];

    expect($put->header('X-Source-Oid')[0])->toBe(Payload::blobOid('hello'))
        ->and($put->hasHeader('X-Source-Oid', Payload::blobOid('hello')))->toBeTrue()
        ->and($put->header('Idempotency-Key'))->toHaveCount(1)
        ->and($put->header('Idempotency-Key')[0])
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        // The declared oid, the version being replaced and the size are three
        // different values: the oid names the bytes we are sending.
        ->and($put->header('X-Source-Oid')[0])->not->toBe(str_repeat('1', 40));
});

it('declares the blob oid of a streamed body', function () {
    $body = str_repeat('streamed bytes ', 150_000);
    $stream = fopen('php://temp/maxmemory:1048576', 'r+');
    fwrite($stream, $body);
    rewind($stream);

    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        return idemWrite();
    });

    idemStore()->put('notes/big.txt', $stream);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->hasHeader('X-Source-Oid', Payload::blobOid($body))
        && $request->hasHeader('Content-Length', (string) strlen($body)));
});

it('reuses one key across the transport retries of the same attempt', function () {
    $attempts = 0;
    $keys = [];
    $oids = [];

    Http::fake(function (Request $request) use (&$attempts, &$keys, &$oids) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $attempts++;
        $keys[] = $request->header('Idempotency-Key')[0] ?? null;
        $oids[] = $request->header('X-Source-Oid')[0] ?? null;

        return $attempts <= 2 ? idemBusy() : idemWrite();
    });

    idemStore()->put('notes/plan.md', 'hello');

    expect($attempts)->toBe(3)
        // One key for the attempt: the server releases it on a refusal, so the
        // same key may be sent again with the same request.
        ->and(array_unique($keys))->toHaveCount(1)
        ->and($keys[0])->not->toBeNull()
        ->and(array_unique($oids))->toBe([Payload::blobOid('hello')]);

    Sleep::assertSleptTimes(2);
});

it('uses a fresh key for each last-write-wins attempt', function () {
    $puts = 0;
    $keys = [];

    Http::fake(function (Request $request) use (&$puts, &$keys) {
        if ($request->method() === 'HEAD') {
            return idemHead(str_repeat('1', 40));
        }

        $puts++;
        $keys[] = $request->header('Idempotency-Key')[0] ?? null;

        return $puts === 1 ? idemChanged() : idemWrite();
    });

    idemStore()->put('notes/plan.md', 'hello');

    expect($puts)->toBe(2)
        // A re-read-and-rewrite is a new request: a successful one stored
        // under the old key would make this one a 409 idempotency_conflict.
        ->and($keys[0])->not->toBeNull()
        ->and($keys[1])->not->toBeNull()
        ->and($keys[0])->not->toBe($keys[1]);
});

it('accepts an Idempotent-Replay response as a success', function () {
    $puts = 0;

    Http::fake(function (Request $request) use (&$puts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $puts++;

        return idemWrite(['Idempotent-Replay' => 'true']);
    });

    $result = idemStore()->put('notes/plan.md', 'hello');

    // The replay is the first success's own body, so it parses and returns
    // like the original answer: no new commit, no exception.
    expect($puts)->toBe(1)
        ->and($result->commitOid)->toBe(str_repeat('b', 40))
        ->and($result->version('notes/plan.md'))->toBe(str_repeat('d', 40));
});

it('maps a 422 oid_mismatch on a raw put to OidMismatch and never retries it', function () {
    $puts = 0;

    Http::fake(function (Request $request) use (&$puts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $puts++;

        return Http::response([
            'error' => [
                'code' => 'oid_mismatch',
                'message' => 'The bytes do not hash to X-Source-Oid.',
                'details' => ['expected_oid' => str_repeat('e', 40), 'actual_oid' => str_repeat('f', 40)],
                'request_id' => '01JCH9',
            ],
        ], 422);
    });

    try {
        idemStore()->put('notes/plan.md', 'hello');

        $this->fail('An oid mismatch was accepted.');
    } catch (OidMismatch $e) {
        expect($e->errorCode)->toBe('oid_mismatch')
            ->and($e->expectedOid)->toBe(str_repeat('e', 40))
            ->and($e->actualOid)->toBe(str_repeat('f', 40))
            ->and($puts)->toBe(1);
    }

    // Sending the same bytes again cannot help, so nothing is retried.
    Sleep::assertSleptTimes(0);
});

// --- the fake server --------------------------------------------------------

function idemFakePut(Client $client, string $path, string $contents, string $expectedOid, string $key): CommitResult
{
    return $client->putFile(
        'acme',
        'workspace',
        'main',
        $path,
        Payload::from($contents),
        $expectedOid,
        'Write '.$path,
        new Author('Elliott', 'elliott@example.com'),
        null,
        $key,
    );
}

it('replays a raw put the fake already accepted under the same key', function () {
    $fake = Source::fake();
    $key = '5b0f9d0e-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    $first = idemFakePut($fake, 'notes/plan.md', 'hello', '', $key);
    $second = idemFakePut($fake, 'notes/plan.md', 'hello', '', $key);

    expect($second->commitOid)->toBe($first->commitOid)
        ->and($fake->commits())->toHaveCount(1)
        ->and($fake->replays())->toHaveCount(1)
        ->and($fake->replays()[0])->toBe(['path' => 'notes/plan.md', 'key' => $key]);
});

it('refuses the same key with a different request as idempotency_conflict', function () {
    $fake = Source::fake();
    $key = '5b0f9d0e-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    idemFakePut($fake, 'notes/plan.md', 'hello', '', $key);

    try {
        idemFakePut($fake, 'notes/plan.md', 'something else', '', $key);

        $this->fail('A key was reused for a different body.');
    } catch (Conflict $e) {
        expect($e->errorCode)->toBe('idempotency_conflict')
            ->and($e->details['key'])->toBe($key);
    }

    expect($fake->commits())->toHaveCount(1);
});

it('releases the key when the fake refuses the write, so the retry may use it', function () {
    $fake = Source::fake();
    $key = '5b0f9d0e-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    // Another writer got there first, without this key.
    idemFakePut($fake, 'notes/plan.md', 'hello', '', 'a-key-used-by-someone-else');

    // This request names a version that is no longer the file's: a refusal, and
    // the key is released rather than stored.
    expect(fn () => idemFakePut($fake, 'notes/plan.md', 'mine', '', $key))
        ->toThrow(FileChanged::class);

    $result = idemFakePut($fake, 'notes/plan.md', 'mine', Payload::blobOid('hello'), $key);

    expect($result->version('notes/plan.md'))->toBe(Payload::blobOid('mine'))
        ->and($fake->replays())->toBe([]);
});

it('answers oid_mismatch in the fake when the bytes do not hash to the declared oid', function () {
    $fake = Source::fake()->simulateOidMismatch(1);
    $key = '5b0f9d0e-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    try {
        idemFakePut($fake, 'notes/plan.md', 'hello', '', $key);

        $this->fail('A mismatched oid was accepted.');
    } catch (OidMismatch $e) {
        expect($e->expectedOid)->toBe(Payload::blobOid('hello'))
            ->and($e->actualOid)->not->toBe(Payload::blobOid('hello'));
    }

    // Nothing was written and the key was released: the same key may run the
    // corrected write.
    expect($fake->commits())->toBe([]);

    idemFakePut($fake, 'notes/plan.md', 'hello', '', $key);

    expect($fake->commits())->toHaveCount(1);
});
