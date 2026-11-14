<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * A write was attempted on a disk pinned to a point in history.
 *
 * `at()` returns a read-only disk; every write on it throws this. It carries
 * the API's `read_only` code so an app can treat it like the server's refusal.
 *
 * The name is `ReadOnlyDisk` rather than `ReadOnly` because `readonly` is a
 * PHP keyword and cannot be a class name.
 */
class ReadOnlyDisk extends SourceException
{
    public function __construct(
        string $message = '',
        string $errorCode = 'read_only',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'This disk is pinned to a point in history and is read-only.',
            $errorCode,
            $details,
            $requestId,
        );
    }
}
