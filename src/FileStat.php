<?php

declare(strict_types=1);

namespace Source;

/**
 * Metadata of a file without its contents (HEAD).
 */
final readonly class FileStat
{
    public function __construct(
        public string $path,
        public string $version,
        public int $size,
        public ?int $lastModified = null,
        public string $mode = '100644',
    ) {}
}
