<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * The token lacks the scope, the ref is not writable, or the view is read-only
 * (403).
 */
class Forbidden extends SourceException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'forbidden',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Forbidden.', $errorCode, $details, $requestId);
    }
}
