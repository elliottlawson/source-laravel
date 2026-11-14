<?php

declare(strict_types=1);

namespace Source\Uploads;

use Source\Support\Payload;

/**
 * One blob waiting for its own `POST .../blobs` (api.md 3.4.5).
 *
 * `key` is only a correlation key inside one pooled wave (the commit path is
 * the natural one); the server never sees it. The payload is the body and is
 * rewound before every attempt, so a retry sends the same bytes.
 */
final readonly class PendingBlob
{
    public function __construct(
        public string $key,
        public Payload $payload,
        public string $oid,
    ) {}
}
