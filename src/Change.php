<?php

declare(strict_types=1);

namespace Source;

use Source\Support\Payload;

/**
 * One change inside a batch commit.
 *
 * The expected version is the object id of the file the writer last saw. A
 * plain change (expected === null) has its version filled in by the driver
 * just before the request; a strict change carries the version the caller
 * named and never retries.
 *
 * Exactly one of `content`, `payload`, `oid` and `delete` says where the bytes
 * come from: `content` is a string in memory, `payload` is a stream (a
 * single-file write streams it to the server), `oid` names a blob the repo
 * already holds, and `delete` removes the file.
 */
final readonly class Change
{
    public function __construct(
        public string $path,
        public ?string $expectedOid,
        public ?string $content = null,
        public bool $delete = false,
        public ?string $mode = null,
        public ?string $oid = null,
        public ?Payload $payload = null,
    ) {}

    public static function put(string $path, string $expectedOid, string $content, ?string $mode = null): self
    {
        return new self($path, $expectedOid, $content, false, $mode);
    }

    /**
     * A put whose bytes may be a stream: nothing is read into memory.
     */
    public static function stream(string $path, string $expectedOid, Payload $payload, ?string $mode = null): self
    {
        return new self($path, $expectedOid, null, false, $mode, null, $payload);
    }

    /**
     * A put that names a blob the repo already holds, instead of its bytes.
     */
    public static function reference(string $path, ?string $expectedOid, string $oid, ?string $mode = null): self
    {
        return new self($path, $expectedOid, null, false, $mode, $oid);
    }

    public static function delete(string $path, string $expectedOid): self
    {
        return new self($path, $expectedOid, null, true);
    }

    public function isDelete(): bool
    {
        return $this->delete;
    }

    /**
     * Whether this change names a blob rather than carrying its bytes.
     */
    public function isReference(): bool
    {
        return $this->oid !== null;
    }

    /**
     * The same change, naming the blob its bytes would produce.
     */
    public function asReference(string $oid): self
    {
        return new self($this->path, $this->expectedOid, null, false, $this->mode, $oid);
    }

    /**
     * The Git blob id this change writes, or null for a delete.
     */
    public function blobOid(): ?string
    {
        if ($this->delete) {
            return null;
        }

        if ($this->oid !== null) {
            return $this->oid;
        }

        return $this->payload?->oid() ?? Payload::blobOid((string) $this->content);
    }

    /**
     * The change as the API wants it.
     *
     * A payload change has no place in a JSON body; it is only ever a
     * single-file write, which streams. If one reaches a batch
     * body after all, its bytes are inlined rather than silently dropped.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $change = [
            'path' => $this->path,
            'expected_oid' => $this->expectedOid ?? '',
        ];

        if ($this->delete) {
            $change['delete'] = true;
        } elseif ($this->oid !== null) {
            $change['oid'] = $this->oid;
        } else {
            $contents = $this->payload?->contents() ?? (string) $this->content;

            if ($this->isUtf8($contents)) {
                $change['content'] = $contents;
            } else {
                $change['content_base64'] = base64_encode($contents);
            }
        }

        if ($this->mode !== null) {
            $change['mode'] = $this->mode;
        }

        return $change;
    }

    private function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
