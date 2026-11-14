<?php

declare(strict_types=1);

namespace Source\Testing;

use Closure;
use Source\Author;
use Source\Change;
use Source\ChangedBy;
use Source\Commit;
use Source\CommitPage;
use Source\CommitResult;
use Source\CommittedFile;
use Source\Diff;
use Source\DiffFile;
use Source\DiffHunk;
use Source\Exceptions\Conflict;
use Source\Exceptions\FileChanged;
use Source\Exceptions\MergeConflict;
use Source\Exceptions\NotFound;
use Source\Exceptions\UnknownObject;
use Source\Exceptions\UploadsBusy;
use Source\Exceptions\ValidationFailed;
use Source\FileStat;
use Source\Http\Backoff;
use Source\Http\Client;
use Source\MergeResult;
use Source\Session;
use Source\SourceFile;
use Source\Support\Payload;
use Source\TreeEntry;

/**
 * An in-memory `source` server that follows the same rules as the real one.
 *
 * Object ids are real Git blob ids, so identical content is the same version.
 * Every write names the version it replaces; one stale change refuses the
 * whole batch and lists every mismatched path. That way the driver's real
 * logic (retries, version filling, all-or-nothing) runs against it unchanged.
 *
 * History, diffs, sessions and merges are modelled too: commits form a graph
 * of tree snapshots, so `at()`, `revert()` and a merge all read a real tree
 * rather than a reconstruction. The merge is deliberately simple — it
 * conflicts when both sides changed the same path to different contents since
 * the merge base, and otherwise takes the changed side, which is what an
 * ordinary three-way merge does.
 *
 * The two write forms of api.md 3.4.2 are both here: `putFile()` is the raw
 * streamed PUT (it hashes the payload as Git does and counts the bytes as
 * uploaded), `sendChanges()` is the batch commit with inline bytes or `oid`
 * references. `presentObjects()` answers like the server: a blob the repo holds
 * from any commit it has recorded. A commit naming a blob it does not hold is a
 * `422 unknown_object`, and `simulateBusy()` makes the next writes answer `429
 * uploads_busy` so the client's back-off can be tested.
 */
final class FakeClient implements Client
{
    /** @var array<string, array<string, string>> repo key => path => contents */
    private array $files = [];

    /** @var array<string, array<string, string>> repo => blob oid => contents */
    private array $blobs = [];

    /** @var array<string, list<int>> path => sizes of raw PUTs that carried it */
    private array $uploads = [];

    /** @var array<string, array<string, ChangedBy>> repo key => path => who moved it */
    private array $changed = [];

    /** @var array<string, array<string, int>> repo key => path => modified time */
    private array $modified = [];

    /** @var array<string, string> repo key => tip commit ('' for an unborn branch) */
    private array $tips = [];

    /** @var array<string, array<string, mixed>> commit id => node */
    private array $graph = [];

    /** @var list<array{repo: string, ref: string, message: string, author: ?Author, changes: list<Change>, commit: string}> */
    private array $commits = [];

    /** @var array<string, array<string, mixed>> session id => row */
    private array $sessions = [];

    /** @var array<string, array<string, mixed>> merge id => row */
    private array $merges = [];

    /** @var array<string, string> session id => pending merge id */
    private array $pending = [];

    private ?string $lastRepoKey = null;

    private int $clock = 1_700_000_000;

    private int $sequence = 0;

    private int $ids = 0;

    /**
     * A hook that runs before every write, so a test can interleave another
     * writer between the driver's version read and its send.
     *
     * @var (Closure(self): void)|null
     */
    private ?Closure $writeHook = null;

    private bool $inHook = false;

    /**
     * How many more write attempts answer 429 uploads_busy.
     */
    private int $busy = 0;

    private int $busyRetryAfter = 1;

    private readonly Backoff $backoff;

    public function __construct(?Backoff $backoff = null)
    {
        $this->backoff = $backoff ?? new Backoff;
    }

    /**
     * Run the hook before each write. Pass null to remove it.
     *
     * @param  (Closure(self): void)|null  $hook
     */
    public function beforeWrite(?Closure $hook): static
    {
        $this->writeHook = $hook;

        return $this;
    }

    // --- Client: files -----------------------------------------------------

    public function head(string $namespace, string $repo, string $ref, string $path): ?FileStat
    {
        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        $files = $this->treeFor($namespace, $repo, $ref);

        if (! isset($files[$path])) {
            return null;
        }

        $contents = $files[$path];

        return new FileStat(
            path: $path,
            version: self::oid($contents),
            size: strlen($contents),
            lastModified: $this->modified[$key][$path] ?? ($this->graph[$ref]['at'] ?? null),
        );
    }

    public function get(string $namespace, string $repo, string $ref, string $path): SourceFile
    {
        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        $files = $this->treeFor($namespace, $repo, $ref);

        if (! isset($files[$path])) {
            throw new NotFound("File [{$path}] does not exist.", ['resource' => 'object', 'id' => $path]);
        }

        $contents = $files[$path];

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
        $files = $this->treeFor($namespace, $repo, $ref);
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

        return $this->backoff->run(function () use ($namespace, $repo, $ref, $message, $author, $changes): CommitResult {
            $this->busyCheck();

            return $this->commitOnce($namespace, $repo, $ref, $message, $author, $changes);
        });
    }

    /**
     * One batch commit, applied all or nothing.
     *
     * A change either carries bytes or names a blob; a named blob must be one
     * this repo holds, or the whole commit is refused with 422 unknown_object
     * and nothing is written (api.md 3.4.3).
     *
     * @param  list<Change>  $changes
     */
    private function commitOnce(
        string $namespace,
        string $repo,
        string $ref,
        string $message,
        ?Author $author,
        array $changes,
    ): CommitResult {
        $key = $this->key($namespace, $repo, $ref);
        $this->lastRepoKey = $key;

        $this->runWriteHook();

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

        // A named blob must be one this repo holds (api.md 3.4.3). Nothing is
        // written when any is unknown, and every refused path is listed.
        $held = $this->blobs[$namespace.'/'.$repo] ?? [];
        $unknown = [];

        foreach ($changes as $change) {
            if ($change->isReference() && ! isset($held[(string) $change->oid])) {
                $unknown[] = ['path' => $change->path, 'oid' => (string) $change->oid];
            }
        }

        if ($unknown !== []) {
            throw new UnknownObject(
                'The repo does not hold these objects as blobs reachable from a ref; send their bytes.',
                'unknown_object',
                ['paths' => $unknown],
            );
        }

        $next = $current;

        foreach ($changes as $change) {
            if ($change->delete) {
                unset($next[$change->path]);

                continue;
            }

            $next[$change->path] = $change->isReference()
                ? (string) $held[(string) $change->oid]
                : $change->payload?->contents() ?? (string) $change->content;
        }

        $parent = $this->tips[$key] ?? '';
        $commit = $this->recordCommit(
            namespace: $namespace,
            repo: $repo,
            ref: $ref,
            message: $message,
            author: $author,
            parents: $parent === '' ? [] : [$parent],
            files: $next,
            log: $changes,
        );

        $files = [];

        foreach ($changes as $change) {
            if ($change->delete) {
                $files[] = new CommittedFile($change->path, null, 0, '100644', deleted: true);

                continue;
            }

            $contents = $next[$change->path];

            $files[] = new CommittedFile($change->path, self::oid($contents), strlen($contents), $change->mode ?? '100644');
        }

        return new CommitResult($commit, $ref, $files, $this->tips[$key]);
    }

    /**
     * The raw streamed write (api.md 3.4.2): the payload's bytes are the file.
     *
     * The size and the Git blob id are computed from the payload exactly as the
     * server does, and the bytes are remembered as uploaded, so a test can tell
     * a streamed write from one that travelled inside a commit body.
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
    ): CommitResult {
        return $this->backoff->run(function () use ($namespace, $repo, $ref, $path, $payload, $expectedOid, $message, $author, $mode): CommitResult {
            $this->busyCheck();

            $contents = $payload->contents();

            if (Payload::blobOid($contents) !== $payload->oid()) {
                throw new ValidationFailed('The payload changed while it was being sent.', 'validation_failed');
            }

            $this->uploads[$path][] = strlen($contents);

            return $this->commitOnce(
                $namespace,
                $repo,
                $ref,
                $message,
                $author,
                [Change::put($path, $expectedOid, $contents, $mode)],
            );
        });
    }

    /**
     * The subset of blobs this repo holds (api.md 3.4.3).
     *
     * The fake keeps every blob any of its commits wrote, which is the
     * reachable set it can answer from; the real server also excludes blobs no
     * ref reaches, and the write path is what settles the difference.
     */
    public function presentObjects(string $namespace, string $repo, array $oids): array
    {
        $held = $this->blobs[$namespace.'/'.$repo] ?? [];

        $present = [];
        $seen = [];

        foreach ($oids as $oid) {
            if (isset($seen[$oid])) {
                continue;
            }

            $seen[$oid] = true;

            if (isset($held[$oid])) {
                $present[] = $oid;
            }
        }

        return $present;
    }

    /**
     * Answer the next `$times` write attempts with 429 uploads_busy.
     *
     * The fake is the transport as well as the server, so it also owns the
     * client's bounded retry: a write made while busy is retried with the same
     * request, which is what api.md 3.4.4 asks a client to do.
     */
    public function simulateBusy(int $times = 1, int $retryAfter = 1): static
    {
        $this->busy = max(0, $times);
        $this->busyRetryAfter = max(1, $retryAfter);

        return $this;
    }

    /**
     * Whether a raw PUT carried this path's bytes (rather than a commit body).
     */
    public function hasUploaded(string $path): bool
    {
        return ($this->uploads[$path] ?? []) !== [];
    }

    /**
     * The sizes of the raw PUTs that carried this path.
     *
     * @return list<int>
     */
    public function uploadsOf(string $path): array
    {
        return $this->uploads[$path] ?? [];
    }

    /**
     * Whether a commit named this path's blob instead of sending its bytes.
     */
    public function hasReferenced(string $path): bool
    {
        foreach ($this->commits as $commit) {
            foreach ($commit['changes'] as $change) {
                if ($change->path === $path && $change->isReference()) {
                    return true;
                }
            }
        }

        return false;
    }

    // --- Client: history ---------------------------------------------------

    public function log(
        string $namespace,
        string $repo,
        string $ref,
        ?string $path = null,
        ?string $cursor = null,
        int $limit = 200,
    ): CommitPage {
        $this->lastRepoKey = $this->key($namespace, $repo, $ref);

        $oids = $this->reachable($this->resolveOid($namespace, $repo, $ref));

        if ($path !== null && $path !== '') {
            $oids = array_values(array_filter(
                $oids,
                fn (string $oid) => $this->touches($oid, $path),
            ));
        }

        $offset = $cursor === null || $cursor === '' ? 0 : max(0, (int) $cursor);

        if ($limit <= 0) {
            $limit = 50;
        }

        $slice = array_slice($oids, $offset, $limit);
        $next = count($oids) > $offset + count($slice) ? (string) ($offset + count($slice)) : null;

        $commits = array_map(fn (string $oid) => $this->commitObject($oid), $slice);

        return new CommitPage($commits, $next);
    }

    public function commit(string $namespace, string $repo, string $ref): Commit
    {
        return $this->commitObject($this->resolveOid($namespace, $repo, $ref));
    }

    public function diff(string $namespace, string $repo, string $from, string $to, ?string $path = null): Diff
    {
        $fromOid = $this->resolveOid($namespace, $repo, $from);
        $toOid = $this->resolveOid($namespace, $repo, $to);

        $fromFiles = $this->filesOf($fromOid);
        $toFiles = $this->filesOf($toOid);

        $paths = array_unique(array_merge(array_keys($fromFiles), array_keys($toFiles)));
        sort($paths);

        $files = [];

        foreach ($paths as $file) {
            if (! $this->matches($file, $path)) {
                continue;
            }

            $before = $fromFiles[$file] ?? null;
            $after = $toFiles[$file] ?? null;

            if ($before === $after) {
                continue;
            }

            $files[] = $this->fileDiff($file, $before, $after);
        }

        return new Diff($fromOid, $toOid, $files);
    }

    public function diffText(string $namespace, string $repo, string $from, string $to, ?string $path = null): string
    {
        $diff = $this->diff($namespace, $repo, $from, $to, $path);
        $out = '';

        foreach ($diff->files as $file) {
            $out .= "diff --git a/{$file->path} b/{$file->path}\n";

            if ($file->binary) {
                $out .= "Binary files differ\n";

                continue;
            }

            foreach ($file->hunks as $hunk) {
                $out .= "--- a/{$file->path}\n+++ b/{$file->path}\n";
                $out .= "@@ -{$hunk->oldStart},{$hunk->oldLines} +{$hunk->newStart},{$hunk->newLines} @@\n";

                foreach ($hunk->lines as $line) {
                    $out .= $line."\n";
                }
            }
        }

        return $out;
    }

    // --- Client: sessions and merges ---------------------------------------

    public function findSession(string $namespace, string $repo, string $ref): ?Session
    {
        $name = $this->shortRef($ref);
        $repoName = $namespace.'/'.$repo;

        foreach (array_reverse($this->sessions, true) as $session) {
            if ($session['repo'] === $repoName && $session['ref'] === $name) {
                return Session::fromArray($this->sessionView($session));
            }
        }

        return null;
    }

    public function createSession(string $namespace, string $repo, string $from, string $ref): Session
    {
        $name = $this->shortRef($ref);

        if ($this->findSession($namespace, $repo, $name) !== null) {
            throw new Conflict('That ref already exists.', 'ref_exists', ['ref' => $name]);
        }

        $branchKey = $this->key($namespace, $repo, $name);

        if (array_key_exists($branchKey, $this->tips)) {
            throw new Conflict('That ref already exists.', 'ref_exists', ['ref' => $name]);
        }

        $base = $this->resolveSessionBase($namespace, $repo, $from);

        $this->files[$branchKey] = $base === '' ? [] : $this->filesOf($base);
        $this->tips[$branchKey] = $base;
        $this->modified[$branchKey] = [];
        $this->changed[$branchKey] = $this->changed[$this->key($namespace, $repo, $from)] ?? [];

        $this->clock++;

        $session = [
            'id' => $this->nextId(),
            'repo' => $namespace.'/'.$repo,
            'ref' => $name,
            'state' => Session::OPEN,
            'base_oid' => $base,
            'tip' => $base,
            'merge_commit_oid' => null,
            'created_at' => $this->clock,
        ];

        $this->sessions[$session['id']] = $session;

        return Session::fromArray($this->sessionView($session));
    }

    public function merge(string $namespace, string $repo, string $sessionId, string $target): MergeResult
    {
        $session = $this->sessionRow($sessionId);
        $target = trim($target) === '' ? 'main' : $this->shortRef(trim($target));

        if ($session['state'] === Session::MERGING) {
            throw new Conflict('The session already has a merge in progress.', 'merge_in_progress', [
                'merge_id' => $this->pending[$sessionId] ?? '',
                'state' => Session::MERGING,
            ]);
        }

        if ($session['state'] !== Session::OPEN && $session['state'] !== Session::MERGED) {
            throw new Conflict('The session is not open.', 'session_not_open', [
                'session_id' => $sessionId,
                'state' => $session['state'],
            ]);
        }

        $targetKey = $this->key($namespace, $repo, $target);
        $targetTip = $this->tips[$targetKey] ?? '';

        if ($targetTip === '') {
            throw new NotFound("Ref [{$target}] does not exist.", ['resource' => 'ref', 'id' => $target]);
        }

        $sessionTip = $this->tips[$this->key($namespace, $repo, $session['ref'])] ?? '';

        if ($sessionTip === '') {
            throw new ValidationFailed('The session has no commits to merge.', 'validation_failed', [
                'fields' => [['field' => 'session', 'error' => 'the session has no commits to merge']],
            ]);
        }

        $this->clock++;
        $mergeId = $this->nextId();

        $row = [
            'id' => $mergeId,
            'session' => $sessionId,
            'state' => 'merged',
            'mode' => 'merge',
            'target' => $target,
            'target_oid' => $targetTip,
            'target_tip' => $targetTip,
            'session_oid' => $sessionTip,
            'session_tip' => $sessionTip,
            'merge_base_oid' => $targetTip,
            'merge_oid' => null,
            'fast_forward' => false,
            'already_merged' => false,
            'strategy' => null,
            'commit' => null,
            'created_at' => $this->clock,
            'resolved_at' => null,
            'stages' => [],
            'merged' => [],
        ];

        if ($sessionTip === $targetTip || $this->isAncestor($sessionTip, $targetTip)) {
            $row['state'] = 'already_merged';
            $row['already_merged'] = true;
            $this->merges[$mergeId] = $row;
            $this->sessions[$sessionId]['state'] = Session::MERGED;

            return MergeResult::fromArray($row);
        }

        if ($this->isAncestor($targetTip, $sessionTip)) {
            $row['fast_forward'] = true;
            $row['merge_oid'] = $sessionTip;
            $row['target_tip'] = $sessionTip;
            $row['commit'] = ['oid' => $sessionTip];
            $this->merges[$mergeId] = $row;
            $this->sessions[$sessionId]['state'] = Session::MERGED;
            $this->sessions[$sessionId]['merge_commit_oid'] = $sessionTip;
            $this->moveBranchTo($targetKey, $sessionTip);

            return MergeResult::fromArray($row);
        }

        $base = $this->mergeBase($targetTip, $sessionTip);

        [$tree, $conflicts, $stages] = $this->threeWay(
            $this->filesOf($base),
            $this->filesOf($targetTip),
            $this->filesOf($sessionTip),
        );

        $row['merge_base_oid'] = $base;
        $row['merged'] = $tree;
        $row['stages'] = $stages;

        if ($conflicts !== []) {
            $payload = [
                'merge_id' => $mergeId,
                'session' => $sessionId,
                'target' => $target,
                'target_oid' => $targetTip,
                'session_oid' => $sessionTip,
                'merge_base_oid' => $base,
                'conflicts' => $conflicts,
                'resolution' => [
                    'strategy' => 'per_path',
                    'accepts' => ['ours', 'theirs', 'content', 'delete'],
                    'default_side_of_ours' => 'target',
                ],
            ];

            $row['state'] = 'conflicted';
            $row['conflicts'] = $payload;
            $this->merges[$mergeId] = $row;
            $this->sessions[$sessionId]['state'] = Session::MERGING;
            $this->pending[$sessionId] = $mergeId;

            throw new MergeConflict(
                'Merge produced '.count($conflicts).' conflict'.(count($conflicts) === 1 ? '' : 's').'; resolve them on the session branch.',
                'merge_conflict',
                $payload,
            );
        }

        $commit = $this->recordCommit(
            namespace: $namespace,
            repo: $repo,
            ref: $target,
            message: 'Merge session '.$sessionId.' into '.$target,
            author: null,
            parents: [$targetTip, $sessionTip],
            files: $tree,
            kind: 'merge',
        );

        $row['merge_oid'] = $commit;
        $row['target_tip'] = $commit;
        $row['commit'] = ['oid' => $commit];
        $this->merges[$mergeId] = $row;
        $this->sessions[$sessionId]['state'] = Session::MERGED;
        $this->sessions[$sessionId]['merge_commit_oid'] = $commit;

        return MergeResult::fromArray($row);
    }

    public function resolveMerge(
        string $namespace,
        string $repo,
        string $sessionId,
        string $mergeId,
        array $resolutions,
        ?string $message = null,
    ): MergeResult {
        $session = $this->sessionRow($sessionId);

        if ($session['state'] !== Session::MERGING) {
            throw new Conflict('The session has no merge to resolve.', 'merge_not_pending', [
                'session_id' => $sessionId,
                'state' => $session['state'],
            ]);
        }

        $row = $this->mergeRow($sessionId, $mergeId);

        if (($row['state'] ?? '') !== 'conflicted') {
            throw new Conflict('The merge is not conflicted.', 'merge_not_pending', [
                'merge_id' => $mergeId,
                'state' => $row['state'] ?? '',
            ]);
        }

        /** @var array<string, array{base: ?string, ours: ?string, theirs: ?string}> $stages */
        $stages = $row['stages'];

        foreach (array_keys($resolutions) as $path) {
            if (! isset($stages[$path])) {
                throw new ValidationFailed('Not a conflicted path: '.$path, 'validation_failed', [
                    'fields' => [['field' => 'paths', 'error' => 'not a conflicted path: '.$path]],
                ]);
            }
        }

        $unanswered = array_values(array_diff(array_keys($stages), array_keys($resolutions)));

        if ($unanswered !== []) {
            throw new ValidationFailed('Every conflicted path must be resolved in one call.', 'unresolved_paths', [
                'unanswered_paths' => $unanswered,
            ]);
        }

        $tree = $row['merged'];

        foreach ($resolutions as $path => $resolution) {
            $stage = $stages[$path];

            $chosen = match ($resolution) {
                'ours' => $stage['ours'],
                'theirs' => $stage['theirs'],
                'delete' => null,
                default => $resolution,
            };

            if ($chosen === null) {
                unset($tree[$path]);

                continue;
            }

            $tree[$path] = $chosen;
        }

        $message ??= 'Merge '.$row['target'].' into session '.$sessionId;

        $commit = $this->recordCommit(
            namespace: $namespace,
            repo: $repo,
            ref: $session['ref'],
            message: $message,
            author: null,
            parents: [$row['session_oid'], $row['target_oid']],
            files: $tree,
            kind: 'resolve',
        );

        $row['state'] = 'resolved';
        $row['merge_oid'] = $commit;
        $row['session_tip'] = $commit;
        $row['target_tip'] = $row['target_oid'];
        $row['commit'] = ['oid' => $commit];
        $row['resolved_at'] = ++$this->clock;
        $this->merges[$mergeId] = $row;

        $this->sessions[$sessionId]['state'] = Session::OPEN;
        $this->sessions[$sessionId]['tip'] = $commit;
        $this->sessions[$sessionId]['merge_commit_oid'] = $commit;
        unset($this->pending[$sessionId]);

        return MergeResult::fromArray($row);
    }

    public function abortMerge(string $namespace, string $repo, string $sessionId, ?string $mergeId = null): Session
    {
        $session = $this->sessionRow($sessionId);

        if ($session['state'] !== Session::MERGING) {
            throw new Conflict('The session has no merge to abort.', 'merge_not_pending', [
                'session_id' => $sessionId,
                'state' => $session['state'],
            ]);
        }

        $mergeId ??= $this->pending[$sessionId] ?? '';

        if (! isset($this->merges[$mergeId])) {
            throw new Conflict('The session has no merge to abort.', 'merge_not_pending', [
                'session_id' => $sessionId,
                'state' => $session['state'],
            ]);
        }

        $this->merges[$mergeId]['state'] = 'aborted';
        $this->merges[$mergeId]['deleted_at'] = ++$this->clock;
        $this->sessions[$sessionId]['state'] = Session::OPEN;
        unset($this->pending[$sessionId]);

        return Session::fromArray($this->sessionView($this->sessions[$sessionId]));
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

            $this->commitOnce(
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

    /**
     * Whether a commit carries a `Reverts: <oid>` trailer.
     */
    public function hasReverted(string $oid): bool
    {
        foreach ($this->commits as $commit) {
            if (preg_match('/^Reverts: '.preg_quote($oid, '/').'$/m', $commit['message']) === 1) {
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

    // --- Internals ---------------------------------------------------------

    /**
     * Add a commit to the graph and move its branch to it.
     *
     * @param  list<string>  $parents
     * @param  array<string, string>  $files
     * @param  list<Change>  $log
     */
    private function recordCommit(
        string $namespace,
        string $repo,
        string $ref,
        string $message,
        ?Author $author,
        array $parents,
        array $files,
        array $log = [],
        string $kind = 'api',
    ): string {
        $key = $this->key($namespace, $repo, $ref);
        $before = $this->files[$key] ?? [];

        $this->clock++;
        $this->sequence++;

        $oid = sha1(implode("\n", [
            'fake-commit',
            $kind,
            (string) $this->clock,
            (string) $this->sequence,
            implode(',', $parents),
            $message,
            $author?->name ?? '',
            $author?->email ?? '',
            json_encode($files),
        ]));

        $this->graph[$oid] = [
            'oid' => $oid,
            'repo' => $namespace.'/'.$repo,
            'ref' => $ref,
            'parents' => $parents,
            'files' => $files,
            'message' => $message,
            'author' => $author ?? new Author('source', 'source@localhost'),
            'at' => $this->clock,
            'seq' => $this->sequence,
            'kind' => $kind,
        ];

        $this->commits[] = [
            'repo' => $namespace.'/'.$repo,
            'ref' => $ref,
            'message' => $message,
            'author' => $author,
            'changes' => $log,
            'commit' => $oid,
        ];

        $this->tips[$key] = $oid;
        $this->files[$key] = $files;

        // Every blob any commit of this repo wrote is what the fake answers
        // objects/present from.
        foreach ($files as $contents) {
            $this->blobs[$namespace.'/'.$repo][self::oid($contents)] = $contents;
        }

        $actor = new ChangedBy(
            commitOid: $oid,
            tokenId: 'fake-token',
            actor: $author?->name ?? 'source',
            at: gmdate('Y-m-d\TH:i:s.000\Z', $this->clock),
        );

        $touched = array_unique(array_merge(array_keys($before), array_keys($files)));

        foreach ($touched as $path) {
            if (($before[$path] ?? null) === ($files[$path] ?? null)) {
                continue;
            }

            $this->changed[$key][$path] = $actor;

            if (isset($files[$path])) {
                $this->modified[$key][$path] = $this->clock;
            } else {
                unset($this->modified[$key][$path]);
            }
        }

        return $oid;
    }

    /**
     * Move a branch without making a commit (a fast-forward).
     */
    private function moveBranchTo(string $key, string $oid): void
    {
        $this->tips[$key] = $oid;
        $this->files[$key] = $this->filesOf($oid);
    }

    /**
     * Resolve a ref or a commit id to a commit id.
     */
    private function resolveOid(string $namespace, string $repo, string $ref): string
    {
        $key = $this->key($namespace, $repo, $ref);

        if (array_key_exists($key, $this->tips)) {
            if ($this->tips[$key] === '') {
                throw new Conflict('The branch has no commits yet.', 'not_found', ['resource' => 'ref']);
            }

            return $this->tips[$key];
        }

        if (isset($this->graph[$ref])) {
            return $ref;
        }

        throw new NotFound("Ref [{$ref}] does not exist.", ['resource' => 'ref', 'id' => $ref]);
    }

    /**
     * The base a session branch forks from. An unborn branch is a valid base:
     * the session starts empty.
     */
    private function resolveSessionBase(string $namespace, string $repo, string $from): string
    {
        $key = $this->key($namespace, $repo, $from);

        if (array_key_exists($key, $this->tips)) {
            return $this->tips[$key];
        }

        if (isset($this->graph[$from])) {
            return $from;
        }

        if ($from === '' || $from === 'main') {
            return '';
        }

        throw new NotFound("Ref [{$from}] does not exist.", ['resource' => 'ref', 'id' => $from]);
    }

    /**
     * @return array<string, string>
     */
    private function filesOf(string $oid): array
    {
        return $this->graph[$oid]['files'] ?? [];
    }

    /**
     * The tree a read sees: a branch's working tree, or a commit's snapshot
     * when the ref is a commit id (which is what `at()` pins).
     *
     * @return array<string, string>
     */
    private function treeFor(string $namespace, string $repo, string $ref): array
    {
        $key = $this->key($namespace, $repo, $ref);

        if (array_key_exists($key, $this->files)) {
            return $this->files[$key];
        }

        return $this->filesOf($ref);
    }

    /**
     * Every commit reachable from one, newest first.
     *
     * @return list<string>
     */
    private function reachable(string $oid): array
    {
        $seen = [];
        $stack = [$oid];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current]) || ! isset($this->graph[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach (array_reverse($this->graph[$current]['parents']) as $parent) {
                $stack[] = $parent;
            }
        }

        $oids = array_keys($seen);

        usort($oids, function (string $a, string $b): int {
            $left = $this->graph[$a];
            $right = $this->graph[$b];

            return [$right['at'], $right['seq']] <=> [$left['at'], $left['seq']];
        });

        return $oids;
    }

    /**
     * Whether a commit changed the path (or anything under it).
     */
    private function touches(string $oid, string $path): bool
    {
        $node = $this->graph[$oid];
        $mine = $this->slicePath($node['files'], $path);

        if ($node['parents'] === []) {
            return $mine !== [];
        }

        foreach ($node['parents'] as $parent) {
            if ($this->slicePath($this->filesOf($parent), $path) != $mine) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function slicePath(array $files, string $path): array
    {
        $out = [];

        foreach ($files as $file => $contents) {
            if ($this->matches($file, $path)) {
                $out[$file] = $contents;
            }
        }

        return $out;
    }

    /**
     * Whether a repo path is the given path or lives under it.
     */
    private function matches(string $file, ?string $path): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        return $file === $path || str_starts_with($file, rtrim($path, '/').'/');
    }

    private function commitObject(string $oid): Commit
    {
        $node = $this->graph[$oid] ?? throw new NotFound("Commit [{$oid}] does not exist.", ['resource' => 'commit']);

        return Commit::fromArray([
            'oid' => $node['oid'],
            'parents' => $node['parents'],
            'message' => $node['message'],
            'author' => $node['author']->toArray(),
            'committer' => $node['author']->toArray(),
            'created_at' => gmdate('Y-m-d\TH:i:s.000\Z', $node['at']),
        ]);
    }

    private function fileDiff(string $path, ?string $before, ?string $after): DiffFile
    {
        $oldLines = $before === null ? [] : explode("\n", rtrim($before, "\n"));
        $newLines = $after === null ? [] : explode("\n", rtrim($after, "\n"));

        if ($before === null) {
            $status = DiffFile::ADDED;
        } elseif ($after === null) {
            $status = DiffFile::DELETED;
        } else {
            $status = DiffFile::MODIFIED;
        }

        $binary = ($before !== null && preg_match('//u', $before) !== 1)
            || ($after !== null && preg_match('//u', $after) !== 1);

        if ($binary) {
            return new DiffFile($path, $status, binary: true);
        }

        $lines = [];

        foreach ($oldLines as $line) {
            $lines[] = '-'.$line;
        }

        foreach ($newLines as $line) {
            $lines[] = '+'.$line;
        }

        return new DiffFile(
            path: $path,
            status: $status,
            binary: false,
            additions: count($newLines),
            deletions: count($oldLines),
            hunks: [new DiffHunk(
                oldStart: $before === null ? 0 : 1,
                oldLines: count($oldLines),
                newStart: $after === null ? 0 : 1,
                newLines: count($newLines),
                lines: $lines,
            )],
        );
    }

    /**
     * A three-way merge of three trees.
     *
     * @param  array<string, string>  $base
     * @param  array<string, string>  $ours
     * @param  array<string, string>  $theirs
     * @return array{0: array<string, string>, 1: list<array<string, mixed>>, 2: array<string, array{base: ?string, ours: ?string, theirs: ?string}>}
     */
    private function threeWay(array $base, array $ours, array $theirs): array
    {
        $paths = array_unique(array_merge(array_keys($base), array_keys($ours), array_keys($theirs)));
        sort($paths);

        $tree = [];
        $conflicts = [];
        $stages = [];

        foreach ($paths as $path) {
            $b = $base[$path] ?? null;
            $o = $ours[$path] ?? null;
            $t = $theirs[$path] ?? null;

            if ($o === $t) {
                if ($o !== null) {
                    $tree[$path] = $o;
                }

                continue;
            }

            if ($o === $b) {
                if ($t !== null) {
                    $tree[$path] = $t;
                }

                continue;
            }

            if ($t === $b) {
                if ($o !== null) {
                    $tree[$path] = $o;
                }

                continue;
            }

            $stages[$path] = ['base' => $b, 'ours' => $o, 'theirs' => $t];

            $conflicts[] = [
                'path' => $path,
                'kind' => 'content',
                'base' => $this->stage($b),
                'ours' => $this->stage($o),
                'theirs' => $this->stage($t),
                'hunks' => [],
            ];
        }

        return [$tree, $conflicts, $stages];
    }

    /**
     * @return array{oid: string, mode: string}|null
     */
    private function stage(?string $contents): ?array
    {
        return $contents === null ? null : ['oid' => self::oid($contents), 'mode' => '100644'];
    }

    /**
     * The nearest common ancestor of two commits.
     */
    private function mergeBase(string $a, string $b): string
    {
        $ancestors = [];

        foreach ($this->reachable($a) as $index => $oid) {
            $ancestors[$oid] = $index;
        }

        $queue = [$b];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            if (isset($ancestors[$current])) {
                return $current;
            }

            foreach ($this->graph[$current]['parents'] ?? [] as $parent) {
                $queue[] = $parent;
            }
        }

        return '';
    }

    private function isAncestor(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        foreach ($this->reachable($b) as $oid) {
            if ($oid === $a) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? throw new NotFound(
            "Session [{$sessionId}] does not exist.",
            ['resource' => 'session', 'id' => $sessionId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeRow(string $sessionId, string $mergeId): array
    {
        $row = $this->merges[$mergeId] ?? throw new NotFound(
            "Merge [{$mergeId}] does not exist.",
            ['resource' => 'merge', 'id' => $mergeId],
        );

        if ($row['session'] !== $sessionId) {
            throw new NotFound("Merge [{$mergeId}] does not exist.", ['resource' => 'merge', 'id' => $mergeId]);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function sessionView(array $row): array
    {
        $tip = $this->tips[$this->key(
            explode('/', $row['repo'])[0],
            explode('/', $row['repo'])[1] ?? '',
            $row['ref'],
        )] ?? '';

        return [
            'id' => $row['id'],
            'repo' => $row['repo'],
            'ref' => $row['ref'],
            'state' => $row['state'],
            'base_oid' => $row['base_oid'],
            'tip' => $tip === '' ? null : $tip,
            'merge_commit_oid' => $row['merge_commit_oid'],
            'created_at' => gmdate('Y-m-d\TH:i:s.000\Z', $row['created_at']),
        ];
    }

    private function nextId(): string
    {
        return str_pad((string) ++$this->ids, 26, '0', STR_PAD_LEFT);
    }

    private function shortRef(string $ref): string
    {
        $ref = trim($ref);

        return str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
    }

    private function freshContents(string $path): string
    {
        return "Changed outside the disk: {$path} {$this->clock}\n";
    }

    private function runWriteHook(): void
    {
        if ($this->writeHook === null || $this->inHook) {
            return;
        }

        $this->inHook = true;

        try {
            ($this->writeHook)($this);
        } finally {
            $this->inHook = false;
        }
    }

    /**
     * Refuse one write attempt as 429 uploads_busy (api.md 3.4.4).
     */
    private function busyCheck(): void
    {
        if ($this->busy <= 0) {
            return;
        }

        $this->busy--;

        throw new UploadsBusy(
            'Upload capacity is busy; retry after the delay.',
            [
                'scope' => 'repo',
                'limit_bytes' => 512 << 20,
                'in_flight_bytes' => 512 << 20,
                'retry_after' => $this->busyRetryAfter,
            ],
            null,
            $this->busyRetryAfter,
        );
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
