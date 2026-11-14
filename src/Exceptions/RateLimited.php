<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * Too many requests (429 rate_limited or uploads_busy). `$retryAfter` is in
 * whole seconds.
 */
class RateLimited extends SourceException
{
    public readonly int $retryAfter;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'rate_limited',
        array $details = [],
        ?string $requestId = null,
        int $retryAfter = 0,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'Too many requests.',
            $errorCode,
            $details,
            $requestId,
        );

        $this->retryAfter = $retryAfter;
    }
}
