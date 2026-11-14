<?php

declare(strict_types=1);

namespace Source\Http;

use Closure;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Source\Author;
use Source\BlobUpload;
use Source\Change;
use Source\Commit;
use Source\CommitPage;
use Source\CommitResult;
use Source\CommittedFile;
use Source\Diff;
use Source\DiffFile;
use Source\DiffHunk;
use Source\Exceptions\OidMismatch;
use Source\Exceptions\SourceException;
use Source\FileStat;
use Source\MergeResult;
use Source\Session;
use Source\SourceFile;
use Source\Support\Payload;
use Source\TreeEntry;

/**
 * The real transport, over Laravel's HTTP client (so Http::fake() works).
 */
final class HttpClient implements Client
{
    private readonly Backoff $backoff;

    /**
     * Runs a pool callback: the one seam a test needs to watch concurrency.
     *
     * @var Closure(callable, int): array
     */
    private readonly Closure $pool;

    /**
     * @param  array{url?: string, token?: string, timeout?: int|float, upload_timeout?: int|float}  $config
     * @param  (Closure(callable, int): array)|null  $pool  replaces Http::pool, for tests
     */
    public function __construct(private readonly array $config, ?Backoff $backoff = null, ?Closure $pool = null)
    {
        $this->backoff = $backoff ?? new Backoff;
        $this->pool = $pool ?? static fn (callable $callback, int $concurrency): array => Http::pool($callback, $concurrency);
    }

    public function head(string $namespace, string $repo, string $ref, string $path): ?FileStat
    {
        $response = $this->request()->head($this->fileUrl($namespace, $repo, $ref, $path));

        if ($this->isMissing($response)) {
            return null;
        }

        $this->ensureSuccess($response);

        $lastModified = $response->header('Last-Modified');

        return new FileStat(
            path: $path,
            version: (string) $response->header('X-Object-Oid'),
            size: (int) $response->header('X-Object-Size'),
            lastModified: $lastModified !== '' ? (strtotime($lastModified) ?: null) : null,
            mode: $response->header('X-Object-Mode') ?: '100644',
        );
    }

    public function get(string $namespace, string $repo, string $ref, string $path): SourceFile
    {
        $response = $this->request()->get($this->fileUrl($namespace, $repo, $ref, $path));

        $this->ensureSuccess($response);

        $contents = $response->body();

        return new SourceFile(
            path: $path,
            contents: $contents,
            version: (string) $response->header('X-Object-Oid'),
            size: (int) ($response->header('X-Object-Size') ?: strlen($contents)),
        );
    }

    public function listTree(string $namespace, string $repo, string $ref, string $path = '', bool $recursive = false): array
    {
        $query = [];

        if ($path !== '') {
            $query[] = 'path='.rawurlencode($path);
        }

        if ($recursive) {
            $query[] = 'recursive=true';
        }

        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/tree/'.rawurlencode($ref));

        if ($query !== []) {
            $url .= '?'.implode('&', $query);
        }

        $response = $this->request()->get($url);

        // A missing path is an empty directory, not an error.
        if ($this->isMissing($response)) {
            return [];
        }

        $this->ensureSuccess($response);

        $entries = [];

        foreach (($response->json('tree.entries') ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                continue;
            }

            $entries[] = new TreeEntry(
                path: $entry['path'],
                type: is_string($entry['type'] ?? null) ? $entry['type'] : TreeEntry::TYPE_FILE,
                version: is_string($entry['oid'] ?? null) ? $entry['oid'] : '',
                size: (int) ($entry['size'] ?? 0),
            );
        }

        return $entries;
    }

    /**
     * The batch write path.
     *
     * Every change carries its bytes (`content`), names a blob the repo holds
     * (`oid`), or deletes a path. Single-file writes do not come through here:
     * they stream over `putFile()`.
     *
     * No `Idempotency-Key`, for the reason given on `putFile()`; with one, the
     * server also reads the whole body before it can answer `429` (3.4.4).
     */
    public function sendChanges(
        string $namespace,
        string $repo,
        string $ref,
        string $message,
        ?Author $author,
        array $changes,
    ): CommitResult {
        $body = [
            'ref' => $ref,
            'message' => $message,
            'changes' => array_values(array_map(fn (Change $change) => $change->toArray(), $changes)),
        ];

        if ($author !== null) {
            $body['author'] = $author->toArray();
        }

        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/commits');

        return $this->backoff->run(function () use ($url, $body): CommitResult {
            $response = $this->request(upload: true)
                ->asJson()
                ->post($url, $body);

            $this->ensureSuccess($response);

            $files = [];

            foreach (($response->json('files') ?? []) as $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }

                $files[] = new CommittedFile(
                    path: $file['path'],
                    version: is_string($file['oid'] ?? null) ? $file['oid'] : null,
                    size: (int) ($file['size'] ?? 0),
                    mode: is_string($file['mode'] ?? null) ? $file['mode'] : '100644',
                    deleted: ($file['oid'] ?? null) === null,
                );
            }

            return new CommitResult(
                commitOid: (string) $response->json('commit.oid'),
                refName: $response->json('ref.name') ?: null,
                files: $files,
                baseOid: $response->json('commit.base_oid') ?: null,
            );
        });
    }

    /**
     * One streamed file write: `PUT .../files/{ref}/{path}` with the file as the
     * body (api.md 3.4.2).
     *
     * Version check, message, author and mode travel in headers, because the
     * body is the file. `Content-Length` is always sent — the server refuses a
     * raw write without it (`411 length_required`) and uses it to refuse an
     * oversize or over-capacity write before reading anything.
     *
     * The payload is rewound per attempt, so a `429` retry or a driver-level
     * retry after `409 file_changed` sends the same bytes again.
     *
     * No `Idempotency-Key`. With one, the server must hash the body before it
     * can recognise a replay, so it skips the pre-body version check and a
     * stale write uploads the whole file to get its 409 (`rawput.go`). The
     * key would buy nothing here: a `429` is refused before anything is
     * written, so resending is safe, and the driver never retries a lost
     * connection. Add a key back if it ever does.
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
        $url = $this->fileUrl($namespace, $repo, $ref, $path);
        $headers = $this->rawHeaders($payload, $expectedOid, $message, $author, $mode);

        return $this->backoff->run(function () use ($url, $payload, $headers): CommitResult {
            try {
                $response = $this->request(upload: true)
                    ->withHeaders($headers)
                    // Guzzle's own `expect` option, so curl sends the header
                    // it knows to wait on before uploading the body: a write
                    // the server refuses (409, 413, 422, 429) costs no bytes.
                    ->withOptions(['expect' => true])
                    ->withBody($payload->body(), 'application/octet-stream')
                    ->send('PUT', $url);
            } finally {
                // Guzzle must not close a stream the caller still owns.
                $payload->release();
            }

            $this->ensureSuccess($response);

            return $this->singleFileResult($response);
        });
    }

    public function presentObjects(string $namespace, string $repo, array $oids): array
    {
        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/objects/present');

        $response = $this->request()->asJson()->post($url, ['oids' => array_values($oids)]);

        $this->ensureSuccess($response);

        $present = [];

        foreach ((array) ($response->json('present') ?? []) as $oid) {
            if (is_string($oid)) {
                $present[] = $oid;
            }
        }

        return $present;
    }

    /**
     * One blob upload (api.md 3.4.5).
     *
     * Streamed, never buffered. `X-Source-Oid` is the oid we computed; the
     * server hashes the same bytes and refuses a mismatch, and the answer is
     * checked against it too. `Expect: 100-continue` lets a refusal (`403`,
     * `413`, `429`, `staging_full`, or "already held") arrive before the body.
     *
     * No `Idempotency-Key`, for the same reason as `putFile()`: the upload is
     * idempotent by content, and a key would make the server read the body
     * before it could refuse a busy upload.
     */
    public function uploadBlob(string $namespace, string $repo, Payload $payload, ?string $oid = null): BlobUpload
    {
        $payload->rewind();

        try {
            $response = $this->blobRequest($this->request(upload: true), $payload, $oid)
                ->send('POST', $this->blobUrl($namespace, $repo));
        } finally {
            // Guzzle must not close a stream the caller still owns.
            $payload->release();
        }

        $this->ensureSuccess($response);

        return $this->blobResult($response, $oid);
    }

    /**
     * One pooled wave of blob uploads (api.md 3.4.5).
     *
     * `Http::pool` is curl multi underneath, so the bodies are in flight at the
     * same time rather than one after another. The pool's concurrency is the
     * caller's decision (`AdaptiveConcurrency`), and every request uses the
     * upload timeout and the same headers as `uploadBlob()`.
     */
    public function uploadBlobs(string $namespace, string $repo, array $blobs, int $concurrency = 4): array
    {
        if ($blobs === []) {
            return [];
        }

        $url = $this->blobUrl($namespace, $repo);

        $callback = function (Pool $pool) use ($blobs, $url): void {
            foreach ($blobs as $blob) {
                $blob->payload->rewind();

                $this->blobRequest($this->configure($pool->as($blob->key), upload: true), $blob->payload, $blob->oid)
                    ->send('POST', $url);
            }
        };

        try {
            $results = ($this->pool)($callback, max(1, $concurrency));
        } finally {
            foreach ($blobs as $blob) {
                $blob->payload->release();
            }
        }

        $uploads = [];

        foreach ($blobs as $blob) {
            $result = $results[$blob->key] ?? null;

            if ($result instanceof Response) {
                try {
                    $this->ensureSuccess($result);
                    $uploads[$blob->key] = $this->blobResult($result, $blob->oid);
                } catch (SourceException $e) {
                    $uploads[$blob->key] = $e;
                }

                continue;
            }

            $uploads[$blob->key] = $result instanceof \Throwable
                ? $result
                : new SourceException('The blob upload returned no response.', 'transport_error');
        }

        return $uploads;
    }

    /**
     * The headers and body of one blob upload. The body is the file itself.
     */
    private function blobRequest(PendingRequest $request, Payload $payload, ?string $oid): PendingRequest
    {
        $headers = ['Content-Length' => (string) $payload->size()];

        if ($oid !== null) {
            $headers['X-Source-Oid'] = $oid;
        }

        return $request
            ->accept('application/json')
            ->withHeaders($headers)
            // Guzzle's own `expect` option, so curl waits for 100-continue
            // before sending the body and a refused upload costs no bytes.
            ->withOptions(['expect' => true])
            ->withBody($payload->body(), 'application/octet-stream');
    }

    /**
     * The `{oid, size, expires_at}` body of a successful upload.
     */
    private function blobResult(Response $response, ?string $expected): BlobUpload
    {
        $oid = (string) $response->json('oid');

        if ($expected !== null && $oid !== $expected) {
            throw new OidMismatch(
                'The server returned a different oid than the bytes were hashed to.',
                ['expected_oid' => $expected, 'actual_oid' => $oid],
            );
        }

        if ($oid === '') {
            throw new SourceException('The blob upload response did not carry an oid.', 'transport_error');
        }

        $expires = $response->json('expires_at');

        return new BlobUpload(
            oid: $oid,
            size: (int) $response->json('size'),
            expiresAt: is_string($expires) && $expires !== '' ? $expires : null,
            existing: $response->status() === 200,
        );
    }

    private function blobUrl(string $namespace, string $repo): string
    {
        return $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/blobs');
    }

    /**
     * The headers of a raw file write (api.md 3.4.2).
     *
     * The message and author are percent-encoded UTF-8 so a multi-line message
     * and its trailers fit in a header; the server decodes them with the same
     * rules RFC 3986 describes.
     *
     * @return array<string, string>
     */
    private function rawHeaders(
        Payload $payload,
        string $expectedOid,
        string $message,
        ?Author $author,
        ?string $mode,
    ): array {
        $headers = [
            'Content-Length' => (string) $payload->size(),
            'X-Source-Message' => rawurlencode($message),
        ];

        if ($expectedOid === '') {
            $headers['If-None-Match'] = '*';
        } else {
            $headers['If-Match'] = '"'.$expectedOid.'"';
        }

        if ($author !== null) {
            $headers['X-Source-Author'] = rawurlencode($author->name.' <'.$author->email.'>');
        }

        if ($mode !== null && $mode !== '') {
            $headers['X-Source-Mode'] = $mode;
        }

        return $headers;
    }

    /**
     * The `{commit, file, ref}` body of a raw file write. It carries one `file`
     * object where a batch commit carries a `files` list.
     */
    private function singleFileResult(Response $response): CommitResult
    {
        $files = [];

        $file = $response->json('file');

        if (is_array($file) && is_string($file['path'] ?? null)) {
            $files[] = new CommittedFile(
                path: $file['path'],
                version: is_string($file['oid'] ?? null) ? $file['oid'] : null,
                size: (int) ($file['size'] ?? 0),
                mode: is_string($file['mode'] ?? null) ? $file['mode'] : '100644',
                deleted: ($file['oid'] ?? null) === null,
            );
        }

        return new CommitResult(
            commitOid: (string) $response->json('commit.oid'),
            refName: $response->json('ref.name') ?: null,
            files: $files,
            baseOid: $response->json('commit.base_oid') ?: null,
        );
    }

    public function log(
        string $namespace,
        string $repo,
        string $ref,
        ?string $path = null,
        ?string $cursor = null,
        int $limit = 200,
    ): CommitPage {
        $query = ['ref' => $ref, 'limit' => (string) $limit];

        if ($path !== null && $path !== '') {
            $query['path'] = $path;
        }

        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/log').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $response = $this->request()->get($url);

        $this->ensureSuccess($response);

        $commits = [];

        foreach (($response->json('commits') ?? []) as $commit) {
            if (is_array($commit)) {
                $commits[] = Commit::fromArray($commit);
            }
        }

        $next = $response->json('next_cursor');

        return new CommitPage($commits, is_string($next) && $next !== '' ? $next : null);
    }

    public function commit(string $namespace, string $repo, string $ref): Commit
    {
        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/commits/'.self::encodeRef($ref));

        $response = $this->request()->get($url);

        $this->ensureSuccess($response);

        $commit = $response->json('commit');

        return Commit::fromArray(is_array($commit) ? $commit : []);
    }

    public function diff(string $namespace, string $repo, string $from, string $to, ?string $path = null): Diff
    {
        $query = ['from' => $from, 'to' => $to, 'format' => 'structured'];

        if ($path !== null && $path !== '') {
            $query['path'] = $path;
        }

        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/diff').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $response = $this->request()->get($url);

        $this->ensureSuccess($response);

        $files = [];

        foreach (($response->json('files') ?? []) as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                continue;
            }

            $hunks = [];

            foreach (($file['hunks'] ?? []) as $hunk) {
                if (! is_array($hunk)) {
                    continue;
                }

                $hunks[] = new DiffHunk(
                    oldStart: (int) ($hunk['old_start'] ?? 0),
                    oldLines: (int) ($hunk['old_lines'] ?? 0),
                    newStart: (int) ($hunk['new_start'] ?? 0),
                    newLines: (int) ($hunk['new_lines'] ?? 0),
                    lines: array_values(array_filter((array) ($hunk['lines'] ?? []), 'is_string')),
                );
            }

            $files[] = new DiffFile(
                path: $file['path'],
                status: is_string($file['status'] ?? null) ? $file['status'] : DiffFile::MODIFIED,
                oldPath: is_string($file['old_path'] ?? null) ? $file['old_path'] : null,
                binary: (bool) ($file['binary'] ?? false),
                additions: (int) ($file['additions'] ?? 0),
                deletions: (int) ($file['deletions'] ?? 0),
                hunks: $hunks,
            );
        }

        return new Diff(
            from: (string) $response->json('from'),
            to: (string) $response->json('to'),
            files: $files,
        );
    }

    public function diffText(string $namespace, string $repo, string $from, string $to, ?string $path = null): string
    {
        $query = ['from' => $from, 'to' => $to, 'format' => 'unified'];

        if ($path !== null && $path !== '') {
            $query['path'] = $path;
        }

        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/diff').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $response = $this->request('text/plain')->get($url);

        $this->ensureSuccess($response);

        return $response->body();
    }

    public function findSession(string $namespace, string $repo, string $ref): ?Session
    {
        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/sessions').'?'.http_build_query(['ref' => $ref], '', '&', PHP_QUERY_RFC3986);

        $response = $this->request()->get($url);

        if ($this->isMissing($response)) {
            return null;
        }

        $this->ensureSuccess($response);

        $wanted = self::shortRef($ref);
        $found = null;

        foreach (($response->json('sessions') ?? []) as $session) {
            if (! is_array($session) || ! is_string($session['id'] ?? null)) {
                continue;
            }

            // The server filters by ref; check it again so a server that
            // ignores the filter cannot hand back someone else's session.
            if (self::shortRef((string) ($session['ref'] ?? '')) === $wanted) {
                $found = Session::fromArray($session);
            }
        }

        return $found;
    }

    public function createSession(string $namespace, string $repo, string $from, string $ref): Session
    {
        $url = $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/sessions');

        $response = $this->request()->asJson()->post($url, ['from' => $from, 'ref' => $ref]);

        $this->ensureSuccess($response);

        $session = $response->json('session');

        return Session::fromArray(is_array($session) ? $session : []);
    }

    public function merge(string $namespace, string $repo, string $sessionId, string $target): MergeResult
    {
        $url = $this->sessionUrl($namespace, $repo, $sessionId).'/merge';

        $response = $this->request()->asJson()->post($url, ['target' => $target]);

        $this->ensureSuccess($response);

        return MergeResult::fromArray((array) ($response->json('merge') ?? []));
    }

    public function resolveMerge(
        string $namespace,
        string $repo,
        string $sessionId,
        string $mergeId,
        array $resolutions,
        ?string $message = null,
    ): MergeResult {
        $paths = [];

        foreach ($resolutions as $path => $resolution) {
            $entry = ['path' => (string) $path];

            if (in_array($resolution, ['ours', 'theirs', 'delete'], true)) {
                $entry['resolution'] = $resolution;
            } elseif (preg_match('//u', $resolution) === 1) {
                $entry['resolution'] = 'content';
                $entry['content'] = $resolution;
            } else {
                $entry['resolution'] = 'content';
                $entry['content_base64'] = base64_encode($resolution);
            }

            $paths[] = $entry;
        }

        $body = ['paths' => $paths];

        if ($message !== null && $message !== '') {
            $body['message'] = $message;
        }

        $url = $this->sessionUrl($namespace, $repo, $sessionId)
            .'/merges/'.rawurlencode($mergeId).'/resolve';

        $response = $this->request()->asJson()->post($url, $body);

        $this->ensureSuccess($response);

        return MergeResult::fromArray((array) ($response->json('merge') ?? []));
    }

    public function abortMerge(string $namespace, string $repo, string $sessionId, ?string $mergeId = null): Session
    {
        $url = $this->sessionUrl($namespace, $repo, $sessionId).'/abort';

        $body = $mergeId !== null && $mergeId !== '' ? ['merge_id' => $mergeId] : [];

        $response = $this->request()->asJson()->post($url, $body);

        $this->ensureSuccess($response);

        return Session::fromArray((array) ($response->json('session') ?? []));
    }

    /**
     * The body is streamed (Guzzle's `stream` option), so a large blob costs
     * a buffer, not its size.
     */
    public function object(string $namespace, string $repo, string $oid)
    {
        $response = $this->request('application/octet-stream')
            ->withOptions(['stream' => true])
            ->get($this->url('namespaces/'.rawurlencode($namespace)
                .'/repos/'.rawurlencode($repo)
                .'/objects/'.rawurlencode($oid)));

        $this->ensureSuccess($response);

        return StreamWrapper::getResource($response->toPsrResponse()->getBody());
    }

    /**
     * Percent-encode a ref. `sessions/01J...` is a single path segment on the
     * wire: `sessions%2F01J...` (api.md 3.3).
     */
    public static function encodeRef(string $ref): string
    {
        return rawurlencode($ref);
    }

    /**
     * Percent-encode a repo path segment by segment, keeping the slashes.
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * `refs/heads/main` and `main` name the same branch.
     */
    public static function shortRef(string $ref): string
    {
        return str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
    }

    private function fileUrl(string $namespace, string $repo, string $ref, string $path): string
    {
        return $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/files/'.self::encodeRef($ref)
            .'/'.self::encodePath($path));
    }

    private function sessionUrl(string $namespace, string $repo, string $sessionId): string
    {
        return $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/sessions/'.rawurlencode($sessionId));
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/').'/v1/'.$path;
    }

    /**
     * Seconds a request may take in total. Writes that carry file bytes get
     * their own, longer budget: a 256 MiB file can't cross a slow uplink in
     * the 30 seconds that suits every other call.
     */
    public function timeoutFor(bool $upload): float
    {
        return $upload
            ? (float) ($this->config['upload_timeout'] ?? 600)
            : (float) ($this->config['timeout'] ?? 30);
    }

    private function request(string $accept = 'application/json', bool $upload = false): PendingRequest
    {
        return $this->configure(Http::accept($accept), $upload);
    }

    /**
     * The settings every request carries: the timeout (uploads get their own)
     * and the bearer token.
     */
    private function configure(PendingRequest $request, bool $upload = false): PendingRequest
    {
        $request = $request->timeout($this->timeoutFor($upload));

        $token = (string) ($this->config['token'] ?? '');

        return $token !== '' ? $request->withToken($token) : $request;
    }

    private function ensureSuccess(Response $response): void
    {
        if (! $response->successful()) {
            throw SourceException::fromResponse($response);
        }
    }

    /**
     * 404 means the path is absent. An empty branch is a 409 not_found from
     * the server ("the branch has no commits yet"), which is absence too.
     */
    private function isMissing(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        if ($response->status() !== 409) {
            return false;
        }

        return $response->json('error.code') === 'not_found';
    }
}
