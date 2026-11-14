<?php

declare(strict_types=1);

namespace Source\Testing;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert as PHPUnit;
use Source\IssuedToken;
use Source\Operator\OperatorClient;

/**
 * The operator half of Source::fake(): namespaces, repos and tokens in memory.
 *
 * Disks on the fake don't check tokens, so this records what was minted for
 * assertions; it does not enforce scopes.
 */
final class FakeOperator implements OperatorClient
{
    /** @var array<string, true> */
    private array $namespaces = [];

    /** @var array<string, string> "ns/repo" => default branch */
    private array $repos = [];

    /** @var array<string, IssuedToken> id => token */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $revoked = [];

    private int $ids = 0;

    public function ensureNamespace(string $name): bool
    {
        if (isset($this->namespaces[$name])) {
            return false;
        }

        $this->namespaces[$name] = true;

        return true;
    }

    public function ensureRepo(string $namespace, string $name, string $defaultBranch = 'main'): bool
    {
        $key = $namespace.'/'.$name;

        if (isset($this->repos[$key])) {
            return false;
        }

        $this->namespaces[$namespace] = true;
        $this->repos[$key] = $defaultBranch;

        return true;
    }

    public function deleteRepo(string $namespace, string $name): void
    {
        unset($this->repos[$namespace.'/'.$name]);
    }

    public function mintToken(string $namespace, string $repo, string $scope = 'write', array $refs = ['main'], int $ttl = 3600): IssuedToken
    {
        $id = sprintf('tok%023d', ++$this->ids);

        return $this->tokens[$id] = new IssuedToken(
            id: $id,
            token: 'asm_tok_fake_'.$id,
            scope: $scope,
            repo: $namespace.'/'.$repo,
            refs: $scope === 'read' ? [] : array_values($refs),
            expiresAt: CarbonImmutable::now()->addSeconds(min($ttl, 86400)),
        );
    }

    public function revokeToken(string $id): void
    {
        $this->revoked[$id] = true;
    }

    public function hasRepo(string $namespace, string $name): bool
    {
        return isset($this->repos[$namespace.'/'.$name]);
    }

    public function hasNamespace(string $name): bool
    {
        return isset($this->namespaces[$name]);
    }

    /**
     * @return list<string> "ns/repo"
     */
    public function repos(): array
    {
        return array_keys($this->repos);
    }

    /**
     * @return list<IssuedToken>
     */
    public function tokens(?string $repo = null): array
    {
        return array_values(array_filter(
            $this->tokens,
            fn (IssuedToken $token): bool => $repo === null || $token->repo === $repo,
        ));
    }

    public function isRevoked(string $id): bool
    {
        return isset($this->revoked[$id]);
    }

    public function assertRepoCreated(string $namespace, string $name): void
    {
        PHPUnit::assertTrue($this->hasRepo($namespace, $name), "The repo [{$namespace}/{$name}] was not created.");
    }
}
