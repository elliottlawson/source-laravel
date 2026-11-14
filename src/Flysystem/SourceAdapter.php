<?php

declare(strict_types=1);

namespace Source\Flysystem;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Source\Exceptions\SourceException;
use Source\SourceStore;
use Source\Support\Contents;

/**
 * The Flysystem adapter under the Laravel disk.
 *
 * It is a thin translation layer: the version rules and retries live in
 * SourceStore. Errors become Flysystem exceptions so Laravel's filesystem
 * adapter can honour the disk's `throw` option the usual way.
 */
final class SourceAdapter implements FilesystemAdapter
{
    public function __construct(private readonly SourceStore $store) {}

    public function fileExists(string $path): bool
    {
        try {
            return $this->store->exists($path);
        } catch (SourceException $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return $this->store->directoryExists($path);
        } catch (SourceException $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->store->put($path, $contents);
        } catch (SourceException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->store->put($path, Contents::toString($contents));
        } catch (SourceException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->store->read($path)->contents;
        } catch (SourceException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+');

        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $this->store->delete($path);
        } catch (SourceException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->store->deleteDirectory($path);
        } catch (SourceException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Git has no empty directories; creating one is a no-op.
     */
    public function createDirectory(string $path, Config $config): void
    {
        if ($path === '') {
            return;
        }

        // Nothing to do: a directory exists once a file is written under it.
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $entries = $this->store->listTree($path, $deep);
        } catch (SourceException $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }

        foreach ($entries as $entry) {
            if ($entry->isDirectory()) {
                yield new DirectoryAttributes($entry->path);
            } else {
                yield new FileAttributes($entry->path, $entry->size);
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->store->move($source, $destination);
        } catch (SourceException $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->store->copy($source, $destination);
        } catch (SourceException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        $stat = $this->stat($path);

        return new FileAttributes($path, $stat->size, null, $stat->lastModified ?? 0);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, $this->stat($path)->size);
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $contents = $this->store->read($path)->contents;
        } catch (SourceException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'text/plain';

        return new FileAttributes($path, null, null, null, $mime);
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'source does not store visibility.');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'source does not store visibility.');
    }

    private function stat(string $path): \Source\FileStat
    {
        try {
            $stat = $this->store->stat($path);
        } catch (SourceException $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }

        if ($stat === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'The file does not exist.');
        }

        return $stat;
    }

    /**
     * The store this adapter writes through.
     */
    public function store(): SourceStore
    {
        return $this->store;
    }
}
