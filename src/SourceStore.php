<?php

declare(strict_types=1);

namespace Source;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Source\Exceptions\CannotRevert;
use Source\Exceptions\Conflict;
use Source\Exceptions\FileChanged;
use Source\Exceptions\MergeConflict;
use Source\Exceptions\NoSession;
use Source\Exceptions\NotFound;
use Source\Exceptions\ReadOnlyDisk;
use Source\Exceptions\UnknownObject;
use Source\Http\Client;
use Source\Support\Payload;
use Source\Uploads\OidWhenPresent;
use Source\Uploads\Uploader;

/**
 * The driver's brain for one repo and branch.
 *
 * It holds the transport, the author and the metadata, and it owns the two
 * rules the whole package is built around:
 *
 *  - a plain write reads the current version, writes against it and retries on
 *    409 file_changed;
 *  - a strict write carries the version the caller named and never retries.
 *
 * Every write leaves through `send()`, which picks one of the server's two write
 * paths: one file streams as the body of `PUT .../files/{ref}/{path}` (api.md
 * 3.4.2), and anything else is one all-or-nothing `POST /commits`. The batch
 * path asks the `Uploader` seam how each change's bytes travel; today that means
 * naming a blob the repo already holds instead of sending it again (3.4.3).
 *
 * History (`history`, `at`, `diff`, `revert`) and branches (`branch`,
 * `mergeInto`) are the extras that need more than the filesystem.
 */
class SourceStore
{
    /**
     * A plain write (or a whole batch of plain writes) gets three attempts.
     */
    public const ATTEMPTS = 3;

    /**
     * How each batch change's bytes reach the server. See Uploader.
     */
    protected Uploader $uploader;

    /**
     * A disk pinned to a commit: reads only.
     */
    protected bool $readOnly = false;

    /**
     * What the disk was pinned at, for the read-only message.
     */
    protected ?string $pinnedAt = null;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        protected Client $client,
        protected string $namespace,
        protected string $repo,
        protected string $ref = 'main',
        protected ?Author $author = null,
        protected array $meta = [],
        protected ?Closure $authorResolver = null,
        protected ?string $sessionId = null,
        ?Uploader $uploader = null,
    ) {
        $this->uploader = $uploader ?? new OidWhenPresent($client);
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function ref(): string
    {
        return $this->ref;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function withAuthor(?Author $author): static
    {
        $clone = clone $this;
        $clone->author = $author;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        $clone = clone $this;
        $clone->meta = array_replace($this->meta, $meta);

        return $clone;
    }

    /**
     * The same disk pointed at another repo in the same namespace.
     *
     * A session belongs to one repo, so it is dropped.
     */
    public function withRepo(string $repo): static
    {
        $clone = clone $this;
        $clone->repo = $repo;
        $clone->sessionId = null;

        return $clone;
    }

    // --- reading -----------------------------------------------------------

    public function read(string $path): SourceFile
    {
        return $this->client->get($this->namespace, $this->repo, $this->ref, $path);
    }

    public function stat(string $path): ?FileStat
    {
        return $this->client->head($this->namespace, $this->repo, $this->ref, $path);
    }

    public function version(string $path): ?string
    {
        return $this->stat($path)?->version;
    }

    public function exists(string $path): bool
    {
        return $this->stat($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        return $this->listTree($path, false) !== [];
    }

    /**
     * @return list<TreeEntry>
     */
    public function listTree(string $path = '', bool $recursive = false): array
    {
        return $this->client->listTree($this->namespace, $this->repo, $this->ref, $path, $recursive);
    }

    /**
     * @return list<string>
     */
    public function files(string $directory = '', bool $recursive = false): array
    {
        return array_values(array_map(
            fn (TreeEntry $entry) => $entry->path,
            array_filter($this->listTree($directory, $recursive), fn (TreeEntry $entry) => $entry->isFile()),
        ));
    }

    /**
     * Directory paths under a prefix.
     *
     * A deep listing from the API has files only (`git ls-tree -r` lists
     * blobs), so the nested directories are derived from the file paths. A
     * shallow listing already carries its direct directories.
     *
     * @return list<string>
     */
    public function directories(string $directory = '', bool $recursive = false): array
    {
        $root = trim($directory, '/');
        $directories = [];

        foreach ($this->listTree($root, $recursive) as $entry) {
            if ($entry->isDirectory()) {
                if ($entry->path !== $root) {
                    $directories[$entry->path] = true;
                }

                continue;
            }

            if (! $recursive) {
                continue;
            }

            $parent = str_contains($entry->path, '/')
                ? substr($entry->path, 0, (int) strrpos($entry->path, '/'))
                : '';

            while ($parent !== '' && $parent !== $root) {
                $directories[$parent] = true;

                if (! str_contains($parent, '/')) {
                    break;
                }

                $parent = substr($parent, 0, (int) strrpos($parent, '/'));
            }
        }

        $paths = array_keys($directories);
        sort($paths);

        return $paths;
    }

    // --- history -----------------------------------------------------------

    /**
     * The commit log, newest first.
     *
     * The whole-repo log can be longer than one page, so this is a
     * LazyCollection: pages are fetched as the caller iterates, and stopping
     * early fetches nothing more. `$path` filters to commits that changed that
     * path (or anything under it).
     *
     * @return LazyCollection<int, Commit>
     */
    public function history(?string $path = null): LazyCollection
    {
        return LazyCollection::make(function () use ($path) {
            $cursor = null;

            do {
                try {
                    $page = $this->client->log($this->namespace, $this->repo, $this->ref, $path, $cursor, 200);
                } catch (Conflict $e) {
                    // An unborn branch is a 409 not_found, not a missing ref:
                    // a branch with no commits yet has no history.
                    if ($e->errorCode === 'not_found') {
                        return;
                    }

                    throw $e;
                }

                foreach ($page->commits as $commit) {
                    yield $commit;
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null && $cursor !== '');
        });
    }

    /**
     * A read-only disk pinned to a commit, a ref, or a point in time.
     *
     * A date resolves to the last commit on this branch at or before it. A
     * version (a blob id) is not a point in tree history and is refused.
     */
    public function at(mixed $point): static
    {
        $oid = $this->resolvePoint($point);

        $clone = clone $this;
        $clone->ref = $oid;
        $clone->readOnly = true;
        $clone->pinnedAt = $this->describe($point);
        // A pinned disk is a point in history, not the session's branch.
        $clone->sessionId = null;

        return $clone;
    }

    /**
     * The structured diff between two points in history.
     *
     * Each side is a ref, a commit oid, a Commit, or a date (which resolves to
     * the last commit at or before it).
     */
    public function diff(mixed $from, mixed $to, ?string $path = null): Diff
    {
        return $this->client->diff(
            $this->namespace,
            $this->repo,
            $this->pointRef($from),
            $this->pointRef($to),
            $path,
        );
    }

    /**
     * The same diff as unified text.
     */
    public function diffText(mixed $from, mixed $to, ?string $path = null): string
    {
        return $this->client->diffText(
            $this->namespace,
            $this->repo,
            $this->pointRef($from),
            $this->pointRef($to),
            $path,
        );
    }

    /**
     * Revert a commit with one new commit.
     *
     * Every file the commit touched is written back to its version from the
     * commit's first parent, expecting the version the commit produced. One
     * stale file refuses the whole revert with FileChanged: nothing is
     * retried and nothing lands partially.
     */
    public function revert(mixed $commit, ?string $message = null): CommitResult
    {
        $this->assertWritable();

        $target = $this->fetchCommit($commit);

        if ($target->isRoot()) {
            throw new CannotRevert(
                "Commit [{$target->oid}] is the root commit; there is no earlier version to restore.",
                'cannot_revert',
                ['commit' => $target->oid],
            );
        }

        if ($target->isMerge()) {
            throw new CannotRevert(
                "Commit [{$target->oid}] is a merge commit; revert the changes on each side instead.",
                'cannot_revert',
                ['commit' => $target->oid, 'parents' => $target->parents],
            );
        }

        $parent = (string) $target->parent();
        $diff = $this->client->diff($this->namespace, $this->repo, $parent, $target->oid);

        $paths = [];

        foreach ($diff->files as $file) {
            foreach ($file->paths() as $path) {
                $paths[$path] = true;
            }
        }

        $changes = [];

        foreach (array_keys($paths) as $path) {
            $before = $this->client->head($this->namespace, $this->repo, $parent, $path);
            $after = $this->client->head($this->namespace, $this->repo, $target->oid, $path);

            if ($before?->version === $after?->version) {
                continue;
            }

            // The commit created the file: reverting deletes it.
            if ($before === null) {
                $changes[] = Change::delete($path, (string) $after?->version);

                continue;
            }

            $contents = $this->client->get($this->namespace, $this->repo, $parent, $path)->contents;

            $changes[] = Change::put(
                $path,
                $after?->version ?? '',
                $contents,
                $before->mode === '100644' ? null : $before->mode,
            );
        }

        if ($changes === []) {
            throw new CannotRevert(
                "Commit [{$target->oid}] changed no file contents, so there is nothing to revert.",
                'cannot_revert',
                ['commit' => $target->oid],
            );
        }

        $message ??= 'Revert "'.$target->subject().'"';

        return $this->send($message, $changes, null, ['Reverts' => $target->oid]);
    }

    // --- branches ----------------------------------------------------------

    /**
     * A disk on another branch, creating a session branch from this disk's
     * branch when the branch has no session yet.
     */
    public function branch(string $name): static
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A branch needs a name.');
        }

        $session = $this->client->findSession($this->namespace, $this->repo, $name);

        if ($session === null) {
            try {
                $session = $this->client->createSession($this->namespace, $this->repo, $this->ref, $name);
            } catch (Conflict $e) {
                // The branch exists without a session (pushed with Git, for
                // example). The disk works; mergeInto() says it cannot merge.
                if ($e->errorCode !== 'ref_exists') {
                    throw $e;
                }
            }
        }

        $clone = clone $this;
        $clone->ref = $name;
        $clone->sessionId = $session?->id;
        $clone->readOnly = false;
        $clone->pinnedAt = null;

        return $clone;
    }

    /**
     * Merge this disk's session into a target branch.
     *
     * A clean merge, a fast-forward, an already-merged session and a
     * strategy-resolved merge return a MergeResult. Conflicts throw
     * MergeConflict, which carries `resolve()` and `abort()`.
     */
    public function mergeInto(string $target): MergeResult
    {
        $sessionId = $this->sessionId;

        if ($sessionId === null) {
            throw new NoSession(
                "The branch [{$this->ref}] has no session, so it cannot be merged. Start it with branch().",
                'no_session',
                ['ref' => $this->ref],
            );
        }

        try {
            return $this->client->merge($this->namespace, $this->repo, $sessionId, $target);
        } catch (MergeConflict $e) {
            throw $e->withActions(
                resolve: fn (array $paths, ?string $message = null): MergeResult => $this->client->resolveMerge(
                    $this->namespace,
                    $this->repo,
                    $sessionId,
                    (string) $e->mergeId(),
                    $paths,
                    $message,
                ),
                abort: function () use ($sessionId, $e): void {
                    $this->client->abortMerge(
                        $this->namespace,
                        $this->repo,
                        $sessionId,
                        $e->mergeId(),
                    );
                },
            );
        }
    }

    /**
     * The session behind this disk's branch, if there is one.
     */
    public function session(): ?Session
    {
        if ($this->sessionId === null) {
            return null;
        }

        return $this->client->findSession($this->namespace, $this->repo, $this->ref);
    }

    /**
     * The branch this disk writes to, without the `refs/heads/` prefix.
     */
    public function branchName(): string
    {
        return str_starts_with($this->ref, 'refs/heads/')
            ? substr($this->ref, strlen('refs/heads/'))
            : $this->ref;
    }

    // --- writing -----------------------------------------------------------

    /**
     * Last-write-wins put. Reads the current version and writes against it,
     * streaming the bytes to the server; retries on file_changed.
     */
    public function put(string $path, mixed $contents, ?string $message = null): CommitResult
    {
        $this->assertWritable();

        $payload = Payload::from($contents);

        return $this->withRetries(function () use ($path, $payload, $message): array {
            $stat = $this->stat($path);

            return [
                $message ?? ($stat === null ? "Create {$path}" : "Update {$path}"),
                [Change::stream($path, $stat?->version ?? '', $payload)],
            ];
        });
    }

    /**
     * A strict put: the version the caller named, one attempt, no retry.
     */
    public function putStrict(string $path, mixed $contents, string $expectedOid, ?string $message = null): CommitResult
    {
        $this->assertWritable();

        return $this->send(
            $message ?? ($expectedOid === '' ? "Create {$path}" : "Update {$path}"),
            [Change::stream($path, $expectedOid, Payload::from($contents))],
        );
    }

    /**
     * Last-write-wins delete.
     */
    public function delete(string $path, ?string $message = null): CommitResult
    {
        $this->assertWritable();

        return $this->withRetries(function () use ($path, $message): array {
            $stat = $this->stat($path) ?? throw new NotFound("File [{$path}] does not exist.");

            return [$message ?? "Delete {$path}", [Change::delete($path, $stat->version)]];
        });
    }

    /**
     * A strict delete: the version the caller named, one attempt, no retry.
     */
    public function deleteStrict(string $path, string $expectedOid, ?string $message = null): CommitResult
    {
        $this->assertWritable();

        return $this->send($message ?? "Delete {$path}", [Change::delete($path, $expectedOid)]);
    }

    /**
     * Move a file with one batch commit: create the destination and delete the
     * source, all or nothing.
     */
    public function move(string $from, string $to): CommitResult
    {
        $this->assertWritable();

        if ($from === $to) {
            throw new InvalidArgumentException('Cannot move a file onto itself.');
        }

        return $this->withRetries(function () use ($from, $to): array {
            $file = $this->read($from);
            $destination = $this->stat($to);

            return [
                "Move {$from} to {$to}",
                [
                    Change::put($to, $destination?->version ?? '', $file->contents),
                    Change::delete($from, $file->version),
                ],
            ];
        });
    }

    /**
     * Copy a file with one commit.
     */
    public function copy(string $from, string $to): CommitResult
    {
        $this->assertWritable();

        return $this->withRetries(function () use ($from, $to): array {
            $file = $this->read($from);
            $destination = $this->stat($to);

            return [
                "Copy {$from} to {$to}",
                [Change::put($to, $destination?->version ?? '', $file->contents)],
            ];
        });
    }

    /**
     * Delete every file under a directory with one commit.
     */
    public function deleteDirectory(string $directory): void
    {
        $this->assertWritable();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $entries = array_values(array_filter(
                $this->listTree($directory, true),
                fn (TreeEntry $entry) => $entry->isFile(),
            ));

            if ($entries === []) {
                return;
            }

            $changes = array_map(
                fn (TreeEntry $entry) => Change::delete($entry->path, $entry->version),
                $entries,
            );

            try {
                $this->send('Delete '.($directory === '' ? 'all files' : $directory), $changes);

                return;
            } catch (FileChanged $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * One all-or-nothing commit built by the closure.
     *
     * @param  array<string, mixed>  $meta
     */
    public function commit(string $message, Closure $callback, mixed $author = null, array $meta = []): CommitResult
    {
        $this->assertWritable();

        $pending = new PendingChanges($this);
        $callback($pending);

        $changes = $pending->all();

        if ($changes === []) {
            throw new InvalidArgumentException('A commit needs at least one change.');
        }

        $author = $author === null ? null : Author::from($author);
        $last = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $wire = $this->resolvePending($changes);

            try {
                return $this->send($message, $wire, $author, $meta);
            } catch (FileChanged $e) {
                $last = $e;

                // Only a batch made purely of plain changes may be retried:
                // re-read the versions and try again. One stale strict change
                // refuses the whole batch, as the server does.
                if (! $this->retryable($e, $changes)) {
                    throw $e;
                }
            }
        }

        throw $last;
    }

    // --- internals ---------------------------------------------------------

    /**
     * The one place a write can leave, and the one place read-only bites.
     *
     * One file (a put, a copy) streams as the body of a raw `PUT` (api.md
     * 3.4.2). A delete, or more than one change, is one batch commit.
     *
     * @param  list<Change>  $changes
     * @param  array<string, mixed>  $meta
     */
    protected function send(string $message, array $changes, ?Author $author = null, array $meta = []): CommitResult
    {
        $this->assertWritable();

        $changes = array_values($changes);

        $message = $this->decorate($message, array_replace($this->meta, $meta));
        $author ??= $this->defaultAuthor();

        if (count($changes) === 1 && ! $changes[0]->isDelete()) {
            return $this->sendFile($message, $changes[0], $author);
        }

        return $this->sendCommit($message, $changes, $author);
    }

    /**
     * One file, streamed: the payload goes as the request body and never into
     * memory.
     */
    protected function sendFile(string $message, Change $change, ?Author $author): CommitResult
    {
        $payload = $change->payload ?? Payload::from((string) $change->content);

        try {
            return $this->client->putFile(
                $this->namespace,
                $this->repo,
                $this->ref,
                $change->path,
                $payload,
                $change->expectedOid ?? '',
                $message,
                $author,
                $change->mode,
            );
        } finally {
            $payload->release();
        }
    }

    /**
     * One all-or-nothing batch commit.
     *
     * The uploader decides which changes travel by oid rather than bytes. Its
     * answer is a hint, so a `422 unknown_object` means "send those bytes";
     * that costs one more POST per commit, once, and is the only retry here.
     *
     * @param  list<Change>  $changes
     */
    protected function sendCommit(string $message, array $changes, ?Author $author): CommitResult
    {
        $inline = [];

        for ($attempt = 1; ; $attempt++) {
            $wire = $this->uploader->prepare($this->namespace, $this->repo, $this->ref, $changes, $inline);

            try {
                return $this->client->sendChanges($this->namespace, $this->repo, $this->ref, $message, $author, $wire);
            } catch (UnknownObject $e) {
                if ($attempt >= 2) {
                    throw $e;
                }

                // The repo stopped holding a blob between the hint and the
                // write: resend those paths' bytes.
                $inline = array_fill_keys($e->paths(), true);
            }
        }
    }

    protected function assertWritable(): void
    {
        if (! $this->readOnly) {
            return;
        }

        throw new ReadOnlyDisk(
            'The disk is pinned at ['.($this->pinnedAt ?? $this->ref).'] and is read-only.',
            'read_only',
            ['ref' => $this->ref],
        );
    }

    /**
     * Turn a `at()` / `diff()` argument into a commit id.
     */
    protected function resolvePoint(mixed $point): string
    {
        if ($point instanceof Commit) {
            return $point->oid;
        }

        if ($point instanceof DateTimeInterface) {
            return $this->commitAtOrBefore(CarbonImmutable::createFromInterface($point));
        }

        if (! is_string($point) || trim($point) === '') {
            throw new InvalidArgumentException('A point in history is a commit id, a ref, a Commit or a date.');
        }

        $point = trim($point);

        if (self::looksLikeDate($point)) {
            return $this->commitAtOrBefore(CarbonImmutable::parse($point));
        }

        return $this->fetchCommit($point)->oid;
    }

    /**
     * Turn a `diff()` argument into something the API resolves: a ref, a
     * commit id, or a date's commit.
     */
    protected function pointRef(mixed $point): string
    {
        if ($point instanceof Commit) {
            return $point->oid;
        }

        if ($point instanceof DateTimeInterface) {
            return $this->commitAtOrBefore(CarbonImmutable::createFromInterface($point));
        }

        if (! is_string($point) || trim($point) === '') {
            throw new InvalidArgumentException('A point in history is a commit id, a ref, a Commit or a date.');
        }

        $point = trim($point);

        return self::looksLikeDate($point)
            ? $this->commitAtOrBefore(CarbonImmutable::parse($point))
            : $point;
    }

    protected function fetchCommit(mixed $point): Commit
    {
        if ($point instanceof Commit) {
            return $point;
        }

        $ref = is_string($point) ? trim($point) : '';

        if ($ref === '') {
            throw new InvalidArgumentException('A commit is a commit id, a ref, or a Commit.');
        }

        try {
            return $this->client->commit($this->namespace, $this->repo, $ref);
        } catch (NotFound $e) {
            // A version is a blob id. The server answers "no such ref", which
            // is true but unhelpful: a blob under a tree can never be a point
            // in tree history.
            if (preg_match('/^[0-9a-f]{40}$/', $ref) === 1) {
                throw new InvalidArgumentException(
                    "A version ([{$ref}]) is a blob id, not a point in history; pass a commit id, a ref, or a date.",
                    0,
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * The last commit on this branch at or before a time.
     */
    protected function commitAtOrBefore(CarbonImmutable $at): string
    {
        foreach ($this->history() as $commit) {
            if ($commit->at->lessThanOrEqualTo($at)) {
                return $commit->oid;
            }
        }

        throw new NotFound(
            "No commit on [{$this->ref}] at or before [{$at->toIso8601String()}].",
            ['resource' => 'commit', 'ref' => $this->ref, 'at' => $at->toIso8601String()],
        );
    }

    /**
     * A date-shaped string is a time, never a ref. `2026-11-01`,
     * `2026-11-01T09:30:00Z` and `2026-11-01 09:30:00` all qualify.
     */
    protected static function looksLikeDate(string $value): bool
    {
        return preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/',
            $value,
        ) === 1;
    }

    protected function describe(mixed $point): string
    {
        if ($point instanceof Commit) {
            return $point->oid;
        }

        if ($point instanceof DateTimeInterface) {
            return $point->format(DATE_ATOM);
        }

        return is_string($point) ? trim($point) : get_debug_type($point);
    }

    /**
     * @param  Closure(): array{0: string, 1: list<Change>}  $build
     */
    protected function withRetries(Closure $build): CommitResult
    {
        $last = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            [$message, $changes] = $build();

            try {
                return $this->send($message, $changes);
            } catch (FileChanged $e) {
                $last = $e;
            }
        }

        throw $last;
    }

    /**
     * Fill in the current version for every plain change.
     *
     * @param  list<PendingChange>  $changes
     * @return list<Change>
     */
    protected function resolvePending(array $changes): array
    {
        $wire = [];

        foreach ($changes as $change) {
            if ($change->isStrict()) {
                $wire[] = $change->toChange();

                continue;
            }

            $stat = $this->stat($change->path);

            if ($change->delete) {
                if ($stat === null) {
                    throw new NotFound("File [{$change->path}] does not exist.");
                }

                $wire[] = Change::delete($change->path, $stat->version);

                continue;
            }

            $wire[] = Change::put($change->path, $stat?->version ?? '', (string) $change->content);
        }

        return $wire;
    }

    /**
     * A retry is safe only when every mismatched path was a plain change.
     *
     * @param  list<PendingChange>  $changes
     */
    protected function retryable(FileChanged $changed, array $changes): bool
    {
        if ($changed->paths === []) {
            return false;
        }

        $byPath = [];

        foreach ($changes as $change) {
            $byPath[$change->path] = $change;
        }

        foreach ($changed->paths as $path) {
            $change = $byPath[$path] ?? null;

            if ($change === null || ! $change->isPlain()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function decorate(string $message, array $meta): string
    {
        $trailers = [];

        foreach ($meta as $key => $value) {
            if ($value === null) {
                continue;
            }

            $key = $this->sanitizeTrailer((string) $key);

            if ($key === '') {
                continue;
            }

            $trailers[] = $key.': '.$this->sanitizeTrailer($this->trailerValue($value));
        }

        if ($trailers === []) {
            return $message;
        }

        return rtrim($message)."\n\n".implode("\n", $trailers);
    }

    protected function trailerValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    /**
     * A trailer is one line: a value with newlines would break the message
     * format the CLI and history read back.
     */
    protected function sanitizeTrailer(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected function defaultAuthor(): ?Author
    {
        if ($this->author !== null) {
            return $this->author;
        }

        if ($this->authorResolver === null) {
            return null;
        }

        return Author::from(($this->authorResolver)());
    }
}
