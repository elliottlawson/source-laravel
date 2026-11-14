<?php

declare(strict_types=1);

namespace Source;

/**
 * A session: a branch the server tracks, mergeable into another branch.
 *
 * `branch()` returns a disk for one of these. The id is what the merge
 * endpoints want; the ref is the branch the disk writes to.
 */
final readonly class Session
{
    public const OPEN = 'open';

    public const MERGING = 'merging';

    public const MERGED = 'merged';

    public const ABANDONED = 'abandoned';

    public function __construct(
        public string $id,
        public string $ref,
        public string $state = self::OPEN,
        public string $baseOid = '',
        public string $tip = '',
        public ?string $mergeCommitOid = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            ref: (string) ($data['ref'] ?? ''),
            state: (string) ($data['state'] ?? self::OPEN),
            baseOid: (string) ($data['base_oid'] ?? ''),
            tip: (string) ($data['tip'] ?? ''),
            mergeCommitOid: is_string($data['merge_commit_oid'] ?? null) ? $data['merge_commit_oid'] : null,
        );
    }

    public function isOpen(): bool
    {
        return $this->state === self::OPEN;
    }
}
