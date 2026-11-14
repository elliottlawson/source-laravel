<?php

declare(strict_types=1);

namespace Source\Support;

use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Stringable;
use Throwable;

/**
 * The bytes of one file, ready to be streamed to the server.
 *
 * A raw file write (api.md 3.4.2) needs three things the caller's stream may
 * not offer: a known size (for `Content-Length`, which the server requires), a
 * rewindable body (a last-write-wins retry after `409 file_changed` has to
 * send the same bytes again) and a Git blob id (to skip bytes the server
 * already holds, api.md 3.4.3). This object provides all three without ever
 * reading a stream into memory:
 *
 *  - a seekable resource or stream is measured in place and streamed from its
 *    current position;
 *  - a non-seekable one is spooled once to `php://temp` (1 MiB in memory, the
 *    rest on disk), which is also what makes it rewindable;
 *  - the blob id is hashed incrementally with `hash_update_stream`.
 *
 * The body is never buffered. `contents()` exists for the in-memory fake, which
 * has to hold the bytes to model a server.
 *
 * @internal
 */
final class Payload
{
    /**
     * Bytes `php://temp` keeps in memory before it spills to a temp file.
     */
    private const SPOOL_MEMORY = 1_048_576;

    /**
     * The stream we hand out, when it is our own wrapper around a resource.
     */
    private ?StreamInterface $wrapper = null;

    /**
     * @param  string|null  $text  A string body; then `$resource` is null.
     * @param  resource|null  $resource  A body read from a resource.
     * @param  StreamInterface|null  $foreign  A body handed in as a PSR-7 stream.
     */
    private function __construct(
        private readonly ?string $text,
        private $resource,
        private readonly ?StreamInterface $foreign,
        private readonly int $start,
        private readonly int $size,
        private ?string $oid = null,
    ) {}

    /**
     * Whatever `put()` was handed: a string, a resource, a PSR-7 stream, a
     * Stringable, or null for an empty file.
     */
    public static function from(mixed $contents): self
    {
        if ($contents === null) {
            return new self('', null, null, 0, 0);
        }

        if (is_string($contents)) {
            return new self($contents, null, null, 0, strlen($contents));
        }

        if ($contents instanceof Stringable) {
            $text = (string) $contents;

            return new self($text, null, null, 0, strlen($text));
        }

        if ($contents instanceof StreamInterface) {
            return self::fromStream($contents);
        }

        if (is_resource($contents)) {
            return self::fromStream(Utils::streamFor($contents), streaming: true);
        }

        throw new InvalidArgumentException('Unsupported contents: expected a string, stream or Stringable.');
    }

    /**
     * The Git blob id of a string: sha1("blob <len>\0<bytes>").
     */
    public static function blobOid(string $contents): string
    {
        return sha1('blob '.strlen($contents)."\0".$contents);
    }

    /**
     * The length of the body in bytes.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * The Git blob id of the body, computed without buffering it.
     */
    public function oid(): string
    {
        if ($this->oid !== null) {
            return $this->oid;
        }

        if ($this->text !== null) {
            return $this->oid = self::blobOid($this->text);
        }

        $hash = hash_init('sha1');
        hash_update($hash, 'blob '.$this->size."\0");

        if ($this->resource !== null) {
            $this->seek($this->resource, $this->start);
            hash_update_stream($hash, $this->resource);
            $this->seek($this->resource, $this->start);
        } else {
            $stream = $this->foreign;
            $stream->seek($this->start);

            while (! $stream->eof()) {
                $chunk = $stream->read(1_048_576);

                if ($chunk === '') {
                    break;
                }

                hash_update($hash, $chunk);
            }

            $stream->seek($this->start);
        }

        return $this->oid = hash_final($hash);
    }

    /**
     * The whole body as a string. Only the in-memory fake needs this; the
     * real transport streams instead.
     */
    public function contents(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        if ($this->resource !== null) {
            $this->seek($this->resource, $this->start);

            $read = stream_get_contents($this->resource);

            return $read === false ? '' : $read;
        }

        $stream = $this->foreign;
        $stream->seek($this->start);

        return $stream->getContents();
    }

    /**
     * The body for one HTTP attempt: a string, or a stream Guzzle can send
     * without holding the file.
     *
     * @return string|StreamInterface
     */
    public function body(): string|StreamInterface
    {
        if ($this->text !== null) {
            return $this->text;
        }

        $this->rewind();

        if ($this->foreign !== null) {
            return $this->foreign;
        }

        return $this->wrapper ??= Utils::streamFor($this->resource);
    }

    /**
     * Put the body back at its first byte, so a retried request sends the same
     * bytes (api.md 3.4.2, a last-write-wins retry after 409 file_changed).
     */
    public function rewind(): void
    {
        if ($this->resource !== null) {
            $this->seek($this->resource, $this->start);
        }

        if ($this->foreign !== null) {
            $this->foreign->seek($this->start);
        }
    }

    /**
     * Let go of the stream wrapper handed to the HTTP client.
     *
     * Guzzle closes the underlying resource when its stream is destroyed, and
     * that resource belongs to the caller: detach first, so a `putFile()` does
     * not close a file the caller still owns.
     */
    public function release(): void
    {
        if ($this->wrapper !== null) {
            $this->wrapper->detach();
            $this->wrapper = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Measure a PSR-7 stream in place, or spool it when it cannot be rewound.
     */
    private static function fromStream(StreamInterface $stream, bool $streaming = false): self
    {
        if ($stream->isSeekable()) {
            try {
                $start = $stream->tell();
                $stream->seek(0, SEEK_END);
                $end = $stream->tell();
                $stream->seek($start);
            } catch (Throwable) {
                return self::spool($stream);
            }

            if ($end >= $start) {
                $size = $end - $start;

                // A resource was handed in: keep the resource itself, so the
                // wrapper can be detached after each attempt.
                $resource = $streaming ? self::unwrapped($stream) : null;

                return $resource === null
                    ? new self(null, null, $stream, $start, $size)
                    : new self(null, $resource, null, $start, $size);
            }
        }

        return self::spool($stream);
    }

    /**
     * Copy a non-seekable stream to php://temp so its size is known and the
     * body can be sent again.
     */
    private static function spool(StreamInterface $stream): self
    {
        $temp = Utils::streamFor(fopen('php://temp/maxmemory:'.self::SPOOL_MEMORY, 'r+'));

        while (! $stream->eof()) {
            $chunk = $stream->read(1_048_576);

            if ($chunk === '') {
                break;
            }

            $temp->write($chunk);
        }

        $size = $temp->tell();
        $temp->seek(0);

        return new self(null, self::unwrapped($temp), null, 0, $size);
    }

    /**
     * The resource behind a PSR-7 stream, taken without closing it.
     *
     * @return resource
     */
    private static function unwrapped(StreamInterface $stream)
    {
        $resource = $stream instanceof \GuzzleHttp\Psr7\Stream ? $stream->detach() : null;

        if (! is_resource($resource)) {
            // Not one of Guzzle's own streams (a PumpStream, say): read it
            // through PHP so the payload still owns a plain resource.
            return self::reopen($stream);
        }

        return $resource;
    }

    /**
     * @return resource
     */
    private static function reopen(StreamInterface $stream)
    {
        $temp = fopen('php://temp/maxmemory:'.self::SPOOL_MEMORY, 'r+');

        if ($temp === false) {
            throw new InvalidArgumentException('Could not open a temporary stream.');
        }

        $stream->rewind();

        while (! $stream->eof()) {
            $chunk = $stream->read(1_048_576);

            if ($chunk === '') {
                break;
            }

            fwrite($temp, $chunk);
        }

        rewind($temp);

        return $temp;
    }

    /**
     * @param  resource  $resource
     */
    private function seek($resource, int $offset): void
    {
        fseek($resource, $offset);
    }
}
