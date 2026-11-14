<?php

declare(strict_types=1);

namespace Source;

/**
 * The commit that last touched a path, as reported by a 409 file_changed.
 *
 * The server never hands over the new content or the current version on a
 * refusal; this is all a writer gets.
 */
final readonly class ChangedBy
{
    public function __construct(
        public string $commitOid = '',
        public string $tokenId = '',
        public string $actor = '',
        public ?string $at = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            commitOid: (string) ($data['commit_oid'] ?? ''),
            tokenId: (string) ($data['token_id'] ?? ''),
            actor: (string) ($data['actor'] ?? ''),
            at: isset($data['at']) ? (string) $data['at'] : null,
        );
    }

    /**
     * @return array{commit_oid: string, token_id: string, actor: string, at?: string}
     */
    public function toArray(): array
    {
        $out = [
            'commit_oid' => $this->commitOid,
            'token_id' => $this->tokenId,
            'actor' => $this->actor,
        ];

        if ($this->at !== null) {
            $out['at'] = $this->at;
        }

        return $out;
    }
}
