<?php

declare(strict_types=1);

namespace Source;

/**
 * A file as it exists on a branch: contents plus its object id.
 */
final readonly class SourceFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public string $version,
        public int $size,
    ) {}
}
