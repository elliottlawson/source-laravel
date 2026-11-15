<?php

declare(strict_types=1);

namespace Source\Operator;

use Source\IssuedToken;
use Source\OperatorKey;

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
 *
 * The same client works with a namespace-scoped operator key: everything it
 * does stays inside that namespace, and another namespace is NotFound rather
 * than Forbidden. The exceptions are the key-management calls, which need an
 * instance-wide key, and ensureNamespace, which a namespace-scoped key can only
 * confirm (it cannot create namespaces).
 */
interface OperatorClient
{
    /**
     * Create a namespace unless it exists. True when this call created it.
     *
     * With a namespace-scoped operator key the server refuses the create, so
     * the call checks whether the one namespace the key can see exists: true is
     * impossible, false means it is already there, and another namespace is
     * Forbidden.
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

    /**
     * Create a key that is admin over one namespace and nothing else.
     *
     * The secret is on the returned key and is the only time it is ever
     * returned; store it somewhere the application can read it. Creating a key
     * needs an instance-wide operator key: a namespace-scoped key cannot mint
     * another key.
     */
    public function createOperatorKey(string $namespace, ?string $label = null): OperatorKey;

    /**
     * List every operator key, including config-derived and revoked ones.
     *
     * No secrets are returned. Needs an instance-wide operator key.
     *
     * @return list<OperatorKey>
     */
    public function listOperatorKeys(): array;

    /**
     * Revoke one key by id. Takes effect on the key's next request.
     *
     * A config-derived key cannot be revoked here: the server answers
     * 409 operator_key_from_config. An unknown id is not an error.
     */
    public function revokeOperatorKey(string $id): void;
}
