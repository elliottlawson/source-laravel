<?php

declare(strict_types=1);

namespace Source;

use Source\Support\Contents;

/**
 * The pending-changes object handed to a commit() closure.
 *
 * Nothing is sent while the closure runs; the driver reads the stored files
 * for reads inside the closure (pending writes are not visible to them).
 */
class PendingChanges
{
    /** @var array<string, PendingChange> */
    protected array $changes = [];

    public function __construct(protected readonly SourceStore $store) {}

    /**
     * Queue a plain put: its version is read when the commit is sent.
     */
    public function put(string $path, mixed $contents): static
    {
        return $this->stage(new PendingChange($path, Contents::toString($contents)));
    }

    /**
     * Queue a plain delete: its version is read when the commit is sent.
     */
    public function delete(string $path): static
    {
        return $this->stage(new PendingChange($path, null, null, delete: true));
    }

    /**
     * Queue strict writes against one version.
     */
    public function ifUnchanged(?string $version): PendingGuard
    {
        return PendingGuard::deferred($this, $version ?? '');
    }

    /**
     * Queue creates only: the path must not exist yet.
     */
    public function ifMissing(): PendingGuard
    {
        return PendingGuard::deferred($this, '');
    }

    /**
     * Read a stored file inside the commit closure.
     */
    public function read(string $path): SourceFile
    {
        return $this->store->read($path);
    }

    /**
     * Read a stored file's contents inside the commit closure.
     */
    public function get(string $path): string
    {
        return $this->read($path)->contents;
    }

    public function exists(string $path): bool
    {
        return $this->store->exists($path);
    }

    /**
     * @return list<PendingChange>
     */
    public function all(): array
    {
        return array_values($this->changes);
    }

    public function stage(PendingChange $change): static
    {
        $this->changes[$change->path] = $change;

        return $this;
    }
}
