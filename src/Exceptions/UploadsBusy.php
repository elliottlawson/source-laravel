<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * Upload capacity is busy (429 uploads_busy).
 *
 * A different condition from `rate_limited` (request counts), which extends the
 * same class because the client reacts the same way: wait `Retry-After`, then
 * send the same request again. The transport already does that; this is what
 * escapes when every bounded retry was refused, and it is what a caller can
 * catch to slow its own concurrency down.
 */
class UploadsBusy extends RateLimited
{
    public readonly ?int $limitBytes;

    public readonly ?int $inFlightBytes;

    public readonly ?string $scope;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        array $details = [],
        ?string $requestId = null,
        int $retryAfter = 0,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'Upload capacity is busy; retry after the delay.',
            'uploads_busy',
            $details,
            $requestId,
            $retryAfter,
        );

        $this->limitBytes = isset($details['limit_bytes']) ? (int) $details['limit_bytes'] : null;
        $this->inFlightBytes = isset($details['in_flight_bytes']) ? (int) $details['in_flight_bytes'] : null;
        $this->scope = is_string($details['scope'] ?? null) ? $details['scope'] : null;
    }
}
