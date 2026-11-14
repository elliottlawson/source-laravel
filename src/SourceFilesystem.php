<?php

declare(strict_types=1);

namespace Source;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use Source\Flysystem\SourceAdapter;

/**
 * The disk: an ordinary Laravel filesystem with the `source` extras on top.
 *
 * The extras are version reads, strict writes and explicit commits. The
 * ordinary methods (put, get, delete, ...) are last-write-wins and each write
 * is one commit.
 */
class SourceFilesystem extends FilesystemAdapter
{
    public function __construct(
        FilesystemOperator $driver,
        FlysystemAdapter $adapter,
        array $config,
        protected SourceStore $store,
    ) {
        parent::__construct($driver, $adapter, $config);
    }

    // --- the extras --------------------------------------------------------

    /**
     * Read a file with its version.
     */
    public function read(string $path): SourceFile
    {
        return $this->store->read($path);
    }

    /**
     * The file's version (its Git blob id), or null when it does not exist.
     */
    public function version(string $path): ?string
    {
        return $this->store->version($path);
    }

    /**
     * A strict write: it only lands if the file is still this version.
     */
    public function ifUnchanged(?string $version): PendingGuard
    {
        return PendingGuard::eager($this->store, $version ?? '');
    }

    /**
     * A create-only write: it only lands if the path does not exist yet.
     */
    public function ifMissing(): PendingGuard
    {
        return PendingGuard::eager($this->store, '');
    }

    /**
     * Several files in one all-or-nothing commit.
     *
     * @param  array<string, mixed>  $meta
     */
    public function commit(string $message, Closure $callback, mixed $author = null, array $meta = []): CommitResult
    {
        return $this->store->commit($message, $callback, $author, $meta);
    }

    /**
     * The store behind this disk (repository, branch, author, metadata).
     */
    public function store(): SourceStore
    {
        return $this->store;
    }

    // --- configured copies -------------------------------------------------

    /**
     * A copy of this disk whose writes are attributed to the given author.
     *
     * The disk itself is immutable: the shared instance keeps its own author.
     */
    public function as(mixed $user): static
    {
        return $this->withStore($this->store->withAuthor(Author::from($user)));
    }

    /**
     * A copy of this disk whose commits carry the given metadata trailers.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        return $this->withStore($this->store->withMeta($meta));
    }

    protected function withStore(SourceStore $store): static
    {
        $adapter = new SourceAdapter($store);

        return new static(new Filesystem($adapter), $adapter, $this->config, $store);
    }
}
