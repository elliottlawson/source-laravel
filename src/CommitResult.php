<?php

declare(strict_types=1);

namespace Source;

/**
 * The result of one write: the commit and the files it touched.
 */
final readonly class CommitResult
{
    /**
     * @param  list<CommittedFile>  $files
     */
    public function __construct(
        public string $commitOid,
        public ?string $refName = null,
        public array $files = [],
        public ?string $baseOid = null,
    ) {}

    public function file(string $path): ?CommittedFile
    {
        foreach ($this->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The new version of a path, or null when the commit deleted it.
     */
    public function version(string $path): ?string
    {
        return $this->file($path)?->version;
    }
}
