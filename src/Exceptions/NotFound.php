<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * The file, ref or resource does not exist (404 not_found).
 */
class NotFound extends SourceException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Not found.', 'not_found', $details, $requestId);
    }

    public function resource(): ?string
    {
        return isset($this->details['resource']) ? (string) $this->details['resource'] : null;
    }
}
