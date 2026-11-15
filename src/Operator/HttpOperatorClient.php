<?php

declare(strict_types=1);

namespace Source\Operator;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Source\Exceptions\SourceException;
use Source\IssuedToken;
use Source\OperatorKey;

/**
 * The operator calls over Laravel's HTTP client (so Http::fake() works).
 *
 * The same client works with a namespace-scoped operator key. The one call it
 * changes is ensureNamespace: a namespace-scoped key cannot create
 * namespaces, so a refusal falls back to "does my namespace already exist?",
 * which is the only namespace it can see.
 */
final class HttpOperatorClient implements OperatorClient
{
    /**
     * @param  array{url?: string, operator_key?: string, timeout?: int|float}  $config
     */
    public function __construct(private readonly array $config)
    {
        if ((string) ($config['url'] ?? '') === '') {
            throw new InvalidArgumentException('The source operator needs a [url].');
        }

        if ((string) ($config['operator_key'] ?? '') === '') {
            throw new InvalidArgumentException('The source operator needs an [operator_key].');
        }
    }

    public function ensureNamespace(string $name): bool
    {
        $response = $this->request()->asJson()->post($this->url('namespaces'), ['name' => $name]);

        if ($response->status() === 403) {
            // A namespace-scoped operator key cannot create a namespace, and it
            // does not need to: its namespace already exists, or the key would
            // not authenticate. Ask for the one namespace it can see. A 200
            // there means provisioning is a no-op; anything else is the
            // refusal the caller must see.
            $existing = $this->request()->get($this->url('namespaces/'.rawurlencode($name)));

            if ($existing->successful()) {
                return false;
            }

            throw SourceException::fromResponse($response);
        }

        return $this->created($response);
    }

    public function ensureRepo(string $namespace, string $name, string $defaultBranch = 'main'): bool
    {
        $response = $this->request()->asJson()->post(
            $this->url('namespaces/'.rawurlencode($namespace).'/repos'),
            ['name' => $name, 'default_branch' => $defaultBranch],
        );

        return $this->created($response);
    }

    public function deleteRepo(string $namespace, string $name): void
    {
        $response = $this->request()->delete(
            $this->url('namespaces/'.rawurlencode($namespace).'/repos/'.rawurlencode($name)),
        );

        if ($response->status() === 404 || $response->status() === 410) {
            return;
        }

        $this->ensureSuccess($response);
    }

    public function mintToken(string $namespace, string $repo, string $scope = 'write', array $refs = ['main'], int $ttl = 3600): IssuedToken
    {
        $body = ['scope' => $scope, 'ttl' => $ttl];

        if ($scope !== 'read') {
            $body['refs'] = array_values($refs);
        }

        $response = $this->request()->asJson()->post(
            $this->url('namespaces/'.rawurlencode($namespace).'/repos/'.rawurlencode($repo).'/tokens'),
            $body,
        );

        $this->ensureSuccess($response);

        return IssuedToken::fromArray((array) ($response->json('token') ?? []), $namespace.'/'.$repo);
    }

    public function revokeToken(string $id): void
    {
        $response = $this->request()->delete($this->url('tokens/'.rawurlencode($id)));

        if ($response->status() === 404 || $response->status() === 410) {
            return;
        }

        $this->ensureSuccess($response);
    }

    public function createOperatorKey(string $namespace, ?string $label = null): OperatorKey
    {
        $body = ['namespace' => $namespace];

        if ($label !== null && $label !== '') {
            $body['label'] = $label;
        }

        $response = $this->request()->asJson()->post($this->url('operator-keys'), $body);

        $this->ensureSuccess($response);

        return OperatorKey::fromArray((array) ($response->json('operator_key') ?? []));
    }

    /**
     * @return list<OperatorKey>
     */
    public function listOperatorKeys(): array
    {
        $response = $this->request()->get($this->url('operator-keys'));

        $this->ensureSuccess($response);

        return array_values(array_map(
            fn (array $key): OperatorKey => OperatorKey::fromArray($key),
            (array) ($response->json('operator_keys') ?? []),
        ));
    }

    public function revokeOperatorKey(string $id): void
    {
        $response = $this->request()->delete($this->url('operator-keys/'.rawurlencode($id)));

        // Gone already: the key is revoked, which is what was asked. A 409 is
        // a config-derived key, which the caller must hear about.
        if ($response->status() === 404 || $response->status() === 410) {
            return;
        }

        $this->ensureSuccess($response);
    }

    /**
     * 201 is created; 409 means it already exists.
     */
    private function created(Response $response): bool
    {
        if ($response->status() === 409) {
            return false;
        }

        $this->ensureSuccess($response);

        return true;
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config['url'], '/').'/v1/'.$path;
    }

    private function request(): PendingRequest
    {
        return Http::accept('application/json')
            ->timeout((float) ($this->config['timeout'] ?? 30))
            ->withToken((string) $this->config['operator_key']);
    }

    private function ensureSuccess(Response $response): void
    {
        if (! $response->successful()) {
            throw SourceException::fromResponse($response);
        }
    }
}
