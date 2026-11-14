<?php

declare(strict_types=1);

namespace Source;

/**
 * One change inside a batch commit.
 *
 * The expected version is the object id of the file the writer last saw. A
 * plain change (expected === null) has its version filled in by the driver
 * just before the request; a strict change carries the version the caller
 * named and never retries.
 */
final readonly class Change
{
    public function __construct(
        public string $path,
        public ?string $expectedOid,
        public ?string $content = null,
        public bool $delete = false,
        public ?string $mode = null,
    ) {}

    public static function put(string $path, string $expectedOid, string $content, ?string $mode = null): self
    {
        return new self($path, $expectedOid, $content, false, $mode);
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
     * The change as the API wants it (api.md 3.14).
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
        } elseif ($this->isUtf8((string) $this->content)) {
            $change['content'] = (string) $this->content;
        } else {
            $change['content_base64'] = base64_encode((string) $this->content);
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
