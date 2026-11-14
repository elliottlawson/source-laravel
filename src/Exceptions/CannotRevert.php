<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * The commit cannot be reverted as one commit.
 *
 * A root commit has nothing to write back to, and reverting a merge commit is
 * not one change; both are refused before anything is sent.
 */
class CannotRevert extends SourceException
{
    public function __construct(
        string $message = '',
        string $errorCode = 'cannot_revert',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'That commit cannot be reverted in one commit.',
            $errorCode,
            $details,
            $requestId,
        );
    }
}
