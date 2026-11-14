<?php

declare(strict_types=1);

namespace Source;

/**
 * The answer to `POST .../blobs` (api.md 3.4.5).
 *
 * `201` means these bytes were stored by this request; `200` means the repo
 * already held the blob (reachable from a ref, or uploaded earlier inside its
 * grace period) and nothing new was stored. Either way the blob counts as held
 * until `expiresAt`.
 *
 * `oid` is the Git blob id the server computed; it is checked against the one
 * the client sent, because a mismatch means the local hash or the bytes are
 * wrong.
 */
final readonly class BlobUpload
{
    public function __construct(
        public string $oid,
        public int $size = 0,
        public ?string $expiresAt = null,
        public bool $existing = false,
    ) {}
}
