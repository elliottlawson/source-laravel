<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Source\Author;
use Source\Change;
use Source\Exceptions\Conflict;
use Source\Exceptions\FileChanged;
use Source\Exceptions\FileTooLarge;
use Source\Exceptions\Forbidden;
use Source\Exceptions\MergeConflict;
use Source\Exceptions\NotFound;
use Source\Exceptions\RateLimited;
use Source\Exceptions\Unauthorized;
use Source\Exceptions\ValidationFailed;
use Source\Http\HttpClient;

function httpClient(array $config = []): HttpClient
{
    return new HttpClient(array_replace([
        'url' => 'https://source.test',
        'token' => 'asm_tok_test',
        'timeout' => 5,
    ], $config));
}

function errorBody(string $code, array $details = [], string $message = 'It failed.'): array
{
    return [
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
            'request_id' => '01JCH8REQUESTID',
        ],
    ];
}

it('sends a bearer token and builds the batch commit URL', function () {
    Http::fake([
        '*' => Http::response([
            'commit' => ['oid' => str_repeat('a', 40), 'base_oid' => str_repeat('b', 40)],
            'ref' => ['name' => 'main', 'target_oid' => str_repeat('a', 40)],
            'files' => [['path' => 'notes/plan.md', 'oid' => str_repeat('c', 40), 'size' => 5, 'mode' => '100644']],
        ]),
    ]);

    $result = httpClient()->sendChanges(
        'acme',
        'workspace',
        'main',
        'Update notes/plan.md',
        new Author('Elliott', 'elliott@example.com'),
        [Change::put('notes/plan.md', '', "# Hi\n")],
    );

    expect($result->commitOid)->toBe(str_repeat('a', 40))
        ->and($result->version('notes/plan.md'))->toBe(str_repeat('c', 40));

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/commits'
            && $request->hasHeader('Authorization', 'Bearer asm_tok_test')
            && $request->hasHeader('Accept', 'application/json')
            && $request['ref'] === 'main'
            && $request['message'] === 'Update notes/plan.md'
            && $request['author'] === ['name' => 'Elliott', 'email' => 'elliott@example.com']
            && $request['changes'] === [[
                'path' => 'notes/plan.md',
                'expected_oid' => '',
                'content' => "# Hi\n",
            ]];
    });
});

it('percent-encodes the ref and each path segment', function () {
    Http::fake(['*' => Http::response('', 200, [
        'X-Object-Oid' => str_repeat('a', 40),
        'X-Object-Size' => '5',
        'Last-Modified' => 'Sat, 14 Nov 2026 10:00:00 GMT',
    ])]);

    $stat = httpClient()->head('acme', 'workspace', 'sessions/01JABC', 'notes/plan v2.md');

    expect($stat->version)->toBe(str_repeat('a', 40));

    Http::assertSent(function (Request $request) {
        return $request->method() === 'HEAD'
            && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/files/sessions%2F01JABC/notes/plan%20v2.md';
    });
});

it('lists a tree with path and recursive query parameters', function () {
    Http::fake(['*' => Http::response([
        'tree' => [
            'ref' => 'main',
            'oid' => str_repeat('d', 40),
            'path' => 'notes',
            'entries' => [
                ['name' => 'plan.md', 'path' => 'notes/plan.md', 'type' => 'file', 'mode' => '100644', 'oid' => str_repeat('a', 40), 'size' => 12],
                ['name' => 'sub', 'path' => 'notes/sub', 'type' => 'dir', 'mode' => '040000', 'oid' => str_repeat('e', 40), 'size' => 0],
            ],
        ],
    ])]);

    $entries = httpClient()->listTree('acme', 'workspace', 'main', 'notes', true);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->path)->toBe('notes/plan.md')
        ->and($entries[0]->isFile())->toBeTrue()
        ->and($entries[1]->isDirectory())->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/tree/main?path=notes&recursive=true';
    });
});

it('maps a missing file to null on head and NotFound on get', function () {
    Http::fake(['*' => Http::response(errorBody('not_found', ['resource' => 'object']), 404)]);

    expect(httpClient()->head('acme', 'workspace', 'main', 'gone.md'))->toBeNull();

    try {
        httpClient()->get('acme', 'workspace', 'main', 'gone.md');
        $this->fail('get() accepted a missing file.');
    } catch (NotFound $e) {
        expect($e->errorCode)->toBe('not_found')
            ->and($e->requestId)->toBe('01JCH8REQUESTID');
    }
});

it('maps every API error onto a typed exception', function (int $status, string $code, string $class) {
    Http::fake(['*' => Http::response(errorBody($code, ['reason' => 'test']), $status)]);

    expect(fn () => httpClient()->get('acme', 'workspace', 'main', 'a.md'))
        ->toThrow($class);
})->with([
    'unauthorized' => [401, 'unauthorized', Unauthorized::class],
    'token expired' => [401, 'token_expired', Unauthorized::class],
    'forbidden' => [403, 'forbidden', Forbidden::class],
    'not found' => [404, 'not_found', NotFound::class],
    'stale base' => [409, 'stale_base', Conflict::class],
    'merge conflict' => [409, 'merge_conflict', MergeConflict::class],
    'payload too large' => [413, 'payload_too_large', FileTooLarge::class],
    'validation failed' => [422, 'validation_failed', ValidationFailed::class],
    'rate limited' => [429, 'rate_limited', RateLimited::class],
]);

it('reads retryAfter from a rate-limited response', function () {
    Http::fake(['*' => Http::response(errorBody('rate_limited', ['retry_after' => 7]), 429)]);

    try {
        httpClient()->get('acme', 'workspace', 'main', 'a.md');
        $this->fail('rate_limited was accepted.');
    } catch (RateLimited $e) {
        expect($e->retryAfter)->toBe(7);
    }
});

it('reads the size limit from a payload-too-large response', function () {
    Http::fake(['*' => Http::response(errorBody('payload_too_large', ['limit_bytes' => 1024, 'actual_bytes' => 2048]), 413)]);

    try {
        httpClient()->get('acme', 'workspace', 'main', 'a.md');
        $this->fail('payload_too_large was accepted.');
    } catch (FileTooLarge $e) {
        expect($e->limitBytes)->toBe(1024)
            ->and($e->actualBytes)->toBe(2048);
    }
});

it('builds FileChanged from the 409 body without leaking contents', function () {
    $path = 'notes/plan.md';
    $commit = str_repeat('9', 40);

    Http::fake(['*' => Http::response([
        'error' => [
            'code' => 'file_changed',
            'message' => '1 file changed since you read it; read it again before writing.',
            'details' => [
                'ref' => 'main',
                'paths' => [[
                    'path' => $path,
                    'expected_oid' => str_repeat('a', 40),
                    'changed_by' => [
                        'commit_oid' => $commit,
                        'token_id' => '01JCH4TOKEN',
                        'actor' => 'agent',
                        'at' => '2026-11-11T21:01:00.000Z',
                    ],
                ]],
            ],
            'request_id' => '01JCH9',
        ],
    ], 409)]);

    try {
        httpClient()->sendChanges('acme', 'workspace', 'main', 'Update', null, [Change::put($path, str_repeat('a', 40), 'mine')]);
        $this->fail('A stale write was accepted.');
    } catch (FileChanged $e) {
        expect($e->paths)->toBe([$path])
            ->and($e->refName)->toBe('main')
            ->and($e->changedBy($path)->commitOid)->toBe($commit)
            ->and($e->changedBy($path)->tokenId)->toBe('01JCH4TOKEN')
            ->and($e->changedBy($path)->actor)->toBe('agent')
            ->and($e->changedBy($path)->at)->toBe('2026-11-11T21:01:00.000Z')
            ->and($e->changedBy('other.md'))->toBeNull();
    }
});

it('sends content_base64 for non-UTF-8 bytes', function () {
    Http::fake(['*' => Http::response(['commit' => ['oid' => str_repeat('a', 40)], 'files' => []])]);

    $binary = "\x00\x01\xff\xfe";

    httpClient()->sendChanges('acme', 'workspace', 'main', 'Binary', null, [Change::put('image.bin', '', $binary)]);

    Http::assertSent(function (Request $request) use ($binary) {
        $change = $request['changes'][0];

        return ! isset($change['content'])
            && $change['content_base64'] === base64_encode($binary);
    });
});

it('creates and finds a session by ref', function () {
    Http::fake([
        '*/sessions?*' => Http::response([
            'sessions' => [
                ['id' => '01JSESSION1', 'repo' => 'acme/workspace', 'ref' => 'somebody-else', 'state' => 'open', 'base_oid' => str_repeat('a', 40), 'tip' => str_repeat('a', 40)],
                ['id' => '01JSESSION2', 'repo' => 'acme/workspace', 'ref' => 'draft', 'state' => 'open', 'base_oid' => str_repeat('a', 40), 'tip' => str_repeat('b', 40)],
            ],
            'next_cursor' => null,
        ]),
        '*/sessions' => Http::response([
            'session' => ['id' => '01JSESSION3', 'repo' => 'acme/workspace', 'ref' => 'draft', 'state' => 'open', 'base_oid' => str_repeat('a', 40), 'tip' => str_repeat('a', 40)],
            'token' => ['id' => '01JTOKEN', 'token' => 'asm_tok_session'],
        ], 201),
    ]);

    $client = httpClient();

    $found = $client->findSession('acme', 'workspace', 'draft');
    $created = $client->createSession('acme', 'workspace', 'main', 'draft');

    expect($found?->id)->toBe('01JSESSION2')
        ->and($found?->tip)->toBe(str_repeat('b', 40))
        ->and($created->id)->toBe('01JSESSION3')
        ->and($client->findSession('acme', 'workspace', 'nobody'))->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/sessions'
        && $request['from'] === 'main'
        && $request['ref'] === 'draft');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/sessions?ref=draft'));
});

it('turns a 409 merge_conflict into a MergeConflict with the payload intact', function () {
    $path = 'notes/plan.md';

    $payload = [
        'merge_id' => '01JMERGE',
        'session' => '01JSESSION',
        'target' => 'main',
        'target_oid' => str_repeat('a', 40),
        'session_oid' => str_repeat('b', 40),
        'merge_base_oid' => str_repeat('c', 40),
        'conflicts' => [[
            'path' => $path,
            'kind' => 'content',
            'base' => ['oid' => str_repeat('d', 40), 'mode' => '100644'],
            'ours' => ['oid' => str_repeat('e', 40), 'mode' => '100644'],
            'theirs' => ['oid' => str_repeat('f', 40), 'mode' => '100644'],
            'hunks' => [],
        ]],
        'resolution' => [
            'strategy' => 'per_path',
            'accepts' => ['ours', 'theirs', 'content', 'delete'],
            'default_side_of_ours' => 'target',
        ],
    ];

    Http::fake(['*' => Http::response(errorBody('merge_conflict', $payload, 'Merge produced 1 conflict.'), 409)]);

    try {
        httpClient()->merge('acme', 'workspace', '01JSESSION', 'main');

        $this->fail('A conflict was accepted as a clean merge.');
    } catch (MergeConflict $e) {
        expect($e->errorCode)->toBe('merge_conflict')
            ->and($e->paths())->toBe([$path])
            ->and($e->mergeId())->toBe('01JMERGE')
            ->and($e->sessionId())->toBe('01JSESSION')
            ->and($e->target())->toBe('main')
            ->and($e->details)->toBe($payload);
    }
});

it('resolves every conflicted path in one call', function () {
    Http::fake(['*' => Http::response(['merge' => ['id' => '01JMERGE', 'state' => 'resolved', 'target' => 'main']])]);

    $client = httpClient();

    $merge = $client->resolveMerge('acme', 'workspace', '01JSESSION', '01JMERGE', [
        'ours.md' => 'ours',
        'theirs.md' => 'theirs',
        'gone.md' => 'delete',
        'merged.md' => "hand written\n",
        'binary.bin' => "\x00\xff",
    ]);

    expect($merge->state)->toBe('resolved');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/sessions/01JSESSION/merges/01JMERGE/resolve'
            && $request['paths'] === [
                ['path' => 'ours.md', 'resolution' => 'ours'],
                ['path' => 'theirs.md', 'resolution' => 'theirs'],
                ['path' => 'gone.md', 'resolution' => 'delete'],
                ['path' => 'merged.md', 'resolution' => 'content', 'content' => "hand written\n"],
                ['path' => 'binary.bin', 'resolution' => 'content', 'content_base64' => base64_encode("\x00\xff")],
            ];
    });
});

it('aborts a merge with its merge id', function () {
    Http::fake(['*' => Http::response(['session' => ['id' => '01JSESSION', 'ref' => 'draft', 'state' => 'open']])]);

    $session = httpClient()->abortMerge('acme', 'workspace', '01JSESSION', '01JMERGE');

    expect($session->state)->toBe('open');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://source.test/v1/namespaces/acme/repos/workspace/sessions/01JSESSION/abort'
        && $request['merge_id'] === '01JMERGE');
});

it('gives uploads their own, longer timeout', function () {
    $client = new HttpClient(['url' => 'https://source.test']);

    expect($client->timeoutFor(upload: false))->toBe(30.0)
        ->and($client->timeoutFor(upload: true))->toBe(600.0);

    $configured = new HttpClient(['url' => 'https://source.test', 'timeout' => 5, 'upload_timeout' => 90]);

    expect($configured->timeoutFor(upload: false))->toBe(5.0)
        ->and($configured->timeoutFor(upload: true))->toBe(90.0);
});
