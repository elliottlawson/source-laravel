<?php

declare(strict_types=1);

namespace Source;

/**
 * One entry in a tree listing.
 */
final readonly class TreeEntry
{
    public const TYPE_FILE = 'file';

    public const TYPE_DIR = 'dir';

    public const TYPE_SYMLINK = 'symlink';

    public function __construct(
        public string $path,
        public string $type,
        public string $version,
        public int $size = 0,
    ) {}

    public function isFile(): bool
    {
        return $this->type !== self::TYPE_DIR;
    }

    public function isDirectory(): bool
    {
        return $this->type === self::TYPE_DIR;
    }
}
