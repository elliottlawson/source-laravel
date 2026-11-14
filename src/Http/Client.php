<?php

declare(strict_types=1);

namespace Source\Http;

use Source\Author;
use Source\Change;
use Source\CommitResult;
use Source\FileStat;
use Source\SourceFile;
use Source\TreeEntry;

/**
 * The transport: everything the driver needs from the `source` API.
 *
 * The driver never talks HTTP itself, so swapping this for the in-memory fake
 * exercises the same retry and version-filling logic (see Source::fake()).
 */
interface Client
{
    /**
     * Read a file's metadata, or null when the path does not exist.
     */
    public function head(string $namespace, string $repo, string $ref, string $path): ?FileStat;

    /**
     * Read a file's bytes and version. Throws NotFound when it is missing.
     */
    public function get(string $namespace, string $repo, string $ref, string $path): SourceFile;

    /**
     * List one level (or every level) of a tree.
     *
     * @return list<TreeEntry>
     */
    public function listTree(string $namespace, string $repo, string $ref, string $path = '', bool $recursive = false): array;

    /**
     * Send one all-or-nothing batch commit. This is the only write path.
     *
     * @param  list<Change>  $changes
     */
    public function sendChanges(
        string $namespace,
        string $repo,
        string $ref,
        string $message,
        ?Author $author,
        array $changes,
    ): CommitResult;
}
