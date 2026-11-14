<?php

declare(strict_types=1);

namespace Source\Testing;

use Source\Author;
use Source\Change;
use Source\ChangedBy;
use Source\CommitResult;
use Source\CommittedFile;
use Source\Exceptions\FileChanged;
use Source\Exceptions\NotFound;
use Source\FileStat;
use Source\Http\Client;
use Source\SourceFile;
use Source\TreeEntry;

/**
 * An in-memory `source` server that follows the same rules as the real one.
 *
 * Object ids are real Git blob ids, so identical content is the same version.
 * Every write names the version it replaces; one stale change refuses the
 * whole batch and lists every mismatched path. That way the driver's real
 * logic (retries, version filling, all-or-nothing) runs against it unchanged.
 */
final class FakeClient implements Client
{
    /** @var array<string, array<string, string>> repo key => path => contents */
    private array $files = [];

    /** @var array<string, array<string, ChangedBy>> repo key => path => who moved it */
    private array $changed = [];

    /** @var array<string, array<string, int>> repo key => path => modified time */
    private array $modified = [];

    /** @var array<string, string> repo key => tip commit */
    private array $tips = [];

    /** @var list<array{repo: string, ref: string, message: string, author: ?Author, changes: list<Change>, commit: string}> */
    private array $commits = [];

    private ?string $lastRepoKey = null;

    private int $clock = 1_700_000_000;

    // --- Client ------------------------------------------------------------

    public function head(string $namespace, string $repo, string $ref, string $path): ?FileStat
    {
        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        if (! isset($this->files[$key][$path])) {
            return null;
        }

        $contents = $this->files[$key][$path];

        return new FileStat(
            path: $path,
            version: self::oid($contents),
            size: strlen($contents),
            lastModified: $this->modified[$key][$path] ?? null,
        );
    }

    public function get(string $namespace, string $repo, string $ref, string $path): SourceFile
    {
        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        if (! isset($this->files[$key][$path])) {
            throw new NotFound("File [{$path}] does not exist.", ['resource' => 'object', 'id' => $path]);
        }

        $contents = $this->files[$key][$path];

        return new SourceFile(
            path: $path,
            contents: $contents,
            version: self::oid($contents),
            size: strlen($contents),
        );
    }

    public function listTree(string $namespace, string $repo, string $ref, string $path = '', bool $recursive = false): array
    {
        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        $prefix = trim($path, '/');
        $files = $this->files[$key] ?? [];
        ksort($files);

        $entries = [];
        $directories = [];

        foreach ($files as $file => $contents) {
            $relative = $prefix === '' ? $file : (str_starts_with($file, $prefix.'/') ? substr($file, strlen($prefix) + 1) : null);

            if ($relative === null) {
                continue;
            }

            if ($recursive) {
                // `git ls-tree -r` lists blobs only, so a deep listing has no
                // directory entries. The fake matches the server.
                $entries[$file] = new TreeEntry($file, TreeEntry::TYPE_FILE, self::oid($contents), strlen($contents));

                continue;
            }

            if (str_contains($relative, '/')) {
                $name = strstr($relative, '/', true);
                $directory = $prefix === '' ? $name : $prefix.'/'.$name;
                $directories[$directory] = new TreeEntry($directory, TreeEntry::TYPE_DIR, '', 0);

                continue;
            }

            $entries[$file] = new TreeEntry($file, TreeEntry::TYPE_FILE, self::oid($contents), strlen($contents));
        }

        $all = array_merge($directories, $entries);
        ksort($all);

        return array_values($all);
    }

    public function sendChanges(
        string $namespace,
        string $repo,
        string $ref,
        string $message,
        ?Author $author,
        array $changes,
    ): CommitResult {
        if ($changes === []) {
            throw new \InvalidArgumentException('A commit needs at least one change.');
        }

        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        $current = $this->files[$key] ?? [];

        // Check every change before touching anything: all or nothing.
        $paths = [];
        $changedBy = [];
        $expected = [];

        foreach ($changes as $change) {
            $actual = isset($current[$change->path]) ? self::oid($current[$change->path]) : '';

            if ($actual !== ($change->expectedOid ?? '')) {
                $paths[] = $change->path;
                $expected[$change->path] = $change->expectedOid ?? '';
                $changedBy[$change->path] = $this->changed[$key][$change->path] ?? new ChangedBy;
            }
        }

        if ($paths !== []) {
            throw new FileChanged(
                paths: $paths,
                changed: $changedBy,
                expected: $expected,
                refName: $ref,
            );
        }

        $commit = sha1(implode("\n", [
            'fake-commit',
            (string) ($this->tips[$key] ?? ''),
            $message,
            $author?->name ?? '',
            json_encode(array_map(fn (Change $change) => $change->toArray(), $changes)),
        ]));

        $this->clock++;
        $this->tips[$key] = $commit;
        $this->commits[] = [
            'repo' => $namespace.'/'.$repo,
            'ref' => $ref,
            'message' => $message,
            'author' => $author,
            'changes' => $changes,
            'commit' => $commit,
        ];

        $actor = new ChangedBy(
            commitOid: $commit,
            tokenId: 'fake-token',
            actor: $author?->name ?? 'source',
            at: gmdate('Y-m-d\TH:i:s.000\Z', $this->clock),
        );

        $files = [];

        foreach ($changes as $change) {
            if ($change->delete) {
                unset($this->files[$key][$change->path], $this->modified[$key][$change->path]);
                $files[] = new CommittedFile($change->path, null, 0, '100644', deleted: true);
            } else {
                $contents = (string) $change->content;
                $this->files[$key][$change->path] = $contents;
                $this->modified[$key][$change->path] = $this->clock;
                $files[] = new CommittedFile($change->path, self::oid($contents), strlen($contents));
            }

            $this->changed[$key][$change->path] = $actor;
        }

        return new CommitResult($commit, $ref, $files, $this->tips[$key]);
    }

    // --- Test helpers ------------------------------------------------------

    /**
     * Change a stored file behind the driver's back, as another writer would.
     *
     * A strict write still holding the old version on this path then fails
     * with FileChanged. The change is recorded as a commit, so `changed_by`
     * names it.
     */
    public function simulateChange(string $path, ?string $contents = null): static
    {
        $keys = array_keys(array_filter(
            $this->files,
            fn (array $files) => array_key_exists($path, $files),
        ));

        if ($keys === []) {
            $keys = $this->lastRepoKey !== null ? [$this->lastRepoKey] : [];
        }

        foreach ($keys as $key) {
            [$namespace, $repo, $ref] = $this->parseKey($key);

            $existing = $this->files[$key][$path] ?? null;
            $new = $contents ?? $this->freshContents($path);

            // The point is that the version moves, even for a fixed string.
            if ($new === $existing) {
                $new .= ' ';
            }

            $this->sendChanges(
                $namespace,
                $repo,
                $ref,
                "Simulate change to {$path}",
                new Author('someone else', 'someone@example.com'),
                [Change::put($path, $existing === null ? '' : self::oid($existing), $new)],
            );
        }

        return $this;
    }

    /**
     * @return list<array{repo: string, ref: string, message: string, author: ?Author, changes: list<Change>, commit: string}>
     */
    public function commits(): array
    {
        return $this->commits;
    }

    public function hasCommitted(string $message): bool
    {
        foreach ($this->commits as $commit) {
            if ($commit['message'] === $message) {
                return true;
            }
        }

        return false;
    }

    public function hasWritten(string $path): bool
    {
        foreach ($this->commits as $commit) {
            foreach ($commit['changes'] as $change) {
                if ($change->path === $path) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The stored contents at a path, or null.
     */
    public function contents(string $path): ?string
    {
        foreach ($this->files as $files) {
            if (isset($files[$path])) {
                return $files[$path];
            }
        }

        return null;
    }

    /**
     * The current version at a path, or null.
     */
    public function version(string $path): ?string
    {
        $contents = $this->contents($path);

        return $contents === null ? null : self::oid($contents);
    }

    /**
     * The Git blob id of contents: sha1("blob <len>\0<contents>").
     */
    public static function oid(string $contents): string
    {
        return sha1('blob '.strlen($contents)."\0".$contents);
    }

    private function freshContents(string $path): string
    {
        return "Changed outside the disk: {$path} {$this->clock}\n";
    }

    private function key(string $namespace, string $repo, string $ref): string
    {
        return "{$namespace}/{$repo}@{$ref}";
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseKey(string $key): array
    {
        [$repoPath, $ref] = explode('@', $key, 2);
        [$namespace, $repo] = explode('/', $repoPath, 2);

        return [$namespace, $repo, $ref];
    }
}
