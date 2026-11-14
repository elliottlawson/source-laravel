<?php

declare(strict_types=1);

namespace Source\Uploads;

use Source\Change;

/**
 * The one switch point for how a change's bytes reach the server.
 *
 * A batch commit (`POST /commits`) has to say, for every put, whether the bytes
 * travel inside the JSON body or whether the change names a blob the server
 * already holds. That decision lives here and nowhere else: `SourceStore` builds
 * the changes, calls `prepare()`, and posts the result unchanged.
 *
 * **Today** the implementation is `OidWhenPresent`. It hashes each put as a Git
 * blob, asks `POST /objects/present` which ones the repo holds (api.md 3.4.3)
 * and swaps those changes to `{path, expected_oid, oid}`; everything else keeps
 * its bytes inline. A commit through a branch that already holds the content
 * therefore sends no bytes at all for it. A server that refuses an `oid` with
 * `422 unknown_object` is answered by preparing again with those paths forced
 * inline.
 *
 * **When `POST /blobs` lands** (pickups/source-blob-upload.md) the blob endpoint
 * streams one file per request and commits nothing, so the swap becomes an
 * upload: the replacement implementation hashes every put, asks
 * `objects/present`, uploads the missing blobs in parallel with adaptive
 * concurrency (lowered on `429 uploads_busy`, per api.md 3.4.4), and returns
 * every change as an `oid` reference. The commit body then never carries bytes.
 * `prepare()`'s signature and everything that calls it stay as they are; only a
 * new class in this namespace and the default in `SourceStore` change. That is
 * the whole seam, and this docblock is its contract.
 */
interface Uploader
{
    /**
     * Decide how each change's bytes reach the server for one commit.
     *
     * @param  list<Change>  $changes
     * @param  array<string, true>  $forceInline  paths whose bytes must travel
     *                                            inline even if the server
     *                                            could take an oid (the
     *                                            `422 unknown_object` fallback)
     * @return list<Change>
     */
    public function prepare(string $namespace, string $repo, string $ref, array $changes, array $forceInline = []): array;
}
