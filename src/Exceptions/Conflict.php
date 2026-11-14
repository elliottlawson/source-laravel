<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * A 409 that is neither a file_changed nor a merge_conflict, such as
 * stale_base or not_fast_forward. The API code is on `$errorCode`.
 */
class Conflict extends SourceException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'conflict',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message !== '' ? $message : 'The request conflicts with the current state.', $errorCode, $details, $requestId);
    }
}
