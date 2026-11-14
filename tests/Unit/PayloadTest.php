<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Source\Support\Payload;

/*
 * The body of one file: a known size, a rewindable stream and a Git blob id,
 * whatever the caller handed in.
 */

it('reads a string as its own bytes', function () {
    $payload = Payload::from("hello\n");

    expect($payload->size())->toBe(6)
        ->and($payload->oid())->toBe(sha1("blob 6\0hello\n"))
        ->and($payload->contents())->toBe("hello\n")
        ->and($payload->body())->toBe("hello\n");
});

it('treats null as an empty file', function () {
    $payload = Payload::from(null);

    expect($payload->size())->toBe(0)
        ->and($payload->oid())->toBe(sha1("blob 0\0"))
        ->and($payload->contents())->toBe('');
});

it('reads a Stringable as its string', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'from an object';
        }
    };

    expect(Payload::from($stringable)->contents())->toBe('from an object');
});

it('sends a seekable resource from its current position', function () {
    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'skip me|the body');
    fseek($resource, 8);

    $payload = Payload::from($resource);

    expect($payload->size())->toBe(strlen('the body'))
        ->and($payload->oid())->toBe(sha1('blob '.strlen('the body')."\0".'the body'))
        ->and($payload->contents())->toBe('the body');

    // The payload is rewindable: the same bytes again after a failed attempt.
    $body = $payload->body();

    expect($body)->toBeInstanceOf(StreamInterface::class)
        ->and((string) $body)->toBe('the body');

    $payload->rewind();

    expect((string) $payload->body())->toBe('the body');
});

it('spools a non-seekable stream so it has a length and can be resent', function () {
    [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    fwrite($writer, 'bytes over a pipe');
    fclose($writer);

    $payload = Payload::from($reader);

    expect($payload->size())->toBe(strlen('bytes over a pipe'))
        ->and($payload->oid())->toBe(sha1('blob '.strlen('bytes over a pipe')."\0".'bytes over a pipe'));

    $first = (string) $payload->body();

    $payload->rewind();

    $second = (string) $payload->body();

    expect($first)->toBe('bytes over a pipe')
        ->and($second)->toBe('bytes over a pipe');
});

it('hashes a stream without reading it into memory', function () {
    $size = 8 * 1024 * 1024;
    $resource = fopen('php://temp/maxmemory:1048576', 'r+');
    $chunk = str_repeat('z', 1024 * 1024);

    for ($written = 0; $written < $size; $written += strlen($chunk)) {
        fwrite($resource, $chunk);
    }

    rewind($resource);
    unset($chunk);

    $payload = Payload::from($resource);

    $before = memory_get_peak_usage(true);
    $oid = $payload->oid();
    $peak = memory_get_peak_usage(true) - $before;

    $hash = hash_init('sha1');
    hash_update($hash, "blob {$size}\0");
    hash_update($hash, str_repeat('z', $size));

    expect($payload->size())->toBe($size)
        ->and($oid)->toBe(hash_final($hash))
        ->and($peak)->toBeLessThan(4 * 1024 * 1024);
});

it('wraps a PSR-7 stream as it is', function () {
    $stream = Utils::streamFor(str_repeat('ab', 10));

    $payload = Payload::from($stream);

    expect($payload->size())->toBe(20)
        ->and($payload->oid())->toBe(sha1("blob 20\0".str_repeat('ab', 10)))
        ->and($payload->body() === $stream)->toBeTrue();
});
