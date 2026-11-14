<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * The token is missing, invalid, expired or revoked (401).
 */
class Unauthorized extends SourceException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'unauthorized',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Unauthorized.', $errorCode, $details, $requestId);
    }
}
