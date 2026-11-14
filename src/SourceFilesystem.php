<?php

declare(strict_types=1);

namespace Source;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Source\Exceptions\SourceException;
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

    /**
     * A deep listing from the API has files only, so nested directories are
     * derived from the file paths (see SourceStore::directories()).
     */
    public function directories($directory = null, $recursive = false)
    {
        return $this->store->directories($directory ?? '', $recursive);
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

    // --- error surface -----------------------------------------------------

    /*
     * The Flysystem adapter translates every failure into a Flysystem
     * exception so Laravel's base class can honour `throw` (return false when
     * it is off). When it does throw, unwrap the typed SourceException again:
     * a failed plain put should surface FileChanged, not UnableToWriteFile.
     */

    public function exists($path)
    {
        return $this->surface(fn () => parent::exists($path));
    }

    public function fileExists($path)
    {
        return $this->surface(fn () => parent::fileExists($path));
    }

    public function directoryExists($path)
    {
        return $this->surface(fn () => parent::directoryExists($path));
    }

    public function put($path, $contents, $options = [])
    {
        return $this->surface(fn () => parent::put($path, $contents, $options));
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->surface(fn () => parent::writeStream($path, $resource, $options));
    }

    public function get($path)
    {
        return $this->surface(fn () => parent::get($path));
    }

    public function readStream($path)
    {
        return $this->surface(fn () => parent::readStream($path));
    }

    public function delete($paths)
    {
        $arguments = func_get_args();

        return $this->surface(fn () => parent::delete(...$arguments));
    }

    public function copy($from, $to)
    {
        return $this->surface(fn () => parent::copy($from, $to));
    }

    public function move($from, $to)
    {
        return $this->surface(fn () => parent::move($from, $to));
    }

    public function size($path)
    {
        return $this->surface(fn () => parent::size($path));
    }

    public function lastModified($path)
    {
        return $this->surface(fn () => parent::lastModified($path));
    }

    public function mimeType($path)
    {
        return $this->surface(fn () => parent::mimeType($path));
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->surface(fn () => parent::files($directory, $recursive));
    }

    public function deleteDirectory($directory)
    {
        return $this->surface(fn () => parent::deleteDirectory($directory));
    }

    /**
     * Run an operation and surface its typed source error.
     *
     * @param  Closure(): mixed  $operation
     */
    protected function surface(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (UnableToWriteFile|UnableToSetVisibility|UnableToDeleteFile|UnableToDeleteDirectory|UnableToMoveFile|UnableToCopyFile|UnableToReadFile|UnableToRetrieveMetadata|UnableToCheckExistence|UnableToListContents|UnableToCreateDirectory $e) {
            $previous = $e->getPrevious();

            throw $previous instanceof SourceException ? $previous : $e;
        }
    }
}
