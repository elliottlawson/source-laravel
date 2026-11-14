<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Source\Author;
use Source\Exceptions\FileChanged;
use Source\Exceptions\FileTooLarge;
use Source\Exceptions\UploadsBusy;
use Source\Http\HttpClient;
use Source\Source;
use Source\SourceStore;

/*
 * The raw-bytes write path (api.md 3.4.2): one file's bytes are the request
 * body, everything else travels in headers, and a stream is never buffered.
 */

function streamStore(?Author $author = null): SourceStore
{
    return new SourceStore(
        new HttpClient(['url' => 'https://source.test', 'token' => 'asm_tok_test', 'timeout' => 30]),
        'acme',
        'workspace',
        'main',
        $author ?? new Author('Elliott', 'elliott@example.com'),
    );
}

/**
 * The `{commit, file, ref}` body a raw file write returns.
 */
function fileWriteResponse(string $path, string $oid = 'dddddddddddddddddddddddddddddddddddddddd', int $size = 5): array
{
    return [
        'commit' => ['oid' => str_repeat('b', 40), 'base_oid' => str_repeat('c', 40)],
        'ref' => ['name' => 'main', 'target_oid' => str_repeat('b', 40)],
        'file' => ['path' => $path, 'oid' => $oid, 'size' => $size, 'mode' => '100644'],
    ];
}

function headWith(string $oid)
{
    return Http::response('', 200, [
        'X-Object-Oid' => $oid,
        'X-Object-Size' => '5',
        'X-Object-Mode' => '100644',
        'Last-Modified' => 'Sat, 14 Nov 2026 10:00:00 GMT',
    ]);
}

it('sends one file as a raw PUT with its version, size and message in headers', function () {
    $oid = str_repeat('1', 40);
    $puts = [];

    Http::fake(function (Request $request) use (&$puts, $oid) {
        if ($request->method() === 'HEAD') {
            return headWith($oid);
        }

        $puts[] = $request;

        return Http::response(fileWriteResponse('notes/plan.md'));
    });

    $message = "Update notes/plan.md\n\nRun: 01JCH3RUN";

    $result = streamStore()
        ->withMeta(['Run' => '01JCH3RUN'])
        ->put('notes/plan.md', 'hello');

    expect($result->commitOid)->toBe(str_repeat('b', 40))
        ->and($result->version('notes/plan.md'))->toBe(str_repeat('d', 40))
        ->and($puts)->toHaveCount(1);

    $put = $puts[0];

    expect($put->method())->toBe('PUT')
        ->and($put->url())->toBe('https://source.test/v1/namespaces/acme/repos/workspace/files/main/notes/plan.md')
        ->and($put->hasHeader('Content-Type', 'application/octet-stream'))->toBeTrue()
        ->and($put->hasHeader('Content-Length', '5'))->toBeTrue()
        ->and($put->hasHeader('If-Match', '"'.$oid.'"'))->toBeTrue()
        ->and($put->hasHeader('If-None-Match'))->toBeFalse()
        ->and($put->hasHeader('Expect', '100-continue'))->toBeTrue()
        ->and($put->header('X-Source-Author')[0])->toBe(rawurlencode('Elliott <elliott@example.com>'))
        ->and(rawurldecode($put->header('X-Source-Message')[0]))->toBe($message)
        ->and($put->header('X-Source-Message')[0])->toContain('%0A')
        ->and($put->header('X-Source-Message')[0])->toContain('%20')
        ->and($put->body())->toBe('hello');

    // A UUID is inside the API's idempotency-key alphabet (api.md 1.5).
    expect($put->header('Idempotency-Key')[0])->toMatch('/^[A-Za-z0-9._:-]{8,255}$/');
});

it('creates a missing file with If-None-Match: *', function () {
    $puts = [];

    Http::fake(function (Request $request) use (&$puts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $puts[] = $request;

        return Http::response(fileWriteResponse('notes/new.md'));
    });

    streamStore()->put('notes/new.md', 'created');

    expect($puts[0]->hasHeader('If-None-Match', '*'))->toBeTrue()
        ->and($puts[0]->hasHeader('If-Match'))->toBeFalse();
});

it('streams a 32 MiB file without buffering it', function () {
    $size = 32 * 1024 * 1024;

    $stream = fopen('php://temp/maxmemory:1048576', 'r+');
    $chunk = str_repeat('a', 1024 * 1024);

    for ($written = 0; $written < $size; $written += strlen($chunk)) {
        fwrite($stream, $chunk);
    }

    rewind($stream);
    unset($chunk);

    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        return Http::response(fileWriteResponse('big.bin', size: 32 * 1024 * 1024));
    });

    $before = memory_get_peak_usage(true);

    streamStore()->put('big.bin', $stream);

    $peak = memory_get_peak_usage(true) - $before;

    // Reading the file into a string would cost the file's size; streaming it
    // costs a buffer.
    expect($peak)->toBeLessThan(8 * 1024 * 1024);

    Http::assertSent(function (Request $request) use ($size) {
        // The body is detached once the request finishes (so Guzzle cannot
        // close a stream the caller still owns), so the recorded request is
        // asserted by its declared length; the memory bound below is what
        // proves the bytes were streamed rather than copied.
        return $request->method() === 'PUT'
            && $request->hasHeader('Content-Length', (string) $size)
            && $request->hasHeader('Content-Type', 'application/octet-stream');
    });
});

it('spools a non-seekable stream, sends its length, and can send it again', function () {
    [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    fwrite($writer, 'bytes from a pipe');
    fclose($writer);

    $attempts = 0;
    $bodies = [];
    $keys = [];

    Http::fake(function (Request $request) use (&$attempts, &$bodies, &$keys) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $attempts++;
        $bodies[] = $request->body();
        $keys[] = $request->header('Idempotency-Key')[0];

        return $attempts === 1
            ? Http::response([
                'error' => [
                    'code' => 'file_changed',
                    'message' => 'The file changed since you read it.',
                    'details' => [
                        'ref' => 'main',
                        'paths' => [['path' => 'notes/pipe.txt', 'expected_oid' => '', 'changed_by' => [
                            'commit_oid' => str_repeat('9', 40),
                            'token_id' => '01JCH4',
                            'actor' => 'agent',
                            'at' => '2026-11-14T10:00:00.000Z',
                        ]]],
                    ],
                    'request_id' => '01JCH9',
                ],
            ], 409)
            : Http::response(fileWriteResponse('notes/pipe.txt', size: 17));
    });

    streamStore()->put('notes/pipe.txt', $reader);

    expect($attempts)->toBe(2)
        ->and($bodies)->toBe(['bytes from a pipe', 'bytes from a pipe'])
        // A last-write-wins retry is a different request: it names a new
        // version, so it gets a fresh key (api.md 1.5, 3.4.4).
        ->and($keys[0])->not->toBe($keys[1]);
});

it('waits out 429 and retries the same request with the same idempotency key', function () {
    $attempts = 0;
    $keys = [];

    Http::fake(function (Request $request) use (&$attempts, &$keys) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $attempts++;
        $keys[] = $request->header('Idempotency-Key')[0];

        if ($attempts <= 2) {
            return Http::response([
                'error' => [
                    'code' => 'uploads_busy',
                    'message' => 'Upload capacity is busy; retry after the delay.',
                    'details' => ['scope' => 'repo', 'limit_bytes' => 1024, 'in_flight_bytes' => 1024, 'retry_after' => 1],
                    'request_id' => '01JCH9',
                ],
            ], 429, ['Retry-After' => '1']);
        }

        return Http::response(fileWriteResponse('notes/plan.md'));
    });

    streamStore()->put('notes/plan.md', 'hello');

    expect($attempts)->toBe(3)
        ->and(array_unique($keys))->toHaveCount(1);

    Sleep::assertSleptTimes(2);
});

it('gives up after a bounded number of 429 retries with UploadsBusy', function () {
    $attempts = 0;

    Http::fake(function (Request $request) use (&$attempts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $attempts++;

        return Http::response([
            'error' => [
                'code' => 'uploads_busy',
                'message' => 'Upload capacity is busy; retry after the delay.',
                'details' => ['scope' => 'server', 'limit_bytes' => 1024, 'in_flight_bytes' => 1024, 'retry_after' => 2],
                'request_id' => '01JCH9',
            ],
        ], 429, ['Retry-After' => '2']);
    });

    try {
        streamStore()->put('notes/plan.md', 'hello');

        $this->fail('A busy server accepted the write.');
    } catch (UploadsBusy $e) {
        expect($e->errorCode)->toBe('uploads_busy')
            ->and($e->scope)->toBe('server')
            ->and($e->retryAfter)->toBe(2)
            ->and($attempts)->toBe(4);
    }

    Sleep::assertSleptTimes(3);
});

it('never retries a 413', function () {
    $attempts = 0;

    Http::fake(function (Request $request) use (&$attempts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        $attempts++;

        return Http::response([
            'error' => [
                'code' => 'payload_too_large',
                'message' => 'The file is over the limit.',
                'details' => ['limit_bytes' => 268435456, 'actual_bytes' => 999],
                'request_id' => '01JCH9',
            ],
        ], 413);
    });

    expect(fn () => streamStore()->put('notes/big.bin', 'hello'))
        ->toThrow(FileTooLarge::class);

    expect($attempts)->toBe(1);
    Sleep::assertSleptTimes(0);
});

it('stores an uploaded file as a stream without the client closing it', function () {
    $local = tempnam(sys_get_temp_dir(), 'source-');
    $size = 1024 * 1024 + 7;
    file_put_contents($local, str_repeat('x', $size));

    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404);
        }

        return Http::response(fileWriteResponse('uploads/report.bin', size: 1024 * 1024 + 7));
    });

    $disk = Source::disk([
        'driver' => 'source',
        'url' => 'https://source.test',
        'token' => 'asm_tok_test',
        'namespace' => 'acme',
        'repo' => 'workspace',
    ]);

    $stored = $disk->putFileAs('uploads', new File($local), 'report.bin');

    expect($stored)->toBe('uploads/report.bin');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->hasHeader('Content-Length', (string) $size));

    // putFileAs closes the stream it opened; the transport must not have
    // closed it first.
    expect(is_file($local))->toBeTrue();

    unlink($local);
});

it('sets the expected version on a strict write and never retries it', function () {
    $puts = [];

    Http::fake(function (Request $request) use (&$puts) {
        if ($request->method() === 'HEAD') {
            return headWith(str_repeat('7', 40));
        }

        $puts[] = $request;

        return Http::response([
            'error' => [
                'code' => 'file_changed',
                'message' => 'The file changed since you read it.',
                'details' => [
                    'ref' => 'main',
                    'paths' => [['path' => 'notes/plan.md', 'expected_oid' => str_repeat('7', 40), 'changed_by' => [
                        'commit_oid' => str_repeat('9', 40),
                        'token_id' => '01JCH4',
                        'actor' => 'agent',
                        'at' => '2026-11-14T10:00:00.000Z',
                    ]]],
                ],
                'request_id' => '01JCH9',
            ],
        ], 409);
    });

    expect(fn () => streamStore()->putStrict('notes/plan.md', 'mine', str_repeat('7', 40)))
        ->toThrow(FileChanged::class);

    expect($puts)->toHaveCount(1)
        ->and($puts[0]->hasHeader('If-Match', '"'.str_repeat('7', 40).'"'))->toBeTrue();
});
