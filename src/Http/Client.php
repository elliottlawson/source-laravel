<?php

declare(strict_types=1);

namespace Source\Http;

use Source\Author;
use Source\BlobUpload;
use Source\Change;
use Source\Commit;
use Source\CommitPage;
use Source\CommitResult;
use Source\Diff;
use Source\FileStat;
use Source\MergeResult;
use Source\Session;
use Source\SourceFile;
use Source\Support\Payload;
use Source\TreeEntry;
use Source\Uploads\PendingBlob;

/**
 * The transport: everything the driver needs from the `source` API.
 *
 * The driver never talks HTTP itself, so swapping this for the in-memory fake
 * exercises the same retry and version-filling logic (see Source::fake()).
 *
 * The methods map one-to-one onto API endpoints: reads, the two write paths for
 * files (one streamed file, or a batch commit), plus the log, the diff, the
 * session and the merge endpoints.
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
     * Send one all-or-nothing batch commit.
     *
     * The changes carry their own bytes (`content`), name a blob the repo
     * already holds (`oid`), or delete a path.
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

    /**
     * Write one file with its bytes as the request body (api.md 3.4.2).
     *
     * The payload streams: a resource is never read into memory. `$expectedOid`
     * is the version being replaced; `''` means the path must not exist yet.
     *
     * The body's Git blob id travels as `X-Source-Oid`, and `$idempotencyKey`
     * as `Idempotency-Key` (api.md 1.5): together they let the server settle a
     * replay and the stale-write check before it reads the body. One key
     * belongs to one attempt — the caller makes a new one per attempt and
     * reuses it for every transport-level retry of that attempt. `null` sends
     * no key, which is only right for a request that is never retried.
     */
    public function putFile(
        string $namespace,
        string $repo,
        string $ref,
        string $path,
        Payload $payload,
        string $expectedOid,
        string $message,
        ?Author $author = null,
        ?string $mode = null,
        ?string $idempotencyKey = null,
    ): CommitResult;

    /**
     * Which of these blob ids the repo already holds (api.md 3.4.3).
     *
     * The answer is a hint: the write path decides, so a blob reported present
     * may still come back as `422 unknown_object`.
     *
     * @param  list<string>  $oids  1 to 1000 per call; the caller chunks
     * @return list<string>
     */
    public function presentObjects(string $namespace, string $repo, array $oids): array;

    /**
     * Upload one blob without committing it (api.md 3.4.5).
     *
     * The payload is the request body and streams: it is never buffered. `$oid`
     * is the Git blob id the caller computed; the server hashes the bytes again
     * and refuses a mismatch with `422 oid_mismatch` (which maps to
     * OidMismatch). A `200` or `201` both mean success.
     *
     * One attempt only. `429 uploads_busy` is handled by the caller
     * (`ParallelBlobUploads`), which owns the concurrency and the wave-wide
     * `Retry-After`; retrying here would hide the back-pressure from it.
     */
    public function uploadBlob(string $namespace, string $repo, Payload $payload, ?string $oid = null): BlobUpload;

    /**
     * Upload several blobs concurrently with one pool (api.md 3.4.5).
     *
     * Results are keyed by `PendingBlob::$key`. A failed upload appears as the
     * Throwable it threw rather than aborting the whole pool, so the caller can
     * retry only the refused ones. One attempt each, for the same reason as
     * `uploadBlob()`.
     *
     * @param  list<PendingBlob>  $blobs
     * @return array<string, BlobUpload|\Throwable>
     */
    public function uploadBlobs(string $namespace, string $repo, array $blobs, int $concurrency = 4): array;

    /**
     * Stream one blob by its id (`GET /objects/{oid}`), wherever or whether
     * any path holds it now. Throws NotFound when the repo has no such object.
     *
     * @return resource
     */
    public function object(string $namespace, string $repo, string $oid);

    /**
     * One page of the commit log, newest first.
     *
     * `$cursor` is the previous page's `nextCursor`; `$path` filters the log
     * to commits that changed that path (or anything under it).
     */
    public function log(
        string $namespace,
        string $repo,
        string $ref,
        ?string $path = null,
        ?string $cursor = null,
        int $limit = 200,
    ): CommitPage;

    /**
     * One commit, by ref or by commit id.
     */
    public function commit(string $namespace, string $repo, string $ref): Commit;

    /**
     * The structured diff between two refs or commit ids.
     */
    public function diff(string $namespace, string $repo, string $from, string $to, ?string $path = null): Diff;

    /**
     * The same diff as unified text.
     */
    public function diffText(string $namespace, string $repo, string $from, string $to, ?string $path = null): string;

    /**
     * The live session for a branch, or null when the branch has none.
     */
    public function findSession(string $namespace, string $repo, string $ref): ?Session;

    /**
     * Create a session branch from a ref.
     */
    public function createSession(string $namespace, string $repo, string $from, string $ref): Session;

    /**
     * Merge a session into a branch. Throws MergeConflict on a 409.
     */
    public function merge(string $namespace, string $repo, string $sessionId, string $target): MergeResult;

    /**
     * Resolve every conflicted path of a merge in one call.
     *
     * @param  array<string, string>  $resolutions  path => ours|theirs|delete|contents
     */
    public function resolveMerge(
        string $namespace,
        string $repo,
        string $sessionId,
        string $mergeId,
        array $resolutions,
        ?string $message = null,
    ): MergeResult;

    /**
     * Abort a conflicted merge.
     */
    public function abortMerge(string $namespace, string $repo, string $sessionId, ?string $mergeId = null): Session;
}
