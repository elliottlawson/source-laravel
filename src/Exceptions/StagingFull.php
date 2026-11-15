<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * Uploaded-but-uncommitted bytes would exceed the staging cap
 * (409 `staging_full`).
 *
 * It is not a `429`: waiting a second does not help. It clears when commits
 * name the staged blobs or when they expire, so this is never retried.
 */
class StagingFull extends Conflict
{
    public readonly ?string $scope;

    public readonly ?int $limitBytes;

    public readonly ?int $stagedBytes;

    public readonly ?string $nextExpiryAt;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'Uploaded-but-uncommitted bytes would exceed the staging cap.',
            'staging_full',
            $details,
            $requestId,
        );

        $this->scope = is_string($details['scope'] ?? null) ? $details['scope'] : null;
        $this->limitBytes = isset($details['limit_bytes']) ? (int) $details['limit_bytes'] : null;
        $this->stagedBytes = isset($details['staged_bytes']) ? (int) $details['staged_bytes'] : null;
        $this->nextExpiryAt = is_string($details['next_expiry_at'] ?? null) ? $details['next_expiry_at'] : null;
    }
}
