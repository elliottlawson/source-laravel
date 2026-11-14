<?php

declare(strict_types=1);

namespace Source;

use Carbon\CarbonImmutable;

/**
 * A token the operator just minted. The secret is shown once: keep it, or
 * mint another. `id` is what revocation takes.
 */
final readonly class IssuedToken
{
    /**
     * @param  list<string>  $refs
     */
    public function __construct(
        public string $id,
        public string $token,
        public string $scope,
        public string $repo,
        public array $refs,
        public ?CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $repo = ''): self
    {
        $expires = is_string($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at']) : null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            token: (string) ($data['token'] ?? ''),
            scope: (string) ($data['scope'] ?? ''),
            repo: $repo !== '' ? $repo : (string) ($data['repo'] ?? ''),
            refs: array_values(array_map('strval', (array) ($data['refs'] ?? []))),
            expiresAt: $expires,
        );
    }
}
