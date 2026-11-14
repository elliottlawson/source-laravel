<?php

declare(strict_types=1);

namespace Source\Uploads;

use Source\Change;

/**
 * The one switch point for how a change's bytes reach the server.
 *
 * A batch commit (`POST /commits`) has to say, for every put, whether the bytes
 * travel inside the JSON body or whether the change names a blob the server
 * holds. That decision lives here and nowhere else: `SourceStore` builds the
 * changes, calls `prepare()`, and posts the result unchanged.
 *
 * **Today** the implementation is `ParallelBlobUploads` (api.md 3.4.5). It
 * hashes every put as a Git blob, asks `POST /objects/present` which ones the
 * repo holds (3.4.3), keeps puts under 64 KiB inline, uploads the rest to
 * `POST /blobs` in parallel with adaptive concurrency, and returns those
 * changes as `{path, expected_oid, oid}`. A `422 unknown_object` on the commit
 * is answered by preparing again with those paths forced: their bytes are sent
 * again (through `/blobs`, or inline when tiny) and the same commit is retried
 * once.
 *
 * `OidWhenPresent` is the earlier implementation of the same seam: it names a
 * blob the repo already holds and sends everything else inline. It still works
 * and is kept for callers that want no upload requests at all.
 *
 * A commit through a branch that already holds the content sends no bytes for
 * it either way. A server that predates `/blobs` is detected by its 404 and
 * answered inline for this and later commits.
 */
interface Uploader
{
    /**
     * Decide how each change's bytes reach the server for one commit.
     *
     * @param  list<Change>  $changes
     * @param  array<string, true>  $forceUpload  paths whose bytes must be sent
     *                                            again even if the server says
     *                                            it holds them (the
     *                                            `422 unknown_object` fallback)
     * @return list<Change>
     */
    public function prepare(string $namespace, string $repo, string $ref, array $changes, array $forceUpload = []): array;
}
