<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Source\Exceptions\Conflict;
use Source\Exceptions\NotFound;
use Source\Exceptions\Unauthorized;
use Source\Operator\HttpOperatorClient;
use Source\Source;
use Source\Testing\FakeClient;
use Source\Testing\FakeOperator;

it('reads a version by id after the file has moved on', function () {
    Source::fake();
    $disk = Storage::disk('source');

    $disk->put('uploads/report.txt', 'first');
    $first = $disk->version('uploads/report.txt');
    $disk->put('uploads/report.txt', 'second');
    $disk->move('uploads/report.txt', 'archive/report.txt');

    expect(stream_get_contents($disk->object($first)))->toBe('first');
});

it('refuses an object that is not in this repo', function () {
    Source::fake();
    Storage::disk('source')->put('a.txt', 'only here');
    $version = FakeClient::oid('only here');

    expect(fn () => Storage::disk('source')->repo('elsewhere')->object($version))
        ->toThrow(NotFound::class);
});

it('refuses something that is not a version', function () {
    Source::fake();

    expect(fn () => Storage::disk('source')->object('../etc'))->toThrow(InvalidArgumentException::class);
});

it('streams an object over HTTP', function () {
    $oid = str_repeat('a', 40);
    Http::fake([
        "source.test/v1/namespaces/acme/repos/workspace/objects/{$oid}" => Http::response('blob bytes', 200),
    ]);

    $stream = Storage::disk('source')->object($oid);

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe('blob bytes');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer asm_tok_test'));
});

it('creates namespaces and repos idempotently over HTTP', function () {
    Http::fake([
        'source.test/v1/namespaces' => Http::sequence()
            ->push(['namespace' => ['name' => 'uno']], 201)
            ->push(['error' => ['code' => 'conflict', 'message' => 'exists']], 409),
        'source.test/v1/namespaces/uno/repos' => Http::response(['repo' => ['name' => 'r1']], 201),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'asm_op_key']);

    expect($operator)->toBeInstanceOf(HttpOperatorClient::class)
        ->and($operator->ensureNamespace('uno'))->toBeTrue()
        ->and($operator->ensureNamespace('uno'))->toBeFalse()
        ->and($operator->ensureRepo('uno', 'r1'))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://source.test/v1/namespaces/uno/repos'
        && $request['name'] === 'r1'
        && $request['default_branch'] === 'main'
        && $request->hasHeader('Authorization', 'Bearer asm_op_key'));
});

it('sends repository labels on create over HTTP', function () {
    Http::fake([
        'source.test/v1/namespaces/uno/repos' => Http::response(['repo' => ['name' => 'r1']], 201),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k']);
    $operator->ensureRepo('uno', 'r1', labels: ['workspace' => 'w1', 'user' => 'u1']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://source.test/v1/namespaces/uno/repos'
        && $request['labels'] === ['workspace' => 'w1', 'user' => 'u1']);
});

it('omits an empty labels object on create over HTTP', function () {
    Http::fake([
        'source.test/v1/namespaces/uno/repos' => Http::response(['repo' => ['name' => 'r1']], 201),
    ]);

    Source::operator(['url' => 'https://source.test', 'operator_key' => 'k'])
        ->ensureRepo('uno', 'r1');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && ! array_key_exists('labels', $request->data()));
});

it('deletes a namespace over HTTP and treats a missing one as done', function () {
    Http::fake([
        'source.test/v1/namespaces/uno*' => Http::sequence()
            ->push('', 202)
            ->push(['error' => ['code' => 'not_found', 'message' => 'gone']], 404),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k']);
    $operator->deleteNamespace('uno', force: true);
    $operator->deleteNamespace('uno', force: true);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://source.test/v1/namespaces/uno?force=true');
    Http::assertSentCount(2);
});

it('surfaces a non-empty namespace from delete without force over HTTP', function () {
    Http::fake([
        'source.test/v1/namespaces/uno*' => Http::response(
            ['error' => ['code' => 'namespace_not_empty', 'message' => 'still has repos']],
            409,
        ),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k']);

    expect(fn () => $operator->deleteNamespace('uno'))->toThrow(Conflict::class);
});

it('mints a repo token over HTTP', function () {
    Http::fake([
        'source.test/v1/namespaces/uno/repos/r1/tokens' => Http::response(['token' => [
            'id' => '01JTOKEN', 'kind' => 'repo', 'scope' => 'write', 'repo' => '01JREPO',
            'refs' => ['refs/heads/main'], 'expires_at' => '2026-11-14T13:00:00.000Z', 'token' => 'asm_tok_secret',
        ]], 201),
    ]);

    $token = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k'])
        ->mintToken('uno', 'r1', 'write', ['main'], 1800);

    expect($token->id)->toBe('01JTOKEN')
        ->and($token->token)->toBe('asm_tok_secret')
        ->and($token->repo)->toBe('uno/r1')
        ->and($token->expiresAt?->toIso8601ZuluString())->toBe('2026-11-14T13:00:00Z');

    Http::assertSent(fn (Request $request) => $request['scope'] === 'write'
        && $request['refs'] === ['main'] && $request['ttl'] === 1800);
});

it('surfaces a refused operator key', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'no']], 401)]);

    expect(fn () => Source::operator(['url' => 'https://source.test', 'operator_key' => 'bad'])->ensureNamespace('uno'))
        ->toThrow(Unauthorized::class);
});

it('treats a missing repo or token as already gone', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'gone']], 404)]);
    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k']);

    $operator->deleteRepo('uno', 'r1');
    $operator->revokeToken('01JTOKEN');

    Http::assertSentCount(2);
});

it('needs a url and an operator key', function () {
    expect(fn () => Source::operator(['url' => 'https://source.test']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Source::operator(['operator_key' => 'k']))->toThrow(InvalidArgumentException::class);
});

it('fakes the operator alongside the disk', function () {
    Source::fake();
    $operator = Source::operator();

    expect($operator)->toBeInstanceOf(FakeOperator::class)
        ->and($operator->ensureRepo('uno', 'r1', labels: ['workspace' => 'w1']))->toBeTrue()
        ->and($operator->ensureRepo('uno', 'r1'))->toBeFalse()
        ->and(Source::fakeOperator())->toBe($operator)
        ->and(Source::fakeOperator()->repoLabels('uno', 'r1'))->toBe(['workspace' => 'w1']);

    $token = $operator->mintToken('uno', 'r1');
    Source::fakeOperator()->assertRepoCreated('uno', 'r1');

    expect(Source::fakeOperator()->tokens('uno/r1'))->toHaveCount(1)
        ->and($token->refs)->toBe(['main']);

    // The server refuses a non-empty namespace without force; the fake does too.
    expect(fn () => $operator->deleteNamespace('uno'))->toThrow(Conflict::class)
        ->and(Source::fakeOperator()->hasRepo('uno', 'r1'))->toBeTrue();

    $operator->deleteNamespace('uno', force: true);
    expect(Source::fakeOperator()->hasNamespace('uno'))->toBeFalse()
        ->and(Source::fakeOperator()->hasRepo('uno', 'r1'))->toBeFalse();

    // A namespace that is already gone is not an error.
    $operator->deleteNamespace('uno', force: true);

    Source::restore();
    Source::fake();

    expect(Source::fakeOperator()->repos())->toBe([]);
});
