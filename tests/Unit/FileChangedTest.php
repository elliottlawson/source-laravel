<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Source\ChangedBy;
use Source\Exceptions\FileChanged;

function changedDetails(): array
{
    return [
        'ref' => 'refs/heads/main',
        'paths' => [
            [
                'path' => 'notes/plan.md',
                'expected_oid' => str_repeat('a', 40),
                'changed_by' => [
                    'commit_oid' => str_repeat('9', 40),
                    'token_id' => '01JCH4TOKEN',
                    'actor' => 'agent',
                    'at' => '2026-11-14T10:00:00.000Z',
                ],
            ],
        ],
    ];
}

it('carries paths and who changed each one', function () {
    $e = FileChanged::fromDetails(changedDetails(), 'It moved.', '01JCH9');

    expect($e->paths)->toBe(['notes/plan.md'])
        ->and($e->refName)->toBe('refs/heads/main')
        ->and($e->errorCode)->toBe('file_changed')
        ->and($e->requestId)->toBe('01JCH9')
        ->and($e->changedBy('notes/plan.md'))->toBeInstanceOf(ChangedBy::class)
        ->and($e->changedBy('notes/plan.md')->commitOid)->toBe(str_repeat('9', 40))
        ->and($e->changedBy('notes/plan.md')->tokenId)->toBe('01JCH4TOKEN')
        ->and($e->changedBy('notes/plan.md')->actor)->toBe('agent')
        ->and($e->changedBy('notes/plan.md')->at)->toBe('2026-11-14T10:00:00.000Z')
        ->and($e->changedBy('nope.md'))->toBeNull();
});

it('renders as a 409 JSON response', function () {
    $e = FileChanged::fromDetails(changedDetails(), 'It moved.', '01JCH9');

    $response = $e->render(Request::create('/notes/plan.md', 'PUT'));

    expect($response->getStatusCode())->toBe(409);

    $body = json_decode($response->getContent(), true);

    expect($body['error']['code'])->toBe('file_changed')
        ->and($body['error']['message'])->toBe('It moved.')
        ->and($body['error']['request_id'])->toBe('01JCH9')
        ->and($body['error']['details']['ref'])->toBe('refs/heads/main')
        ->and($body['error']['details']['paths'][0]['path'])->toBe('notes/plan.md')
        ->and($body['error']['details']['paths'][0]['expected_oid'])->toBe(str_repeat('a', 40))
        ->and($body['error']['details']['paths'][0]['changed_by']['actor'])->toBe('agent')
        ->and($body['error']['details']['paths'][0])->not->toHaveKey('oid')
        ->and($body['error']['details']['paths'][0])->not->toHaveKey('version')
        ->and($body['error']['details']['paths'][0])->not->toHaveKey('contents');
});

it('describes every mismatched path in the default message', function () {
    $one = FileChanged::fromDetails(changedDetails());
    $two = FileChanged::fromDetails([
        'paths' => [
            ['path' => 'a.md', 'expected_oid' => '', 'changed_by' => []],
            ['path' => 'b.md', 'expected_oid' => '', 'changed_by' => []],
        ],
    ]);

    expect($one->getMessage())->toBe('1 file changed since you read it; read it again before writing.')
        ->and($two->getMessage())->toBe('2 files changed since you read them; read them again before writing.');
});

it('deletes strictly on a disk', function () {
    $disk = fakeDisk();

    $disk->put('a.md', "a\n");
    $version = $disk->version('a.md');

    $disk->ifUnchanged($version)->delete('a.md');

    expect($disk->fileExists('a.md'))->toBeFalse();
});
