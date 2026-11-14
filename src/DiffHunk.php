<?php

declare(strict_types=1);

namespace Source;

/**
 * One hunk of a unified diff. `lines` keep their leading ` `, `+` or `-`.
 */
final readonly class DiffHunk
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public int $oldStart,
        public int $oldLines,
        public int $newStart,
        public int $newLines,
        public array $lines = [],
    ) {}
}
