<?php

declare(strict_types=1);

namespace Source;

use Carbon\CarbonImmutable;
use Source\Support\Trailers;

/**
 * One commit from a repo's history.
 *
 * `message` is what the author wrote, with the trailers stripped; `meta` is
 * the trailer block parsed back into an array. Commits are read-only.
 */
final readonly class Commit
{
    /**
     * @param  array<string, string>  $meta
     * @param  list<string>  $parents
     */
    public function __construct(
        public string $oid,
        public string $message,
        public ?Author $author,
        public ?Author $committer,
        public CarbonImmutable $at,
        public array $meta = [],
        public array $parents = [],
        public ?string $ref = null,
    ) {}

    /**
     * Build a commit from the API's `commit` object.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $parsed = Trailers::parse(is_string($data['message'] ?? null) ? $data['message'] : '');

        $parents = [];

        foreach (($data['parents'] ?? []) as $parent) {
            if (is_string($parent) && $parent !== '') {
                $parents[] = $parent;
            }
        }

        return new self(
            oid: (string) ($data['oid'] ?? ''),
            message: $parsed['message'],
            author: self::person($data['author'] ?? null),
            committer: self::person($data['committer'] ?? null),
            at: self::time($data['created_at'] ?? null),
            meta: $parsed['meta'],
            parents: $parents,
            ref: is_string($data['ref'] ?? null) ? $data['ref'] : null,
        );
    }

    /**
     * The first line of the message.
     */
    public function subject(): string
    {
        return Trailers::subject($this->message);
    }

    public function parent(): ?string
    {
        return $this->parents[0] ?? null;
    }

    public function isMerge(): bool
    {
        return count($this->parents) > 1;
    }

    public function isRoot(): bool
    {
        return $this->parents === [];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private static function person(mixed $data): ?Author
    {
        if (! is_array($data)) {
            return null;
        }

        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($email) || $email === '') {
            return null;
        }

        return new Author($name, $email);
    }

    private static function time(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return CarbonImmutable::createFromTimestamp(0);
        }

        return CarbonImmutable::parse($value);
    }
}
