<?php

declare(strict_types=1);

namespace Source\Support;

use Illuminate\Support\Str;

/**
 * An `Idempotency-Key` for one write request (api.md 1.5).
 *
 * The key identifies one logical write. It stays the same across the transport's
 * `429` retries, because a retry of the same request must be recognised as one
 * write. A last-write-wins retry after `409 file_changed` is a *different*
 * request, so the driver sends it with a fresh key: it carries a new
 * `expected_oid` and must not replay the refused response.
 *
 * @internal
 */
final class IdempotencyKey
{
    public static function generate(): string
    {
        // A UUID is inside the API's `[A-Za-z0-9._:-]{8,255}` alphabet.
        return (string) Str::uuid();
    }
}
