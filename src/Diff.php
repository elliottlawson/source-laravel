<?php

declare(strict_types=1);

namespace Source;

/**
 * The diff between two points in history.
 *
 * `from` and `to` are the commit ids the server resolved, not the refs that
 * were asked for.
 */
final readonly class Diff
{
    /**
     * @param  list<DiffFile>  $files
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $files = [],
    ) {}

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(fn (DiffFile $file) => $file->path, $this->files);
    }

    public function file(string $path): ?DiffFile
    {
        foreach ($this->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }
}
