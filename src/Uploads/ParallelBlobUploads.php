<?php

declare(strict_types=1);

namespace Source\Uploads;

use Source\BlobUpload;
use Source\Change;
use Source\Exceptions\NotFound;
use Source\Exceptions\OidMismatch;
use Source\Exceptions\RateLimited;
use Source\Exceptions\SourceException;
use Source\Exceptions\StagingFull;
use Source\Http\Backoff;
use Source\Http\Client;
use Source\Support\Payload;
use Throwable;

/**
 * Uploads each change's bytes first, then names them by oid in one commit.
 *
 * This is the default `Uploader` and the implementation api.md 3.4.5
 * describes:
 *
 *  1. every put is hashed as a Git blob (`Payload`, so a stream is read once,
 *     never buffered) and `POST /objects/present` asks which blobs the repo
 *     already holds, in chunks of 1000;
 *  2. a put whose bytes are under `inlineThreshold` (64 KiB by default) stays
 *     inline in the commit body: one request per tiny file costs more than it
 *     saves;
 *  3. every other missing blob is uploaded to `POST .../blobs`, streamed and in
 *     parallel, and its change becomes `{path, expected_oid, oid}`;
 *  4. the commit names them and never carries those bytes.
 *
 * Parallelism is a wave at a time, with the concurrency chosen by
 * `AdaptiveConcurrency`: start at 4, add one after a faster clean wave, halve on
 * any refusal or transport error. A `429` waits for the largest `Retry-After`
 * in the wave and retries only the refused uploads; a transport/5xx error
 * retries that upload, bounded. `409 staging_full` and `422 oid_mismatch` are
 * final: neither can be fixed by sending the same bytes again.
 *
 * Two compatibility behaviours live here too. `$forceUpload` (the
 * `422 unknown_object` fallback in `SourceStore`) means "do not trust present or
 * an earlier upload for these paths": their bytes are sent again, through
 * `/blobs` or inline when tiny, and the commit is then retried once. And a
 * server that predates `/blobs` answers `404` with `details.resource: "path"`
 * (the router's own not-found); the uploader remembers that and sends every
 * later commit's missing bytes inline, so the package keeps working.
 */
final class ParallelBlobUploads implements Uploader
{
    /**
     * The documented request bound for `objects/present`.
     */
    public const CHUNK = 1000;

    /**
     * Files smaller than this stay inline: a blob upload costs a request.
     */
    public const DEFAULT_INLINE_THRESHOLD = 65536;

    /**
     * Whether this client has seen `/blobs` answer "no such path". Remembered
     * for every later commit on the same disk/transport.
     */
    private bool $uploadsSupported = true;

    private readonly Backoff $backoff;

    public function __construct(
        private readonly Client $client,
        private readonly int $inlineThreshold = self::DEFAULT_INLINE_THRESHOLD,
        private readonly AdaptiveConcurrency $concurrency = new AdaptiveConcurrency,
        ?Backoff $backoff = null,
        private readonly int $attempts = Backoff::ATTEMPTS,
    ) {
        $this->backoff = $backoff ?? new Backoff;
    }

    /**
     * @param  list<Change>  $changes
     * @param  array<string, true>  $forceUpload  paths whose bytes must be sent
     *                                            again even if the repo says it
     *                                            holds them (the unknown_object
     *                                            fallback)
     * @return list<Change>
     */
    public function prepare(string $namespace, string $repo, string $ref, array $changes, array $forceUpload = []): array
    {
        $oids = [];
        $sizes = [];
        $payloads = [];
        $candidates = [];

        foreach ($changes as $change) {
            if ($change->isDelete() || $change->isReference()) {
                continue;
            }

            $payload = $change->payload ?? Payload::from((string) $change->content);
            $oid = $payload->oid();
            $size = $payload->size();

            $oids[$change->path] = $oid;
            $sizes[$change->path] = $size;
            $payloads[$change->path] = $payload;

            if ($size >= $this->inlineThreshold || isset($forceUpload[$change->path])) {
                $candidates[$change->path] = true;
            }
        }

        // Everything is small enough to travel inline, and nothing was forced.
        if ($candidates === []) {
            return $changes;
        }

        $present = $this->present($namespace, $repo, $candidates, $oids, $forceUpload);

        $upload = [];

        foreach (array_keys($candidates) as $path) {
            if (isset($forceUpload[$path]) || ! isset($present[$oids[$path]])) {
                $upload[] = $path;
            }
        }

        // Identical bytes are one blob: upload them once and name every path
        // that produces them.
        $byOid = [];

        foreach ($upload as $path) {
            $byOid[$oids[$path]] ??= $path;
        }

        $upload = array_values($byOid);

        $uploaded = [];

        if ($upload !== [] && $this->uploadsSupported) {
            [$uploaded, $this->uploadsSupported] = $this->upload(
                $namespace,
                $repo,
                $upload,
                $oids,
                $sizes,
                $payloads,
            );
        }

        if (! $this->uploadsSupported) {
            // A server without /blobs: nothing was uploaded, and every later
            // commit takes the same inline path.
            $uploaded = [];
        }

        $prepared = [];
        $uploadedOids = [];

        foreach (array_keys($uploaded) as $path) {
            $uploadedOids[$oids[$path]] = true;
        }

        foreach ($changes as $change) {
            $oid = $oids[$change->path] ?? null;

            $prepared[] = $oid !== null && (
                (isset($present[$oid]) && ! isset($forceUpload[$change->path]))
                || isset($uploadedOids[$oid])
            )
                ? $change->asReference($oid)
                : $change;
        }

        return $prepared;
    }

    /**
     * Which of the candidate blobs the repo already holds (api.md 3.4.3).
     *
     * Forced paths are left out: the whole point of the fallback is that the
     * hint was wrong for them.
     *
     * @param  array<string, true>  $candidates
     * @param  array<string, string>  $oids
     * @param  array<string, true>  $forceUpload
     * @return array<string, true>
     */
    private function present(string $namespace, string $repo, array $candidates, array $oids, array $forceUpload): array
    {
        $wanted = [];

        foreach (array_keys($candidates) as $path) {
            if (! isset($forceUpload[$path])) {
                $wanted[$oids[$path]] = true;
            }
        }

        $present = [];

        foreach (array_chunk(array_keys($wanted), self::CHUNK) as $chunk) {
            foreach ($this->client->presentObjects($namespace, $repo, $chunk) as $oid) {
                $present[$oid] = true;
            }
        }

        return $present;
    }

    /**
     * Upload the paths in waves, returning the ones that landed.
     *
     * The second element is false when the server has no `/blobs` route, which
     * ends uploading for this client (api.md 3.4.5 predates it).
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $oids
     * @param  array<string, int>  $sizes
     * @param  array<string, Payload>  $payloads
     * @return array{0: array<string, true>, 1: bool}
     */
    private function upload(string $namespace, string $repo, array $paths, array $oids, array $sizes, array $payloads): array
    {
        $uploaded = [];
        $queue = $paths;
        $attempts = [];

        while ($queue !== []) {
            $take = max(1, min($this->concurrency->current(), count($queue)));
            $wave = array_splice($queue, 0, $take);

            $blobs = [];

            foreach ($wave as $path) {
                // A retry has to send the same bytes from the first one.
                $payloads[$path]->rewind();
                $blobs[] = new PendingBlob($path, $payloads[$path], $oids[$path]);
            }

            $started = microtime(true);
            $results = $this->client->uploadBlobs($namespace, $repo, $blobs, $take);
            $elapsed = microtime(true) - $started;

            $retry = [];
            $bytes = 0;
            $wait = 0;
            $failed = false;

            foreach ($wave as $path) {
                $result = $results[$path] ?? null;

                if ($result instanceof BlobUpload) {
                    if ($result->oid !== $oids[$path]) {
                        throw new OidMismatch(
                            'An uploaded blob hashed to a different oid than the local file.',
                            ['expected_oid' => $oids[$path], 'actual_oid' => $result->oid],
                        );
                    }

                    $uploaded[$path] = true;
                    $bytes += $sizes[$path];

                    continue;
                }

                $error = $result instanceof Throwable
                    ? $result
                    : new SourceException('The blob upload returned no result.', 'transport_error');

                if ($error instanceof StagingFull || $error instanceof OidMismatch) {
                    throw $error;
                }

                // The router's 404: this server has no POST /blobs at all. Send
                // every file inline from now on (api.md 3.4.5 is newer than the
                // server), which is what the package did before it.
                if ($error instanceof NotFound && $error->resource() === 'path') {
                    return [[], false];
                }

                $failed = true;
                $attempts[$path] = ($attempts[$path] ?? 0) + 1;

                if ($error instanceof RateLimited) {
                    if ($attempts[$path] >= $this->attempts) {
                        throw $error;
                    }

                    $wait = max($wait, max(1, $error->retryAfter));
                    $retry[] = $path;

                    continue;
                }

                if ($this->retryable($error)) {
                    if ($attempts[$path] >= $this->attempts) {
                        throw $error;
                    }

                    $retry[] = $path;

                    continue;
                }

                throw $error;
            }

            if ($failed) {
                $this->concurrency->halve();
            } else {
                $this->concurrency->recordWave($bytes, $elapsed);
            }

            if ($wait > 0) {
                // One wait for the whole wave, for the largest Retry-After.
                $this->backoff->pause($wait);
            }

            if ($retry !== []) {
                // Refused uploads go first; the untouched queue stays behind.
                $queue = array_merge($retry, $queue);
            }
        }

        return [$uploaded, true];
    }

    /**
     * Whether the same request could succeed later: a 5xx or a transport
     * failure, as opposed to a 4xx that will answer the same way again.
     */
    private function retryable(Throwable $error): bool
    {
        if (! $error instanceof SourceException) {
            // A connection failure is not an API error (laravel-package.md
            // "Errors"); the write never reached the server.
            return true;
        }

        if ($error instanceof RateLimited) {
            return true;
        }

        if ($error instanceof NotFound) {
            return false;
        }

        $code = $error->errorCode;

        return in_array($code, ['internal_error', 'unavailable'], true)
            || str_starts_with($code, 'http_5');
    }
}
