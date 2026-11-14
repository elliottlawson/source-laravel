<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Source\Exceptions\Forbidden;
use Source\Exceptions\NotFound;
use Source\Exceptions\Unauthorized;
use Source\Source;

/*
 * The operator calls and object reads against a live server. Opt-in with
 * SOURCE_TEST_URL and SOURCE_TEST_OPERATOR_KEY. Each run creates a throwaway
 * namespace and two repos, and deletes the namespace at the end.
 */

beforeEach(function () {
    $url = env('SOURCE_TEST_URL');
    $key = env('SOURCE_TEST_OPERATOR_KEY');

    if (! is_string($url) || $url === '' || ! is_string($key) || $key === '') {
        $this->markTestSkipped('Set SOURCE_TEST_URL and SOURCE_TEST_OPERATOR_KEY to run the operator conformance tests.');
    }

    $this->url = $url;
    $this->operator = Source::operator(['url' => $url, 'operator_key' => $key]);
    $this->namespace = 'conf-'.strtolower((string) Str::random(10));
});

afterEach(function () {
    if (isset($this->operator)) {
        $this->operator->deleteRepo($this->namespace, 'one');
        $this->operator->deleteRepo($this->namespace, 'two');

        // The operator client has no namespace delete, so remove the
        // throwaway namespace directly; otherwise every run leaves one behind.
        Http::withToken((string) env('SOURCE_TEST_OPERATOR_KEY'))
            ->delete(rtrim($this->url, '/').'/v1/namespaces/'.rawurlencode($this->namespace).'?force=true');
    }
});

function liveDisk(string $url, string $namespace, string $repo, string $token)
{
    return Storage::build([
        'driver' => 'source', 'url' => $url, 'token' => $token,
        'namespace' => $namespace, 'repo' => $repo, 'branch' => 'main', 'throw' => true,
    ]);
}

it('provisions, mints, writes, reads by version and isolates repos live', function () {
    expect($this->operator->ensureNamespace($this->namespace))->toBeTrue()
        ->and($this->operator->ensureNamespace($this->namespace))->toBeFalse()
        ->and($this->operator->ensureRepo($this->namespace, 'one'))->toBeTrue()
        ->and($this->operator->ensureRepo($this->namespace, 'one'))->toBeFalse()
        ->and($this->operator->ensureRepo($this->namespace, 'two'))->toBeTrue();

    $one = $this->operator->mintToken($this->namespace, 'one', 'write', ['main'], 600);
    $two = $this->operator->mintToken($this->namespace, 'two', 'write', ['main'], 600);

    expect($one->token)->toStartWith('asm_tok_')
        ->and($one->expiresAt)->not->toBeNull();

    $disk = liveDisk($this->url, $this->namespace, 'one', $one->token);
    $disk->put('uploads/a.txt', 'version one');
    $first = $disk->version('uploads/a.txt');
    $disk->put('uploads/a.txt', 'version two');

    expect(stream_get_contents($disk->object($first)))->toBe('version one');

    // Repo two's token cannot read repo one, and repo two has no such object.
    $crossed = liveDisk($this->url, $this->namespace, 'one', $two->token);
    expect(fn () => $crossed->read('uploads/a.txt'))->toThrow(Forbidden::class);

    $other = liveDisk($this->url, $this->namespace, 'two', $two->token);
    expect(fn () => $other->object($first))->toThrow(NotFound::class);

    $this->operator->revokeToken($one->id);
    expect(fn () => liveDisk($this->url, $this->namespace, 'one', $one->token)->read('uploads/a.txt'))
        ->toThrow(Unauthorized::class);
});
