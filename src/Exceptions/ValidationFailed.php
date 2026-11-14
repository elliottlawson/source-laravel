<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * A field, path, name, ref or label was invalid (422).
 */
class ValidationFailed extends SourceException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'validation_failed',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message !== '' ? $message : 'One or more fields are invalid.', $errorCode, $details, $requestId);
    }

    /**
     * The field errors, when the payload carries them.
     *
     * @return array<int, mixed>
     */
    public function fields(): array
    {
        return is_array($this->details['fields'] ?? null) ? $this->details['fields'] : [];
    }
}
