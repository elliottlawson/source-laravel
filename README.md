# source-laravel

A Laravel filesystem driver for `source`, the Git-backed file store, so the
store can be used as `Storage::disk('source')`.

Every write is a commit. Plain writes are last-write-wins: the driver reads
the file's version, writes against that version, and retries if another writer
lands in between. The package also exposes the version rules the store is
built on, because some writes should not be last-write-wins.

This is the first slice: the disk, versions, strict writes, batch commits,
authors, metadata and the fake. History, diff, revert and branches are not in
it yet.

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

## Errors

| Exception | When |
| --- | --- |
| `Source\Exceptions\FileChanged` | 409 `file_changed` |
| `Source\Exceptions\MergeConflict` | 409 `merge_conflict` |
| `Source\Exceptions\Conflict` | another 409 (`stale_base`, ...) |
| `Source\Exceptions\FileTooLarge` | 413 (`->limitBytes`, `->actualBytes`) |
| `Source\Exceptions\NotFound` | 404 |
| `Source\Exceptions\Unauthorized` | 401 |
| `Source\Exceptions\Forbidden` | 403 |
| `Source\Exceptions\ValidationFailed` | 422 |
| `Source\Exceptions\RateLimited` | 429 (`->retryAfter`) |

All extend `SourceException`, which carries `->errorCode`, `->details` and
`->requestId`.

The ordinary disk methods honour `throw`: with `throw => false` they return
`false` the way Laravel's do. With `throw => true` a failure surfaces the typed
exception above (a plain `put()` that loses three races throws `FileChanged`).
The extras (`read()`, `version()`, `ifUnchanged()`, `ifMissing()`, `commit()`)
always throw typed exceptions.

## Testing

```php
use Source\Source;

Source::fake();                                    // in-memory server with the same rules

$disk = Storage::disk('source');
$disk->put('notes/plan.md', $plan);

Source::assertCommitted('Create notes/plan.md');
Source::assertWritten('notes/plan.md');

Source::fake()->simulateChange('notes/plan.md');   // the next strict write on it throws FileChanged
```

The fake replaces the transport, not the driver, so retries, version filling
and all-or-nothing batches are the real code. Object ids are real Git blob ids
(`sha1("blob <len>\0<content>")`).

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
vendor/bin/pest
```

Without those variables the live tests are skipped.

## Notes

- A plain write costs one extra round trip (a HEAD for the current version)
  before its commit.
- Writes currently travel as JSON (`content` for UTF-8, `content_base64`
  otherwise). The single place to change to raw-bytes streaming is
  `Source\Http\HttpClient::sendChanges()`.
