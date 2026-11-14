<?php

declare(strict_types=1);

namespace Source\Support;

use GuzzleHttp\Psr7\Stream;
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
 * rewindable body (a retry has to send the same bytes again) and a Git blob id
 * (to skip bytes the server already holds, api.md 3.4.3). This object provides
 * all three without ever reading a stream into memory:
 *
 *  - a stream at its first byte is measured in place and streamed as it is;
 *  - a stream that is not at its first byte, or cannot be seeked, is spooled
 *    once to `php://temp` (1 MiB in memory, the rest on disk), which also makes
 *    it rewindable. The HTTP client rewinds a request body to absolute zero, so
 *    a body that starts anywhere else has to become its own stream first;
 *  - the blob id is hashed incrementally with `hash_update_stream`.
 *
 * The body is never buffered. `contents()` exists for the in-memory fake, which
 * has to hold the bytes to model a server.
 *
 * It is the body type of `Client::putFile()`, so a transport other than the two
 * in this package receives one too.
 */
final class Payload
{
    /**
     * Bytes `php://temp` keeps in memory before it spills to a temp file.
     */
    private const SPOOL_MEMORY = 1_048_576;

    /**
     * The stream we handed to the HTTP client, when it is our own wrapper
     * around a resource.
     */
    private ?StreamInterface $wrapper = null;

    /**
     * @param  string|null  $text  A string body; then nothing else is set.
     * @param  resource|null  $resource  A body read from a resource.
     * @param  StreamInterface|null  $foreign  A body handed in as a PSR-7 stream.
     */
    private function __construct(
        private readonly ?string $text,
        private $resource,
        private readonly ?StreamInterface $foreign,
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
            return new self('', null, null, 0);
        }

        if (is_string($contents)) {
            return new self($contents, null, null, strlen($contents));
        }

        if ($contents instanceof StreamInterface) {
            return self::fromStream($contents, keepStream: true);
        }

        if ($contents instanceof Stringable) {
            $text = (string) $contents;

            return new self($text, null, null, strlen($text));
        }

        if (is_resource($contents)) {
            return self::fromStream(Utils::streamFor($contents));
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
            fseek($this->resource, 0);
            hash_update_stream($hash, $this->resource);
            fseek($this->resource, 0);
        } else {
            $stream = $this->foreign;
            $stream->rewind();

            while (! $stream->eof()) {
                $chunk = $stream->read(1_048_576);

                if ($chunk === '') {
                    break;
                }

                hash_update($hash, $chunk);
            }

            $stream->rewind();
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
            fseek($this->resource, 0);

            $read = stream_get_contents($this->resource);

            return $read === false ? '' : $read;
        }

        $this->foreign->rewind();

        return $this->foreign->getContents();
    }

    /**
     * The body for one HTTP attempt: a string, or a stream Guzzle can send
     * without holding the file.
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
            fseek($this->resource, 0);
        }

        if ($this->foreign !== null) {
            $this->foreign->rewind();
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
     * Measure a stream from its first byte, or spool what cannot be sent as it
     * is.
     */
    private static function fromStream(StreamInterface $stream, bool $keepStream = false): self
    {
        if (! $stream->isSeekable()) {
            return self::spool($stream);
        }

        try {
            // The HTTP client rewinds the body to absolute zero, so a stream
            // positioned anywhere else has to be copied first.
            if ($stream->tell() !== 0) {
                return self::spool($stream);
            }

            $stream->seek(0, SEEK_END);
            $size = $stream->tell();
            $stream->seek(0);
        } catch (Throwable) {
            return self::spool($stream);
        }

        if ($size < 0) {
            return self::spool($stream);
        }

        if ($keepStream) {
            return new self(null, null, $stream, $size);
        }

        // A resource was handed in: keep the resource itself, so the wrapper
        // can be detached after each attempt.
        $resource = $stream instanceof Stream ? $stream->detach() : null;

        return is_resource($resource)
            ? new self(null, $resource, null, $size)
            : self::spool($stream);
    }

    /**
     * Copy a stream to php://temp so its size is known and the body can be
     * sent again.
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

        return new self(null, self::detach($temp), null, $size);
    }

    /**
     * Take the resource out of one of Guzzle's streams without closing it.
     *
     * @return resource
     */
    private static function detach(StreamInterface $stream)
    {
        $resource = $stream instanceof Stream ? $stream->detach() : null;

        if (is_resource($resource)) {
            return $resource;
        }

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
}
