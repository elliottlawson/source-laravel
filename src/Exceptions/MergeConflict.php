<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * A merge produced conflicts (409 merge_conflict).
 *
 * Only the class exists for now; the conflict payload is in `$details`.
 */
class MergeConflict extends SourceException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'merge_conflict',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message, $errorCode, $details, $requestId);
    }

    /**
     * The conflicted paths, when the payload names them.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach (($this->details['conflicts'] ?? []) as $conflict) {
            if (is_array($conflict) && is_string($conflict['path'] ?? null)) {
                $paths[] = $conflict['path'];
            }
        }

        return $paths;
    }
}
