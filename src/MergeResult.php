<?php

declare(strict_types=1);

namespace Source;

/**
 * The outcome of a session merge.
 *
 * A clean merge, a fast-forward, an already-merged session and a resolved
 * merge all end up here. A conflicted merge is a `MergeConflict` instead.
 */
final readonly class MergeResult
{
    public function __construct(
        public string $id,
        public string $state,
        public string $target,
        public string $targetOid = '',
        public string $targetTip = '',
        public string $sessionOid = '',
        public string $sessionTip = '',
        public string $mergeBaseOid = '',
        public ?string $mergeOid = null,
        public bool $fastForward = false,
        public bool $alreadyMerged = false,
        public ?string $strategy = null,
        public ?string $mode = null,
        public ?string $commitOid = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $commit = $data['commit'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            target: (string) ($data['target'] ?? ''),
            targetOid: (string) ($data['target_oid'] ?? ''),
            targetTip: (string) ($data['target_tip'] ?? ''),
            sessionOid: (string) ($data['session_oid'] ?? ''),
            sessionTip: (string) ($data['session_tip'] ?? ''),
            mergeBaseOid: (string) ($data['merge_base_oid'] ?? ''),
            mergeOid: is_string($data['merge_oid'] ?? null) ? $data['merge_oid'] : null,
            fastForward: (bool) ($data['fast_forward'] ?? false),
            alreadyMerged: (bool) ($data['already_merged'] ?? false),
            strategy: is_string($data['strategy'] ?? null) ? $data['strategy'] : null,
            mode: is_string($data['mode'] ?? null) ? $data['mode'] : null,
            commitOid: is_array($commit) && is_string($commit['oid'] ?? null) ? $commit['oid'] : null,
        );
    }

    /**
     * The commit the merge produced, if it made one.
     */
    public function commit(): ?string
    {
        return $this->commitOid ?? $this->mergeOid;
    }
}
