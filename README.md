# source-laravel

A Laravel filesystem driver for `source`, the Git-backed file store, so the
store can be used as `Storage::disk('source')`.

Every write is a commit. Plain writes are last-write-wins: the driver reads
the file's version, writes against that version, and retries if another writer
lands in between. The package also exposes the version rules the store is
built on, because some writes should not be last-write-wins.

This is the disk, versions, strict writes, batch commits, authors, metadata,
history, reading the past, diffs, reverts, repos, branches and the fake.

## Setup

```php
// config/filesystems.php
'disks' => [
    'source' => [
        'driver'    => 'source',
        'url'       => env('SOURCE_URL'),        // e.g. http://127.0.0.1:8470
        'token'     => env('SOURCE_TOKEN'),
        'namespace' => env('SOURCE_NAMESPACE'),
        'repo'      => env('SOURCE_REPO'),
        'branch'    => 'main',
        'throw'     => true,
    ],
],
```

| Key | Required | Meaning |
| --- | --- | --- |
| `driver` | yes | `source`. |
| `url` | yes | Base URL of the server. `/v1` is appended. |
| `token` | yes in practice | The scoped token sent as `Authorization: Bearer`. |
| `namespace` | yes | The namespace the repo lives in. |
| `repo` | yes | The repo name. |
| `branch` | no | Defaults to `main`. |
| `throw` | no | Defaults to **true** here, unlike Laravel's own disks. Set it to `false` for Laravel's "return false" behaviour. |
| `timeout` | no | Request timeout in seconds. Defaults to 30. |
| `upload_timeout` | no | Timeout in seconds for writes that carry file bytes (single-file `PUT` and blob uploads). Defaults to 600. |
| `inline_threshold` | no | A batch change smaller than this many bytes travels inside the commit body instead of its own blob upload. Defaults to 65536 (64 KiB). |
| `upload_concurrency` | no | Blob uploads in flight at the start of a commit. Defaults to 4. |
| `upload_concurrency_max` | no | The most blob uploads in flight after a fast wave. Defaults to 16. |

The provider registers the driver with `Storage::extend('source', ...)`; package
discovery picks it up. The disk is a
`Source\SourceFilesystem` (a subclass of `Illuminate\Filesystem\FilesystemAdapter`)
over a Flysystem adapter (`Source\Flysystem\SourceAdapter`), so anything that
accepts a Laravel disk accepts this one.

## The ordinary disk

```php
$disk = Storage::disk('source');

$disk->put('notes/plan.md', $text);
$disk->get('notes/plan.md');
$disk->exists('notes/plan.md');
$disk->delete('notes/old.md');
$disk->files('notes');
$disk->move('a.md', 'b.md');
$request->file('report')->store('uploads', 'source');
```

Each write is one commit with a generated message: `Create notes/plan.md`,
`Update notes/plan.md`, `Delete notes/old.md`, `Move a.md to b.md`,
`Copy a.md to b.md`. A batch commit's message is the one you pass.

`move()` is a single commit: the destination is created and the source deleted,
all or nothing. `deleteDirectory()` is one commit for every file under the
prefix.

## Versions and strict writes

A version is the file's Git blob id. Identical content is the same version, so
a file that changes and changes back has its old version again.

```php
$file = $disk->read('notes/plan.md');   // SourceFile: ->contents, ->version, ->size, ->path
$disk->version('notes/plan.md');        // just the version, or null

$disk->ifUnchanged($file->version)->put('notes/plan.md', $edited);
$disk->ifUnchanged($file->version)->delete('notes/plan.md');
$disk->ifMissing()->put('notes/new.md', $text);   // create only
```

A strict write gets one attempt. If the file moved, it throws
`Source\Exceptions\FileChanged`:

```php
use Source\Exceptions\FileChanged;

try {
    $disk->ifUnchanged($file->version)->put('notes/plan.md', $edited);
} catch (FileChanged $e) {
    $e->paths;                         // every path that moved
    $e->changedBy('notes/plan.md');    // ->commitOid, ->tokenId, ->actor, ->at
}
```

The exception never carries the new contents or the current version, so the
caller has to read the file again. It also renders as a 409 JSON response if it
escapes a controller, because it implements `Responsable`.

## Several files, one commit

```php
$disk->commit('Draft Q3 plan', function ($files) use ($file) {
    $files->ifUnchanged($file->version)->put('notes/plan.md', $plan);
    $files->put('notes/summary.md', $summary);          // plain: version read at send time
    $files->delete('notes/old-plan.md');                // plain
    $files->ifMissing()->put('notes/new.md', $newFile); // create only
});
```

Nothing is sent until the closure returns, and then everything goes as one
commit, all or nothing. Reads inside the closure (`$files->get($path)`,
`$files->read($path)`) return the stored file, not the pending write. An
exception thrown inside the closure sends nothing.

Plain and strict changes can be mixed. The driver fills in current versions for
the plain ones and retries the whole batch on `file_changed` only when **every**
mismatched path was a plain change. One stale strict change refuses the batch
and throws `FileChanged`, exactly as the server does.

## Who and why

```php
$disk->as($user)->withMeta(['run' => $runId, 'conversation' => $convId])->put(...);

$disk->commit('Message', fn ($files) => ..., author: $user, meta: [...]);
```

`as()` and `withMeta()` return configured copies; the shared disk is not
mutated. The author defaults to the signed-in user through the auth guard, and
an app can replace that:

```php
Source::resolveAuthorUsing(fn () => auth()->user());
```

Metadata is recorded as Git commit trailers appended to the message after a
blank line (`Key: value`). The author goes in the commit's `author` field.

## History and the past

```php
$disk->history('notes/plan.md');   // Collection of commits for one path
$disk->history();                  // whole repo, paginated

$disk->at($commit)->get('notes/plan.md');
$disk->at('2026-11-01')->files('notes');    // the tree as it was
$disk->diff($from, $to, 'notes/plan.md');
```

`history()` returns a `LazyCollection` of `Source\Commit`, newest first. Pages
are fetched as you iterate, so `->first()` costs one request and stopping early
fetches nothing more. Whole-repo history follows the server's `next_cursor`;
a path filter uses the log's `path` parameter. Each commit carries `->oid`,
`->message` (with the trailers stripped), `->meta` (the trailer block parsed
back into an array), `->author`, `->at` (a `CarbonImmutable`), `->parents`, and
`->subject()`.

`at()` returns a read-only disk pinned to a commit, a ref, or a date. Every
ordinary read works on it (`get`, `exists`, `files`, `directories`, ...); every
write throws `Source\Exceptions\ReadOnlyDisk`.

A date means the last commit on this branch at or before that time, so
`at('2026-11-01')` is the tree at the start of the day. Bare dates are parsed
in the app's timezone. A *version* (a blob id) is not a point in tree history:
`at($version)` is refused with an `InvalidArgumentException` saying so, because
the same file can have the same blob in many commits. Pass the commit id.

`diff()` returns `Source\Diff`: `->from`, `->to` (the commit ids the server
resolved), and `->files` of `Source\DiffFile` (`->path`, `->status`, `->oldPath`
for a rename, `->binary`, `->additions`, `->deletions`, `->hunks`). Statuses are
`added`, `modified`, `deleted`, `renamed`, `copied`, `mode_changed` and
`type_changed`. `diffText()` returns the same diff as unified text, and
`->path` limits either to a file or a directory.

## Revert

```php
$disk->revert($commit);
$disk->revert($commit, 'Roll back the Q3 draft');
```

A revert is one new commit that writes each file back to its version from the
commit's first parent, expecting the version the commit produced: a modified
file gets its old contents back, a deleted file is recreated, a file the commit
added is deleted. The default message is `Revert "<subject>"` with a
`Reverts: <oid>` trailer.

It is the flip side of a strict write: if **any** of those files changed since,
the whole revert is refused with `FileChanged`, nothing is retried, and nothing
lands partially. A root commit and a merge commit are refused up front with
`Source\Exceptions\CannotRevert`, because neither has one earlier tree to go
back to.

## Repos

```php
$disk->repo('acme/alice')->put('notes/plan.md', $text);
```

`repo()` returns a copy of the same disk — connection, token, branch, author,
metadata — pointed at another repo in the same namespace. The original disk is
not mutated.

## Errors

| Exception | When |
| --- | --- |
| `Source\Exceptions\FileChanged` | 409 `file_changed` |
| `Source\Exceptions\MergeConflict` | 409 `merge_conflict` (`->paths()`, `->resolve()`, `->abort()`) |
| `Source\Exceptions\Conflict` | another 409 (`stale_base`, ...) |
| `Source\Exceptions\FileTooLarge` | 413 (`->limitBytes`, `->actualBytes`) |
| `Source\Exceptions\NotFound` | 404 |
| `Source\Exceptions\ReadOnlyDisk` | a write on an `at()` disk |
| `Source\Exceptions\CannotRevert` | a root or merge commit given to `revert()` |
| `Source\Exceptions\NoSession` | `mergeInto()` on a branch with no session |
| `Source\Exceptions\Unauthorized` | 401 |
| `Source\Exceptions\Forbidden` | 403 |
| `Source\Exceptions\ValidationFailed` | 422 |
| `Source\Exceptions\UnknownObject` | 422 `unknown_object` (`->paths()`), after the one retry with fresh uploads |
| `Source\Exceptions\OidMismatch` | 422 `oid_mismatch` (`->expectedOid`, `->actualOid`), never retried |
| `Source\Exceptions\StagingFull` | 409 `staging_full` (`->scope`, `->limitBytes`, `->stagedBytes`, `->nextExpiryAt`), never retried |
| `Source\Exceptions\RateLimited` | 429 `rate_limited` (`->retryAfter`) |
| `Source\Exceptions\UploadsBusy` | 429 `uploads_busy` (`->retryAfter`, `->scope`), after the bounded retries |

All extend `SourceException`, which carries `->errorCode`, `->details` and
`->requestId`.

The ordinary disk methods honour `throw`: with `throw => false` they return
`false` the way Laravel's do. With `throw => true` a failure surfaces the typed
exception above (a plain `put()` that loses three races throws `FileChanged`).
The extras (`read()`, `version()`, `ifUnchanged()`, `ifMissing()`, `commit()`)
always throw typed exceptions.

## Advanced: branches

Branches are Git-style: a branch is a session on the server, writes on it are
ordinary commits, and merging is a real three-way merge. Most apps never need
them, which is why they are not in the getting-started section.

```php
$draft = $disk->branch('draft');       // creates a session branch from main if needed
$draft->put('notes/plan.md', $text);   // one commit on draft; main is untouched
$draft->mergeInto('main');             // clean merge, fast-forward, or already merged
```

`branch()` returns a disk on that branch. It finds the branch's session with
`GET /sessions?ref=` and creates one with `POST /sessions` when there is none,
forking from the current disk's branch. The disk keeps the session id, which is
what `mergeInto()` uses. Writes on the branch go through the same write paths as
everywhere else, so the per-file version rules are unchanged.

`mergeInto()` returns a `Source\MergeResult` (`->state`, `->fastForward`,
`->alreadyMerged`, `->mergeOid`, `->targetTip`, ...) for a clean merge, a
fast-forward, an already-merged session, or a strategy-resolved merge. A
conflict throws `Source\Exceptions\MergeConflict`:

```php
use Source\Exceptions\MergeConflict;

try {
    $draft->mergeInto('main');
} catch (MergeConflict $e) {
    $e->paths();       // the conflicted paths
    $e->details;       // the server's conflict payload, exactly as sent
    $e->mergeId();     // the merge to resolve or abort

    // Every path in one call: ours is the target's version, theirs the
    // session's. A contents string is a hand-written resolution.
    $e->resolve([
        'notes/plan.md' => 'theirs',
        'notes/old.md'  => 'delete',
        'notes/new.md'  => "the merged text\n",
    ]);

    // or, to leave the target alone and keep the branch:
    $e->abort();
}
```

Resolving writes the merge commit on the session branch, so the target does not
move until the next `mergeInto()`, which is then a fast-forward. `abort()`
returns the session to `open` with the target untouched.

The disk's own token writes the branch, like every other write. Creating the
session mints a token for the branch, and the server refuses that unless the
disk's token already covers the branch ref; a token scoped only to `main`
therefore cannot use `branch()` at all (403 `forbidden`).

## Testing

```php
use Source\Source;

Source::fake();                                    // in-memory server with the same rules

$disk = Storage::disk('source');
$disk->put('notes/plan.md', $plan);

Source::assertCommitted('Create notes/plan.md');
Source::assertWritten('notes/plan.md');
Source::assertUploaded('notes/plan.md');            // the bytes went as a raw PUT
Source::assertReferenced('notes/summary.md');       // the commit named a blob instead of sending bytes
Source::assertBlobUploaded('assets/big.bin');       // went as its own POST /blobs
Source::assertReverted($commit);                   // a commit carried Reverts: <oid>

Source::fake()->simulateChange('notes/plan.md');   // the next strict write on it throws FileChanged
Source::fake()->simulateBusy(times: 2);            // the next two writes answer 429 uploads_busy
Source::fake()->simulateStagingFull(times: 1);     // the next upload answers 409 staging_full
Source::fake()->expireUploads();                   // staged blobs expire, as after the 24h grace period

Sleep::fake();                                     // keep the 429 back-off instant
```

The fake replaces the transport, not the driver, so retries, version filling
and all-or-nothing batches are the real code. Object ids are real Git blob ids
(`sha1("blob <len>\0<content>")`).

The fake models history too: commits are snapshots in a graph, so `history()`,
`at()`, `diff()` and `revert()` read a real tree, and branches and merges go
through the same rules as the server (a merge conflicts when both sides changed
the same path to different contents since the merge base). Its diff hunks are
whole-file replacements rather than minimal hunks; paths and statuses match.

`Source::fake()` is idempotent: calling it twice returns the same fake, so a
disk resolved after the first call keeps working. Call `Source::restore()` to
stop faking (the test suite does it before every test).

The `tests/Conformance` suite runs the same tests against the fake and against
a live server. The live run is opt-in:

```
SOURCE_TEST_URL=https://source.example \
SOURCE_TEST_TOKEN=asm_tok_... \
SOURCE_TEST_NAMESPACE=acme \
SOURCE_TEST_REPO=workspace \
SOURCE_TEST_BRANCH=main \
SOURCE_TEST_REPO_OTHER=workspace-other \
vendor/bin/pest
```

Without those variables the live tests are skipped. `SOURCE_TEST_REPO_OTHER` is
optional: without it the live `repo()` isolation case is skipped. The branch
cases need a token whose write refs cover the branch names the tests use
(`conformance/...`); with a `main`-only token those live cases are skipped with
a message saying so.

## Notes

- A plain write costs one extra round trip (a HEAD for the current version)
  before its write.
- One file is written as its own stream: the bytes are the body of a raw
  `PUT /files/{ref}/{path}` (api.md 3.4.2), with the version in `If-Match` (or
  `If-None-Match: *`), the size in `Content-Length` and the message in
  `X-Source-Message`. A string, a resource and a PSR-7 stream all work, and a
  file is never read into memory: `$disk->putFileAs(...)` and
  `$disk->writeStream(...)` of a large file cost a buffer, not the file size.
- A delete, or a commit with more than one change, is one `POST /commits`. Every
  put is hashed as a Git blob first, and `POST /objects/present` says which blobs
  the repo already holds (in chunks of 1000). Each file at or over
  `inline_threshold` (64 KiB) whose blob is missing is uploaded first with its
  own `POST /blobs`, streamed and never buffered, and the commit then names it as
  `{path, expected_oid, oid}`. A multi-file commit therefore stays one commit,
  and its body carries only the small files: a request per tiny file costs more
  than it saves. Re-committing unchanged content uploads nothing.
- Blob uploads run in parallel waves: four in flight at the start, one more after
  a faster clean wave, halved after a `429` or a transport error, sixteen at
  most. A `429` waits for the largest `Retry-After` in the wave and retries only
  the refused uploads, bounded, then `UploadsBusy`. A transport/5xx error retries
  that upload, bounded. `409 staging_full` and `422 oid_mismatch` are never
  retried: waiting or resending the same bytes cannot fix either. A stream is
  rewound before every retry, so the second attempt sends the whole file.
- A `422 unknown_object` on the commit means an uploaded blob expired (24 hours)
  or GC took it in the gap; the driver uploads those paths again and retries the
  commit once. A server that predates `POST /blobs` answers the upload with a
  plain 404 (`details.resource: "path"`); the driver remembers that and sends
  every later commit's bytes inline, which is the behaviour the package had
  before the endpoint existed.
- Writes and blob uploads carry no `Idempotency-Key`: with one, the server can't
  refuse a stale version or a busy server before reading the body. A `429`
  (`uploads_busy`, or `rate_limited`) is refused before anything is written, and
  is answered by waiting `Retry-After` and sending the same request again, up to
  four attempts; a `413` is never retried. The wait uses Laravel's `Sleep`, so
  `Sleep::fake()` in a test makes it instant.
- A stream handed to `put()` is detached from the HTTP client after the
  request, so the transport never closes a file the caller still owns.
