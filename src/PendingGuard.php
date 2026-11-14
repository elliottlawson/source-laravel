<?php

declare(strict_types=1);

namespace Source;

use Source\Support\Contents;

/**
 * `ifUnchanged($version)` / `ifMissing()` writes.
 *
 * On a disk (`$disk->ifUnchanged($v)->put(...)`) the write is sent at once.
 * Inside a commit closure (`$files->ifUnchanged($v)->put(...)`) it is queued
 * into the open batch. Either way the version is the caller's: no retry.
 */
class PendingGuard
{
    protected function __construct(
        protected readonly PendingChanges|SourceStore $target,
        protected readonly string $expectedOid,
    ) {}

    public static function deferred(PendingChanges $changes, string $expectedOid): static
    {
        return new static($changes, $expectedOid);
    }

    public static function eager(SourceStore $store, string $expectedOid): static
    {
        return new static($store, $expectedOid);
    }

    /**
     * Queue the write into a commit, or send it at once on a disk.
     */
    public function put(string $path, mixed $contents): PendingChanges|CommitResult
    {
        $contents = Contents::toString($contents);

        if ($this->target instanceof PendingChanges) {
            return $this->target->stage(new PendingChange($path, $contents, $this->expectedOid));
        }

        return $this->target->putStrict($path, $contents, $this->expectedOid);
    }

    /**
     * Queue the delete into a commit, or send it at once on a disk.
     */
    public function delete(string $path): PendingChanges|CommitResult
    {
        if ($this->target instanceof PendingChanges) {
            return $this->target->stage(new PendingChange($path, null, $this->expectedOid, delete: true));
        }

        return $this->target->deleteStrict($path, $this->expectedOid);
    }
}
