<?php

declare(strict_types=1);

namespace Source\Operator;

use Source\IssuedToken;

/**
 * What an app holding the operator key does: lay out namespaces and repos, and
 * mint the short-lived scoped tokens its disks use.
 *
 * Disks never carry the operator key. An app mints a token scoped to one repo
 * and builds the disk with it, so a bug that points a disk at the wrong repo
 * is refused by the server rather than trusted.
 *
 * The `ensure` calls are idempotent: an existing namespace or repo is not an
 * error, which is what provisioning on every sign-in wants.
 */
interface OperatorClient
{
    /**
     * Create a namespace unless it exists. True when this call created it.
     */
    public function ensureNamespace(string $name): bool;

    /**
     * Create a repo unless it exists. True when this call created it.
     */
    public function ensureRepo(string $namespace, string $name, string $defaultBranch = 'main'): bool;

    /**
     * Delete a repo and everything in it. A missing repo is not an error.
     */
    public function deleteRepo(string $namespace, string $name): void;

    /**
     * Mint a token for one repo.
     *
     * @param  'read'|'write'  $scope
     * @param  list<string>  $refs  the refs the token may write (write scope only)
     * @param  int  $ttl  seconds; the server caps it at 24 hours
     */
    public function mintToken(string $namespace, string $repo, string $scope = 'write', array $refs = ['main'], int $ttl = 3600): IssuedToken;

    /**
     * Revoke a token by id. An unknown token is not an error.
     */
    public function revokeToken(string $id): void;
}
