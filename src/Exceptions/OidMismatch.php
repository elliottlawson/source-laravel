<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * Uploaded bytes did not hash to the oid the client named (422 `oid_mismatch`).
 *
 * Nothing was kept. It means the local Git hash is wrong or the stream changed
 * while it was being read, so sending the same bytes again cannot help; it is
 * never retried.
 */
class OidMismatch extends ValidationFailed
{
    public readonly ?string $expectedOid;

    public readonly ?string $actualOid;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'The uploaded bytes do not hash to the oid the client named.',
            'oid_mismatch',
            $details,
            $requestId,
        );

        $this->expectedOid = is_string($details['expected_oid'] ?? null) ? $details['expected_oid'] : null;
        $this->actualOid = is_string($details['actual_oid'] ?? null) ? $details['actual_oid'] : null;
    }
}
