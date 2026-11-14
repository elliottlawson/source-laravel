<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * The request body, file or commit was over a limit (413 payload_too_large).
 */
class FileTooLarge extends SourceException
{
    public readonly ?int $limitBytes;

    public readonly ?int $actualBytes;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message, 'payload_too_large', $details, $requestId);

        $this->limitBytes = isset($details['limit_bytes']) ? (int) $details['limit_bytes'] : null;
        $this->actualBytes = isset($details['actual_bytes']) ? (int) $details['actual_bytes'] : null;
    }
}
