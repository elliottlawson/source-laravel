<?php

declare(strict_types=1);

namespace Source\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Source\Author;
use Source\Change;
use Source\CommitResult;
use Source\CommittedFile;
use Source\Exceptions\SourceException;
use Source\FileStat;
use Source\SourceFile;
use Source\TreeEntry;

/**
 * The real transport, over Laravel's HTTP client (so Http::fake() works).
 */
final class HttpClient implements Client
{
    /**
     * @param  array{url?: string, token?: string, timeout?: int|float}  $config
     */
    public function __construct(private readonly array $config) {}

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
     * The one write path.
     *
     * Every write this package makes goes through here: single-file writes,
     * deletes, moves and batch commits are all one POST /commits. It builds a
     * JSON body today, using `content` for UTF-8 and `content_base64`
     * otherwise.
     *
     * When raw-bytes streaming lands (api.md 3.4.2), this is the single place
     * to switch: a raw file write is
     * PUT /files/{ref}/{path} with an octet-stream body, If-Match /
     * If-None-Match: *, X-Source-Message and Content-Length. Batch commits
     * (POST /commits) stay as they are.
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

        $response = $this->request()->asJson()->post($url, $body);

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

    private function fileUrl(string $namespace, string $repo, string $ref, string $path): string
    {
        return $this->url('namespaces/'.rawurlencode($namespace)
            .'/repos/'.rawurlencode($repo)
            .'/files/'.self::encodeRef($ref)
            .'/'.self::encodePath($path));
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/').'/v1/'.$path;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((float) ($this->config['timeout'] ?? 30));

        $token = (string) ($this->config['token'] ?? '');

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
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
