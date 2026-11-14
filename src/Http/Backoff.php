<?php

declare(strict_types=1);

namespace Source\Http;

use Closure;
use Illuminate\Support\Sleep;
use Source\Exceptions\RateLimited;

/**
 * Waits out `429` and sends the same request again (api.md 3.4.4).
 *
 * The server refuses a write it cannot fit, with `Retry-After`, rather than
 * queueing it. The client's part of that contract is to wait and retry the same
 * request, with the same bytes, a bounded number of times. A
 * `413` is never retried: that request can never fit.
 *
 * The waits are real by default (`Sleep::for(...)->seconds()`), and a test can
 * either `Sleep::fake()` or hand in its own sleeper.
 */
final class Backoff
{
    /**
     * One try plus three retries.
     */
    public const ATTEMPTS = 4;

    /**
     * A cap on a single wait, so a silly `Retry-After` cannot hang a request.
     */
    public const MAX_WAIT = 30;

    /**
     * @param  (Closure(int): void)|null  $sleeper  seconds to wait
     */
    public function __construct(
        private readonly int $attempts = self::ATTEMPTS,
        private readonly int $maxWait = self::MAX_WAIT,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(int): T  $attempt  receives the attempt number, from 1
     * @return T
     */
    public function run(Closure $attempt): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $attempt($try);
            } catch (RateLimited $e) {
                if ($try >= $this->attempts) {
                    throw $e;
                }

                $this->wait($e->retryAfter);
            }
        }
    }

    /**
     * Wait out a delay chosen elsewhere, with the same cap as a `429` retry.
     *
     * A parallel uploader has several refusals in one wave but waits once, for
     * the largest `Retry-After` among them (api.md 3.4.4).
     */
    public function pause(int $seconds): void
    {
        $this->wait($seconds);
    }

    private function wait(int $seconds): void
    {
        $seconds = max(1, min($seconds > 0 ? $seconds : 1, $this->maxWait));

        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        Sleep::for($seconds)->seconds();
    }
}
