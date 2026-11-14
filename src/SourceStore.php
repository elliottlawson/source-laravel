<?php

declare(strict_types=1);

namespace Source;

use Closure;
use InvalidArgumentException;
use Source\Exceptions\FileChanged;
use Source\Exceptions\NotFound;
use Source\Http\Client;
use Source\Support\Contents;

/**
 * The driver's brain for one repo and branch.
 *
 * It holds the transport, the author and the metadata, and it owns the two
 * rules the whole package is built around:
 *
 *  - a plain write reads the current version, writes against it and retries on
 *    409 file_changed;
 *  - a strict write carries the version the caller named and never retries.
 */
class SourceStore
{
    /**
     * A plain write (or a whole batch of plain writes) gets three attempts.
     */
    public const ATTEMPTS = 3;

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
    ) {}

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

    // --- writing -----------------------------------------------------------

    /**
     * Last-write-wins put. Reads the current version, writes against it and
     * retries on file_changed.
     */
    public function put(string $path, mixed $contents, ?string $message = null): CommitResult
    {
        $contents = Contents::toString($contents);

        return $this->withRetries(function () use ($path, $contents, $message): array {
            $stat = $this->stat($path);

            return [
                $message ?? ($stat === null ? "Create {$path}" : "Update {$path}"),
                [Change::put($path, $stat?->version ?? '', $contents)],
            ];
        });
    }

    /**
     * A strict put: the version the caller named, one attempt, no retry.
     */
    public function putStrict(string $path, mixed $contents, string $expectedOid, ?string $message = null): CommitResult
    {
        $contents = Contents::toString($contents);

        return $this->send(
            $message ?? ($expectedOid === '' ? "Create {$path}" : "Update {$path}"),
            [Change::put($path, $expectedOid, $contents)],
        );
    }

    /**
     * Last-write-wins delete.
     */
    public function delete(string $path, ?string $message = null): CommitResult
    {
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
        return $this->send($message ?? "Delete {$path}", [Change::delete($path, $expectedOid)]);
    }

    /**
     * Move a file with one batch commit: create the destination and delete the
     * source, all or nothing.
     */
    public function move(string $from, string $to): CommitResult
    {
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
     * @param  list<Change>  $changes
     * @param  array<string, mixed>  $meta
     */
    protected function send(string $message, array $changes, ?Author $author = null, array $meta = []): CommitResult
    {
        return $this->client->sendChanges(
            $this->namespace,
            $this->repo,
            $this->ref,
            $this->decorate($message, array_replace($this->meta, $meta)),
            $author ?? $this->defaultAuthor(),
            array_values($changes),
        );
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
