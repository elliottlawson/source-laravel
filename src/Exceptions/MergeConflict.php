<?php

declare(strict_types=1);

namespace Source\Exceptions;

use Closure;
use LogicException;
use Source\MergeResult;

/**
 * A merge produced conflicts (409 merge_conflict).
 *
 * `details` is exactly the conflict payload the server sent, no more: the
 * merge id, both sides' tips, the merge base, every conflicted path with its
 * three stages and hunks, and the resolution the server accepts.
 *
 * The exception is the handle for finishing the merge. `resolve()` answers
 * every conflicted path in one call and `abort()` drops the merge; both are
 * bound to the disk that raised it.
 */
class MergeConflict extends SourceException
{
    /** @var (Closure(array<string, string>, ?string): MergeResult)|null */
    private ?Closure $resolver = null;

    /** @var (Closure(): void)|null */
    private ?Closure $aborter = null;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'merge_conflict',
        array $details = [],
        ?string $requestId = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : 'The merge produced conflicts; resolve them on the session branch.',
            $errorCode,
            $details,
            $requestId,
        );
    }

    /**
     * Bind the disk's resolve and abort calls to this conflict.
     *
     * @param  Closure(array<string, string>, ?string): MergeResult  $resolve
     * @param  Closure(): void  $abort
     */
    public function withActions(Closure $resolve, Closure $abort): static
    {
        // Exceptions cannot be cloned, and the conflict is about to be thrown
        // anyway, so the actions are bound to this instance.
        $this->resolver = $resolve;
        $this->aborter = $abort;

        return $this;
    }

    /**
     * The conflicted paths, as the payload names them.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->conflicts() as $conflict) {
            if (is_string($conflict['path'] ?? null)) {
                $paths[] = $conflict['path'];
            }
        }

        return $paths;
    }

    /**
     * The raw conflict records from the payload.
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(): array
    {
        $conflicts = $this->details['conflicts'] ?? [];

        return is_array($conflicts) ? array_values(array_filter($conflicts, 'is_array')) : [];
    }

    public function mergeId(): ?string
    {
        $id = $this->details['merge_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function sessionId(): ?string
    {
        $id = $this->details['session'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function target(): ?string
    {
        $target = $this->details['target'] ?? null;

        return is_string($target) ? $target : null;
    }

    /**
     * Answer every conflicted path in one call.
     *
     * The map is `path => 'ours' | 'theirs' | 'delete' | <new contents>`.
     * `ours` is the target's version and `theirs` the session's, as the API
     * names them.
     *
     * @param  array<string, string>  $paths
     */
    public function resolve(array $paths, ?string $message = null): MergeResult
    {
        if ($this->resolver === null) {
            throw new LogicException('This conflict was not raised by a disk merge, so it cannot resolve it.');
        }

        if ($this->mergeId() === null) {
            throw new LogicException('This conflict carries no merge id, so it cannot be resolved.');
        }

        return ($this->resolver)($paths, $message);
    }

    /**
     * Drop the merge; the target branch is untouched.
     */
    public function abort(): void
    {
        if ($this->aborter === null) {
            throw new LogicException('This conflict was not raised by a disk merge, so it cannot abort it.');
        }

        ($this->aborter)();
    }
}
