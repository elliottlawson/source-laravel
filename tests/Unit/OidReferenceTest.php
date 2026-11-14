<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Source\Author;
use Source\Change;
use Source\Exceptions\UnknownObject;
use Source\Http\HttpClient;
use Source\SourceStore;
use Source\Support\Payload;
use Source\Uploads\OidWhenPresent;

/*
 * api.md 3.4.3: a commit names a blob the repo already holds instead of sending
 * its bytes, and answers 422 unknown_object by sending them after all.
 */

function oidStore(): SourceStore
{
    $client = new HttpClient(['url' => 'https://source.test', 'token' => 'asm_tok_test', 'timeout' => 5]);

    return new SourceStore(
        $client,
        'acme',
        'workspace',
        'main',
        new Author('Elliott', 'elliott@example.com'),
        uploader: new OidWhenPresent($client),
    );
}

function okCommit(array $paths)
{
    return Http::response([
        'commit' => ['oid' => str_repeat('b', 40), 'base_oid' => str_repeat('c', 40)],
        'ref' => ['name' => 'main', 'target_oid' => str_repeat('b', 40)],
        'files' => array_map(fn (string $path) => [
            'path' => $path,
            'oid' => Payload::blobOid($path.'-written'),
            'size' => 10,
            'mode' => '100644',
        ], $paths),
    ]);
}

it('commits a change by oid when the repo already holds the blob', function () {
    $unchanged = "same content\n";
    $changed = "new content\n";
    $oid = Payload::blobOid($unchanged);

    $bodies = [];
    $present = [];

    Http::fake(function (Request $request) use (&$bodies, &$present, $oid) {
        if ($request->method() === 'HEAD') {
            // Both files exist at the versions the driver read.
            $path = str_contains($request->url(), 'a.md') ? 'a.md' : 'b.md';

            return Http::response('', 200, [
                'X-Object-Oid' => $path === 'a.md' ? $oid : Payload::blobOid("old b\n"),
                'X-Object-Size' => '1',
                'X-Object-Mode' => '100644',
            ]);
        }

        if (str_contains($request->url(), '/objects/present')) {
            $present[] = $request->data()['oids'];

            // Only the unchanged file's blob is already there.
            return Http::response(['present' => [$oid]]);
        }

        $bodies[] = $request->data();

        return okCommit(['a.md', 'b.md']);
    });

    oidStore()->commit('Rewrite both', function ($files) use ($unchanged, $changed) {
        $files->put('a.md', $unchanged);
        $files->put('b.md', $changed);
    });

    expect($present)->toHaveCount(1)
        ->and($present[0])->toHaveCount(2)
        ->and($present[0])->toContain($oid);

    expect($bodies)->toHaveCount(1);

    $changes = $bodies[0]['changes'];

    expect($changes[0])->toBe([
        'path' => 'a.md',
        'expected_oid' => $oid,
        // No bytes: the server already has this blob.
        'oid' => $oid,
    ])->and($changes[1])->toBe([
        'path' => 'b.md',
        'expected_oid' => Payload::blobOid("old b\n"),
        'content' => $changed,
    ]);
});

it('asks for present blobs in chunks of a thousand', function () {
    $calls = 0;

    Http::fake(function (Request $request) use (&$calls) {
        if (str_contains($request->url(), '/objects/present')) {
            $calls++;

            return Http::response(['present' => []]);
        }

        return okCommit([]);
    });

    $changes = [];

    for ($i = 0; $i < 1001; $i++) {
        $changes[] = Change::put("file-{$i}.txt", '', "contents {$i}\n");
    }

    (new OidWhenPresent(new HttpClient(['url' => 'https://source.test'])))
        ->prepare('acme', 'workspace', 'main', $changes);

    expect($calls)->toBe(2);
});

it('resends the bytes once when the server answers unknown_object', function () {
    $contents = "present but not reachable\n";
    $oid = Payload::blobOid($contents);

    $commits = [];

    Http::fake(function (Request $request) use (&$commits, $oid) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'X-Object-Oid' => $oid,
                'X-Object-Size' => '1',
                'X-Object-Mode' => '100644',
            ]);
        }

        if (str_contains($request->url(), '/objects/present')) {
            // The hint says present; the write path disagrees.
            return Http::response(['present' => [$oid]]);
        }

        $commits[] = $request->data();

        if (count($commits) === 1) {
            return Http::response([
                'error' => [
                    'code' => 'unknown_object',
                    'message' => 'The repo does not hold these objects as blobs reachable from a ref; send their bytes.',
                    'details' => ['paths' => [['path' => 'a.md', 'oid' => $oid]]],
                    'request_id' => '01JCH9',
                ],
            ], 422);
        }

        return okCommit(['a.md', 'b.md']);
    });

    oidStore()->commit('Two files', function ($files) use ($contents) {
        $files->put('a.md', $contents);
        $files->put('b.md', "b\n");
    });

    expect($commits)->toHaveCount(2)
        ->and($commits[0]['changes'][0])->toHaveKey('oid')
        // The fallback sends the bytes for the refused path, once.
        ->and($commits[1]['changes'][0]['content'])->toBe($contents)
        ->and($commits[1]['changes'][0])->not->toHaveKey('oid');
});

it('surfaces unknown_object with the refused paths', function () {
    $oid = str_repeat('e', 40);

    Http::fake(function () use ($oid) {
        return Http::response([
            'error' => [
                'code' => 'unknown_object',
                'message' => 'The repo does not hold these objects.',
                'details' => ['paths' => [['path' => 'a.md', 'oid' => $oid]]],
                'request_id' => '01JCH9',
            ],
        ], 422);
    });

    try {
        (new HttpClient(['url' => 'https://source.test']))->sendChanges(
            'acme',
            'workspace',
            'main',
            'One file',
            null,
            [Change::reference('a.md', '', $oid)],
        );

        $this->fail('The reference was accepted.');
    } catch (UnknownObject $e) {
        expect($e->paths())->toBe(['a.md'])
            ->and($e->oids())->toBe(['a.md' => $oid]);
    }
});

it('does not ask present for a delete', function () {
    $presentCalls = 0;

    Http::fake(function (Request $request) use (&$presentCalls) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'X-Object-Oid' => str_repeat('1', 40),
                'X-Object-Size' => '1',
                'X-Object-Mode' => '100644',
            ]);
        }

        if (str_contains($request->url(), '/objects/present')) {
            $presentCalls++;
        }

        return okCommit(['a.md']);
    });

    oidStore()->delete('a.md');

    expect($presentCalls)->toBe(0);
});
