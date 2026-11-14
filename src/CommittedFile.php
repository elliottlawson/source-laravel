<?php

declare(strict_types=1);

namespace Source;

/**
 * One file touched by a commit, as the server reports it.
 */
final readonly class CommittedFile
{
    public function __construct(
        public string $path,
        public ?string $version,
        public int $size = 0,
        public string $mode = '100644',
        public bool $deleted = false,
    ) {}
}
