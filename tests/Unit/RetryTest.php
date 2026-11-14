<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Source\Author;
use Source\Exceptions\FileChanged;
use Source\Http\HttpClient;
use Source\PendingGuard;
use Source\SourceStore;

function retryStore(): SourceStore
{
    return new SourceStore(
        new HttpClient(['url' => 'https://source.test', 'token' => 'asm_tok_test']),
        'acme',
        'workspace',
        'main',
        new Author('Elliott', 'elliott@example.com'),
    );
}

function headResponse(string $oid = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
{
    return Http::response('', 200, [
        'X-Object-Oid' => $oid,
        'X-Object-Size' => '5',
        'X-Object-Mode' => '100644',
        'Last-Modified' => 'Sat, 14 Nov 2026 10:00:00 GMT',
    ]);
}

function changedResponse(array $paths)
{
    return Http::response([
        'error' => [
            'code' => 'file_changed',
            'message' => 'Files changed since you read them.',
            'details' => [
                'ref' => 'main',
                'paths' => array_map(fn (string $path) => [
                    'path' => $path,
                    'expected_oid' => str_repeat('a', 40),
                    'changed_by' => [
                        'commit_oid' => str_repeat('9', 40),
                        'token_id' => '01JCH4',
                        'actor' => 'agent',
                        'at' => '2026-11-14T10:00:00.000Z',
                    ],
                ], $paths),
            ],
            'request_id' => '01JCH9',
        ],
    ], 409);
}

function okCommitResponse()
{
    return Http::response([
        'commit' => ['oid' => str_repeat('b', 40), 'base_oid' => str_repeat('c', 40)],
        'ref' => ['name' => 'main', 'target_oid' => str_repeat('b', 40)],
        'files' => [['path' => 'a.md', 'oid' => str_repeat('d', 40), 'size' => 5, 'mode' => '100644']],
    ]);
}

it('retries a plain put and gives up after three attempts', function () {
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse();
        }

        $posts++;

        return changedResponse(['a.md']);
    });

    try {
        retryStore()->put('a.md', 'mine');
        $this->fail('The put was accepted.');
    } catch (FileChanged $e) {
        expect($e->paths)->toBe(['a.md'])
            ->and($posts)->toBe(3);
    }

    expect($posts)->toBe(3);
});

it('retries a plain put and succeeds on the second attempt', function () {
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse();
        }

        return ++$posts === 1 ? changedResponse(['a.md']) : okCommitResponse();
    });

    $result = retryStore()->put('a.md', 'mine');

    expect($posts)->toBe(2)
        ->and($result->commitOid)->toBe(str_repeat('b', 40));
});

it('never retries a strict write', function () {
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse();
        }

        $posts++;

        return changedResponse(['a.md']);
    });

    expect(fn () => PendingGuard::eager(retryStore(), str_repeat('a', 40))->put('a.md', 'mine'))
        ->toThrow(FileChanged::class);

    expect($posts)->toBe(1);
});

it('retries a batch only when every mismatched path is a plain change', function () {
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse();
        }

        return ++$posts === 1 ? changedResponse(['b.md']) : okCommitResponse();
    });

    retryStore()->commit('Two files', function ($files) {
        $files->put('a.md', 'a');
        $files->put('b.md', 'b');
    });

    expect($posts)->toBe(2);
});

it('refuses a batch without retrying when a strict change mismatched', function () {
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse();
        }

        $posts++;

        return changedResponse(['a.md']);
    });

    expect(fn () => retryStore()->commit('Two files', function ($files) {
        $files->ifUnchanged(str_repeat('a', 40))->put('a.md', 'a');
        $files->put('b.md', 'b');
    }))->toThrow(FileChanged::class);

    expect($posts)->toBe(1);
});

it('writes a move as one commit with the destination create and the source delete', function () {
    $posts = [];

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 404, []);
        }

        if ($request->method() === 'GET') {
            return Http::response('moving', 200, [
                'X-Object-Oid' => str_repeat('e', 40),
                'X-Object-Size' => '6',
            ]);
        }

        $posts[] = $request->data();

        return okCommitResponse();
    });

    retryStore()->move('a.md', 'b.md');

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['changes'])->toBe([
            ['path' => 'b.md', 'expected_oid' => '', 'content' => 'moving'],
            ['path' => 'a.md', 'expected_oid' => str_repeat('e', 40), 'delete' => true],
        ]);
});

it('writes a delete as one commit naming the version', function () {
    $posts = [];

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse(str_repeat('f', 40));
        }

        $posts[] = $request->data();

        return okCommitResponse();
    });

    retryStore()->delete('a.md');

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['changes'][0])->toBe([
            'path' => 'a.md',
            'expected_oid' => str_repeat('f', 40),
            'delete' => true,
        ]);
});

it('turns the version into expected_oid for a plain put', function () {
    $posts = [];

    Http::fake(function (Request $request) use (&$posts) {
        if ($request->method() === 'HEAD') {
            return headResponse(str_repeat('1', 40));
        }

        $posts[] = $request->data();

        return okCommitResponse();
    });

    retryStore()->put('a.md', 'mine');

    expect($posts[0]['changes'][0]['expected_oid'])->toBe(str_repeat('1', 40));
});
