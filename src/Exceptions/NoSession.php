<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * The disk is on a branch that has no session behind it, so it cannot merge.
 *
 * `branch()` normally creates the session. This happens when the branch
 * already existed on the server without one (a branch pushed with Git, for
 * example), so the disk can be written to and read from but not merged.
 */
class NoSession extends SourceException
{
    public function __construct(
        string $message = '',
        string $errorCode = 'no_session',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'This disk has no session, so it cannot merge. Start it with branch().',
            $errorCode,
            $details,
            $requestId,
        );
    }
}
