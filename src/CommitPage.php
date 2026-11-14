<?php

declare(strict_types=1);

namespace Source;

/**
 * One page of the commit log.
 *
 * `nextCursor` is opaque: the only thing a client may do with it is hand it
 * back for the next page.
 */
final readonly class CommitPage
{
    /**
     * @param  list<Commit>  $commits
     */
    public function __construct(
        public array $commits,
        public ?string $nextCursor = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null && $this->nextCursor !== '';
    }
}
