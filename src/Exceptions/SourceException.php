<?php

declare(strict_types=1);

namespace Source\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * The base for every API error `source` returns.
 *
 * `$errorCode` is the stable API code ("not_found", "stale_base", ...);
 * Exception::$code stays the integer PHP one, so the API code has its own
 * property.
 */
class SourceException extends RuntimeException
{
    public readonly string $errorCode;

    /** @var array<string, mixed> */
    public readonly array $details;

    public readonly ?string $requestId;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'source_error',
        array $details = [],
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->requestId = $requestId;
    }

    /**
     * Map an HTTP error response onto the typed exception tree.
     */
    public static function fromResponse(Response $response): self
    {
        $body = json_decode($response->body(), true);
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        $code = is_string($error['code'] ?? null) ? $error['code'] : 'http_'.$response->status();
        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : ($response->reason() ?: 'The source request failed.');
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $requestId = is_string($error['request_id'] ?? null)
            ? $error['request_id']
            : ($response->header('X-Request-Id') ?: null);
        $requestId = $requestId === '' ? null : $requestId;

        return match (true) {
            $response->status() === 409 && $code === 'file_changed' => FileChanged::fromDetails($details, $message, $requestId),
            $response->status() === 409 && $code === 'merge_conflict' => new MergeConflict($message, $code, $details, $requestId),
            $response->status() === 404 => new NotFound($message, $details, $requestId),
            $response->status() === 401 => new Unauthorized($message, $code, $details, $requestId),
            $response->status() === 403 => new Forbidden($message, $code, $details, $requestId),
            $response->status() === 409 => new Conflict($message, $code, $details, $requestId),
            $response->status() === 413 => new FileTooLarge($message, $details, $requestId),
            $response->status() === 422 => new ValidationFailed($message, $code, $details, $requestId),
            $response->status() === 429 => new RateLimited(
                $message,
                $code,
                $details,
                $requestId,
                (int) ($details['retry_after'] ?? $response->header('Retry-After') ?: 0),
            ),
            default => new self($message, $code, $details, $requestId),
        };
    }

    /**
     * The API error body for this exception.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => (object) $this->details,
                'request_id' => $this->requestId,
            ],
        ];
    }
}
