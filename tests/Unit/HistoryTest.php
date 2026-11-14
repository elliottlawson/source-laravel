<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Source\Exceptions\CannotRevert;
use Source\Exceptions\MergeConflict;
use Source\Exceptions\ValidationFailed;
use Source\Http\HttpClient;
use Source\SourceStore;
use Source\Support\Trailers;

function storedCommit(string $oid, string $message, string $at = '2026-11-11T21:00:00.000Z'): array
{
    return [
        'oid' => $oid,
        'parents' => [str_repeat('0', 40)],
        'message' => $message,
        'author' => ['name' => 'Elliott', 'email' => 'elliott@example.com'],
        'committer' => ['name' => 'Elliott', 'email' => 'elliott@example.com'],
        'created_at' => $at,
    ];
}

function historyStore(): SourceStore
{
    return new SourceStore(
        new HttpClient(['url' => 'https://source.test', 'token' => 'asm_tok_test', 'timeout' => 5]),
        'acme',
        'workspace',
        'main',
    );
}

it('reads trailers out of a commit message', function () {
    expect(Trailers::parse("Draft the plan\n\nBody text.\n\nrun: 01JRUN\nconversation: 01JCONV"))
        ->toBe([
            'message' => "Draft the plan\n\nBody text.",
            'meta' => ['run' => '01JRUN', 'conversation' => '01JCONV'],
        ])
        ->and(Trailers::parse('Just a subject'))->toBe(['message' => 'Just a subject', 'meta' => []])
        // A last paragraph that is not all trailers is part of the message.
        ->and(Trailers::parse("Subject\n\nNote: this is prose, not metadata\nand more prose"))
        ->toBe([
            'message' => "Subject\n\nNote: this is prose, not metadata\nand more prose",
            'meta' => [],
        ])
        // A single-paragraph message is never all trailers.
        ->and(Trailers::parse('run: 01JRUN'))->toBe(['message' => 'run: 01JRUN', 'meta' => []]);
});

it('pages through the whole-repo log lazily, newest page first', function () {
    $urls = [];

    Http::fake(function (Request $request) use (&$urls) {
        $urls[] = $request->url();

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (! isset($query['cursor'])) {
            return Http::response([
                'commits' => [
                    storedCommit(str_repeat('a', 40), 'Newest'),
                    storedCommit(str_repeat('b', 40), 'Middle'),
                ],
                'next_cursor' => 'page-two',
            ]);
        }

        return Http::response([
            'commits' => [storedCommit(str_repeat('c', 40), 'Oldest')],
            'next_cursor' => null,
        ]);
    });

    $history = historyStore()->history();

    expect($history)->toBeInstanceOf(LazyCollection::class)
        ->and($urls)->toBe([]);

    // One page is enough for the first commit; nothing more is fetched.
    expect($history->first()->message)->toBe('Newest')
        ->and($urls)->toHaveCount(1)
        ->and($urls[0])->toContain('limit=200')
        ->and($urls[0])->toContain('ref=main');

    expect($history->map(fn ($commit) => $commit->message)->all())
        ->toBe(['Newest', 'Middle', 'Oldest'])
        ->and($urls)->toHaveCount(3)
        ->and($urls[2])->toContain('cursor=page-two');
});

it('filters history by path', function () {
    Http::fake(['*' => Http::response(['commits' => [], 'next_cursor' => null])]);

    expect(historyStore()->history('notes/plan.md')->all())->toBe([]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'path=notes%2Fplan.md'));
});

it('treats an unborn branch as empty history', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'code' => 'not_found',
            'message' => 'The branch has no commits yet.',
            'details' => ['resource' => 'ref'],
            'request_id' => '01JCH8',
        ],
    ], 409)]);

    expect(historyStore()->history()->all())->toBe([]);
});

it('resolves a date to the last commit at or before it', function () {
    Http::fake(['*' => Http::response([
        'commits' => [
            storedCommit(str_repeat('a', 40), 'November 2', '2026-11-02T10:00:00.000Z'),
            storedCommit(str_repeat('b', 40), 'November 1', '2026-11-01T10:00:00.000Z'),
            storedCommit(str_repeat('c', 40), 'October 31', '2026-10-31T10:00:00.000Z'),
        ],
        'next_cursor' => null,
    ])]);

    $store = historyStore();

    expect($store->at('2026-11-01 12:00:00')->ref())->toBe(str_repeat('b', 40))
        ->and($store->at(CarbonImmutable::parse('2026-10-31 12:00:00'))->ref())->toBe(str_repeat('c', 40))
        ->and($store->at('2026-12-01')->ref())->toBe(str_repeat('a', 40))
        ->and($store->at(str_repeat('d', 40))->isReadOnly())->toBeTrue();
});

it('refuses a blob version as a point in history', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'code' => 'not_found',
            'message' => 'Not found.',
            'details' => ['resource' => 'object'],
            'request_id' => '01JCH8',
        ],
    ], 404)]);

    expect(fn () => historyStore()->at(str_repeat('e', 40)))
        ->toThrow(InvalidArgumentException::class, 'not a point in history');
});

it('diffs a directory path and a unified diff', function () {
    Http::fake([
        '*/diff?*format=structured*' => Http::response([
            'from' => str_repeat('a', 40),
            'to' => str_repeat('b', 40),
            'files' => [[
                'path' => 'notes/plan.md',
                'status' => 'modified',
                'binary' => false,
                'additions' => 1,
                'deletions' => 1,
                'hunks' => [[
                    'old_start' => 1,
                    'old_lines' => 1,
                    'new_start' => 1,
                    'new_lines' => 1,
                    'lines' => ['-v1', '+v2'],
                ]],
            ]],
        ]),
        '*/diff?*format=unified*' => Http::response("diff --git a/notes/plan.md b/notes/plan.md\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]),
    ]);

    $diff = historyStore()->diff('main', str_repeat('b', 40), 'notes');

    expect($diff->paths())->toBe(['notes/plan.md'])
        ->and($diff->file('notes/plan.md')->status)->toBe('modified')
        ->and($diff->file('notes/plan.md')->hunks[0]->lines)->toBe(['-v1', '+v2'])
        ->and(historyStore()->diffText('main', str_repeat('b', 40), 'notes'))
        ->toContain('diff --git a/notes/plan.md');
});

it('refuses to revert a root commit or a merge commit', function () {
    $disk = fakeDisk();

    $disk->put('a.md', "a\n");
    $root = $disk->history()->last()->oid;

    expect(fn () => $disk->revert($root))->toThrow(CannotRevert::class, 'root commit');

    // A real merge commit (two parents) on main, then a revert of it: main
    // and the branch both move, on different paths, so the merge is clean but
    // not a fast-forward.
    $disk->put('a.md', "a2\n");
    $draft = $disk->branch('draft');
    $draft->put('b.md', "b\n");
    $disk->put('c.md', "c\n");
    $merge = $draft->mergeInto('main');

    expect($merge->fastForward)->toBeFalse();

    try {
        $disk->revert((string) $merge->mergeOid);

        $this->fail('A merge commit was reverted.');
    } catch (CannotRevert $e) {
        expect($e->getMessage())->toContain('merge commit');
    }
});

it('refuses a partial merge resolution on the fake', function () {
    $disk = fakeDisk();

    $disk->put('conflict.md', "base\n");

    $draft = $disk->branch('draft');
    $draft->put('conflict.md', "theirs\n");
    $disk->put('conflict.md', "ours\n");

    try {
        $draft->mergeInto('main');

        $this->fail('A conflicting merge was reported as clean.');
    } catch (MergeConflict $e) {
        expect(fn () => $e->resolve(['some-other-path.md' => 'ours']))
            ->toThrow(ValidationFailed::class, 'Not a conflicted path');
    }
});
