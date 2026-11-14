<?php

declare(strict_types=1);

namespace Source;

/**
 * One changed file in a structured diff.
 *
 * `oldPath` is only set for a rename or a copy. `hunks` is empty for a binary
 * file.
 */
final readonly class DiffFile
{
    public const ADDED = 'added';

    public const MODIFIED = 'modified';

    public const DELETED = 'deleted';

    public const RENAMED = 'renamed';

    public const COPIED = 'copied';

    public const MODE_CHANGED = 'mode_changed';

    public const TYPE_CHANGED = 'type_changed';

    /**
     * @param  list<DiffHunk>  $hunks
     */
    public function __construct(
        public string $path,
        public string $status,
        public ?string $oldPath = null,
        public bool $binary = false,
        public int $additions = 0,
        public int $deletions = 0,
        public array $hunks = [],
    ) {}

    public function wasAdded(): bool
    {
        return $this->status === self::ADDED;
    }

    public function wasDeleted(): bool
    {
        return $this->status === self::DELETED;
    }

    /**
     * Every path this change touches: the new one, and the old one for a
     * rename or a copy.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [$this->path];

        if ($this->oldPath !== null && $this->oldPath !== '' && $this->oldPath !== $this->path) {
            array_unshift($paths, $this->oldPath);
        }

        return $paths;
    }
}
