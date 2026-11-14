<?php

declare(strict_types=1);

namespace Source\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Source\ChangedBy;
use Throwable;

/**
 * A write named a version that is no longer the file's version (409
 * file_changed).
 *
 * It carries every path that moved and who moved it, and never the new
 * contents or the current version: the only way forward is to read again.
 */
class FileChanged extends SourceException implements Responsable
{
    /**
     * @param  list<string>  $paths  every path that moved
     * @param  array<string, ChangedBy>  $changed  path => who moved it
     * @param  array<string, string>  $expected  path => the version the writer sent
     */
    public function __construct(
        public readonly array $paths = [],
        public readonly array $changed = [],
        public readonly array $expected = [],
        public readonly ?string $refName = null,
        string $message = '',
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : self::defaultMessage(count($paths)),
            'file_changed',
            $this->detailsPayload(),
            $requestId,
            $previous,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function fromDetails(array $details, string $message = '', ?string $requestId = null): self
    {
        $paths = [];
        $changed = [];
        $expected = [];

        foreach (($details['paths'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                continue;
            }

            $path = $entry['path'];
            $paths[] = $path;
            $expected[$path] = is_string($entry['expected_oid'] ?? null) ? $entry['expected_oid'] : '';
            $changed[$path] = ChangedBy::fromArray(is_array($entry['changed_by'] ?? null) ? $entry['changed_by'] : []);
        }

        return new self(
            paths: $paths,
            changed: $changed,
            expected: $expected,
            refName: is_string($details['ref'] ?? null) ? $details['ref'] : null,
            message: $message,
            requestId: $requestId,
        );
    }

    /**
     * Who moved the given path last, if the server named it.
     */
    public function changedBy(string $path): ?ChangedBy
    {
        return $this->changed[$path] ?? null;
    }

    /**
     * The 409 body, shaped like every other API error (api.md 2.1).
     *
     * @return array<string, mixed>
     */
    public function detailsPayload(): array
    {
        $paths = [];

        foreach ($this->paths as $path) {
            $paths[] = [
                'path' => $path,
                'expected_oid' => $this->expected[$path] ?? '',
                'changed_by' => ($this->changed[$path] ?? new ChangedBy)->toArray(),
            ];
        }

        $details = ['paths' => $paths];

        if ($this->refName !== null) {
            $details['ref'] = $this->refName;
        }

        return $details;
    }

    /**
     * Render the refusal as a 409 JSON response when it escapes a controller.
     */
    public function render($request = null): JsonResponse
    {
        return $this->toResponse($request);
    }

    public function toResponse($request = null): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'file_changed',
                'message' => $this->getMessage(),
                'details' => $this->detailsPayload(),
                'request_id' => $this->requestId,
            ],
        ], 409);
    }

    private static function defaultMessage(int $count): string
    {
        return $count === 1
            ? '1 file changed since you read it; read it again before writing.'
            : "{$count} files changed since you read them; read them again before writing.";
    }
}
