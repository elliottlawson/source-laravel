<?php

declare(strict_types=1);

namespace Source\Uploads;

use InvalidArgumentException;

/**
 * How many blob uploads one wave may have in flight.
 *
 * The server caps upload bytes and refuses what cannot fit with `429
 * uploads_busy`; the client's part is to slow down when that happens and to
 * speed up again when it can. This object holds only the arithmetic, with no
 * I/O, so the rules can be tested on their own:
 *
 *  - a clean wave whose throughput (bytes per second) beats the previous clean
 *    wave adds one, up to the maximum;
 *  - a clean wave that is slower, or exactly as fast, holds;
 *  - any wave with a refusal or a transport error halves the concurrency, never
 *    below one.
 *
 * "Throughput" is the wave's bytes divided by its wall-clock duration. Only
 * clean waves are recorded: an errored wave says nothing about how fast the
 * server would have been.
 */
final class AdaptiveConcurrency
{
    public const START = 4;

    public const MAX = 16;

    private int $current;

    private ?float $throughput = null;

    public function __construct(
        int $start = self::START,
        private readonly int $max = self::MAX,
        private readonly int $min = 1,
    ) {
        if ($min < 1) {
            throw new InvalidArgumentException('Concurrency cannot go below one.');
        }

        if ($max < $min) {
            throw new InvalidArgumentException('The maximum concurrency cannot be below the minimum.');
        }

        $this->current = max($min, min($start, $max));
    }

    /**
     * The number of uploads the next wave may have in flight.
     */
    public function current(): int
    {
        return $this->current;
    }

    /**
     * The last clean wave's throughput in bytes per second, if there was one.
     */
    public function throughput(): ?float
    {
        return $this->throughput;
    }

    /**
     * Record a wave that finished without a refusal or transport error.
     */
    public function recordWave(int $bytes, float $seconds): int
    {
        $throughput = $bytes / max($seconds, 0.000_001);

        if ($this->throughput !== null && $throughput > $this->throughput) {
            $this->current = min($this->max, $this->current + 1);
        }

        $this->throughput = $throughput;

        return $this->current;
    }

    /**
     * Back off after a `429` or a transport/5xx error: halve, never to zero.
     */
    public function halve(): int
    {
        $this->current = max($this->min, intdiv($this->current, 2));

        return $this->current;
    }
}
