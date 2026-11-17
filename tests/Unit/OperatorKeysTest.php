<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Source\Exceptions\Conflict;
use Source\Exceptions\Forbidden;
use Source\OperatorKey;
use Source\Source;

it('creates a namespace-scoped operator key over HTTP and returns the secret once', function () {
    Http::fake([
        'source.test/v1/operator-keys' => Http::response(['operator_key' => [
            'id' => '01KEY', 'namespace' => 'uno', 'label' => 'web',
            'prefix' => 'asm_op_abcdefghijkl', 'origin' => 'api',
            'created_at' => '2026-11-15T10:00:00.000Z', 'revoked_at' => null,
            'secret' => 'asm_op_abcdefghijkl_secret',
        ]], 201),
    ]);

    $key = Source::operator(['url' => 'https://source.test', 'operator_key' => 'asm_op_admin'])
        ->createOperatorKey('uno', 'web');

    expect($key)->toBeInstanceOf(OperatorKey::class)
        ->and($key->id)->toBe('01KEY')
        ->and($key->namespace)->toBe('uno')
        ->and($key->label)->toBe('web')
        ->and($key->origin)->toBe('api')
        ->and($key->secret)->toBe('asm_op_abcdefghijkl_secret')
        ->and($key->isRevoked())->toBeFalse()
        ->and($key->isFromConfig())->toBeFalse()
        ->and($key->createdAt?->toIso8601ZuluString())->toBe('2026-11-15T10:00:00Z');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://source.test/v1/operator-keys'
        && $request['namespace'] === 'uno'
        && $request['label'] === 'web'
        && $request->hasHeader('Authorization', 'Bearer asm_op_admin'));
});

it('omits an empty label when creating a key', function () {
    Http::fake([
        'source.test/v1/operator-keys' => Http::response(['operator_key' => [
            'id' => '01KEY', 'namespace' => 'uno', 'prefix' => 'asm_op_x', 'origin' => 'api',
        ]], 201),
    ]);

    Source::operator(['url' => 'https://source.test', 'operator_key' => 'k'])->createOperatorKey('uno');

    Http::assertSent(fn (Request $request) => ! array_key_exists('label', $request->data()));
});

it('lists operator keys without secrets and knows a config key', function () {
    Http::fake([
        'source.test/v1/operator-keys' => Http::response(['operator_keys' => [
            ['id' => '01A', 'namespace' => 'uno', 'prefix' => 'asm_op_aaa', 'origin' => 'api',
                'created_at' => '2026-11-15T10:00:00.000Z', 'revoked_at' => null],
            ['id' => '01B', 'namespace' => null, 'prefix' => 'asm_op_bbb', 'origin' => 'config',
                'created_at' => '2026-11-15T10:00:00.000Z', 'revoked_at' => null],
        ]], 200),
    ]);

    $keys = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k'])->listOperatorKeys();

    expect($keys)->toHaveCount(2)
        ->and($keys[0]->namespace)->toBe('uno')
        ->and($keys[0]->secret)->toBeNull()
        ->and($keys[1]->namespace)->toBeNull()
        ->and($keys[1]->isFromConfig())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://source.test/v1/operator-keys');
});

it('revokes an operator key and treats a missing one as gone', function () {
    Http::fake([
        'source.test/v1/operator-keys/*' => Http::sequence()
            ->push('', 204)
            ->push(['error' => ['code' => 'not_found', 'message' => 'gone']], 404),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'k']);
    $operator->revokeOperatorKey('01KEY');
    $operator->revokeOperatorKey('01GONE');

    Http::assertSentCount(2);
});

it('surfaces the refusal to revoke a config-derived key', function () {
    Http::fake([
        'source.test/v1/operator-keys/*' => Http::response(['error' => [
            'code' => 'operator_key_from_config',
            'message' => 'This operator key comes from configuration and cannot be revoked through the API.',
        ]], 409),
    ]);

    expect(fn () => Source::operator(['url' => 'https://source.test', 'operator_key' => 'k'])
        ->revokeOperatorKey('01CONFIG'))
        ->toThrow(Conflict::class);
});

it('provisions a namespace with a namespace-scoped key as a no-op when it exists', function () {
    // A scoped key cannot POST /v1/namespaces (403), so the client asks for
    // the one namespace the key can see. A 200 means it is already there.
    Http::fake([
        'source.test/v1/namespaces' => Http::response(['error' => [
            'code' => 'forbidden', 'message' => 'operator scope required',
        ]], 403),
        'source.test/v1/namespaces/uno' => Http::response(['namespace' => ['name' => 'uno']], 200),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'asm_op_scoped']);

    expect($operator->ensureNamespace('uno'))->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://source.test/v1/namespaces/uno');
});

it('refuses to provision another namespace with a namespace-scoped key', function () {
    Http::fake([
        'source.test/v1/namespaces' => Http::response(['error' => [
            'code' => 'forbidden', 'message' => 'operator scope required',
        ]], 403),
        'source.test/v1/namespaces/other' => Http::response(['error' => [
            'code' => 'not_found', 'message' => 'no such namespace',
        ]], 404),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'asm_op_scoped']);

    expect(fn () => $operator->ensureNamespace('other'))->toThrow(Forbidden::class);
});

it('creates repos and mints tokens inside the key\'s namespace over HTTP', function () {
    Http::fake([
        'source.test/v1/namespaces/uno/repos' => Http::response(['repo' => ['name' => 'r1']], 201),
        'source.test/v1/namespaces/uno/repos/r1/tokens' => Http::response(['token' => [
            'id' => '01T', 'kind' => 'repo', 'scope' => 'write', 'repo' => '01R',
            'refs' => ['refs/heads/main'], 'expires_at' => '2026-11-15T11:00:00.000Z',
            'token' => 'asm_tok_secret',
        ]], 201),
    ]);

    $operator = Source::operator(['url' => 'https://source.test', 'operator_key' => 'asm_op_scoped']);

    expect($operator->ensureRepo('uno', 'r1'))->toBeTrue();

    $token = $operator->mintToken('uno', 'r1', 'write', ['main'], 600);

    expect($token->token)->toBe('asm_tok_secret')
        ->and($token->repo)->toBe('uno/r1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://source.test/v1/namespaces/uno/repos'
        && $request->hasHeader('Authorization', 'Bearer asm_op_scoped'));
});

it('manages operator keys on the fake', function () {
    Source::fake();
    $operator = Source::operator();

    $key = $operator->createOperatorKey('uno', 'web');

    expect($key->id)->not->toBe('')
        ->and($key->namespace)->toBe('uno')
        ->and($key->secret)->toStartWith('asm_op_')
        ->and(Source::fakeOperator()->listOperatorKeys())->toHaveCount(1);

    $operator->revokeOperatorKey($key->id);

    expect(Source::fakeOperator()->operatorKey($key->id)?->isRevoked())->toBeTrue();

    // An unknown id is not an error.
    $operator->revokeOperatorKey('nope');

    Source::restore();
    Source::fake();

    expect(Source::fakeOperator()->listOperatorKeys())->toBe([]);
});
