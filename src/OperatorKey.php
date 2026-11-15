<?php

declare(strict_types=1);

namespace Source;

use Carbon\CarbonImmutable;

/**
 * An operator key: the long-lived credential an application holds.
 *
 * A key created with a namespace is admin over that one namespace and nothing
 * else. The secret is shown once, on create; `id` is what revocation takes.
 */
final readonly class OperatorKey
{
    public function __construct(
        public string $id,
        public ?string $namespace,
        public string $prefix,
        public string $origin,
        public ?string $label,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $revokedAt,
        public ?string $secret = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $namespace = $data['namespace'] ?? null;
        $label = $data['label'] ?? null;
        $secret = $data['secret'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            namespace: is_string($namespace) && $namespace !== '' ? $namespace : null,
            prefix: (string) ($data['prefix'] ?? ''),
            origin: (string) ($data['origin'] ?? ''),
            label: is_string($label) && $label !== '' ? $label : null,
            createdAt: is_string($data['created_at'] ?? null) ? CarbonImmutable::parse($data['created_at']) : null,
            revokedAt: is_string($data['revoked_at'] ?? null) ? CarbonImmutable::parse($data['revoked_at']) : null,
            secret: is_string($secret) && $secret !== '' ? $secret : null,
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * True when the key comes from SOURCE_OPERATOR_TOKEN / SOURCE_OPERATOR_KEYS,
     * which the server re-registers on boot: it cannot be revoked over the API.
     */
    public function isFromConfig(): bool
    {
        return $this->origin === 'config';
    }
}
