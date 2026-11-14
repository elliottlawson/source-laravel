<?php

declare(strict_types=1);

namespace Source;

/**
 * One queued change inside PendingChanges.
 *
 * `expectedOid === null` means a plain change: the driver reads the current
 * version when it sends. A strict change carries the version the caller named.
 *
 * @internal
 */
final readonly class PendingChange
{
    public function __construct(
        public string $path,
        public ?string $content,
        public ?string $expectedOid = null,
        public bool $delete = false,
    ) {}

    public function isStrict(): bool
    {
        return $this->expectedOid !== null;
    }

    public function isPlain(): bool
    {
        return $this->expectedOid === null;
    }

    public function toChange(): Change
    {
        return $this->delete
            ? Change::delete($this->path, (string) $this->expectedOid)
            : Change::put($this->path, (string) $this->expectedOid, (string) $this->content);
    }
}
