<?php

declare(strict_types=1);

namespace Source\Support;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Stringable;

/**
 * Turns whatever a caller handed `put()` into bytes.
 *
 * The package sends JSON bodies for now (api.md 3.4), so streams are read
 * into memory here. Raw-bytes streaming (api.md 3.4.2) is the place to stop
 * doing this.
 */
final class Contents
{
    public static function toString(mixed $contents): string
    {
        if (is_string($contents)) {
            return $contents;
        }

        if (is_resource($contents)) {
            $read = stream_get_contents($contents);

            return $read === false ? '' : $read;
        }

        if ($contents instanceof StreamInterface) {
            return $contents->__toString();
        }

        if ($contents instanceof Stringable) {
            return (string) $contents;
        }

        if ($contents === null) {
            return '';
        }

        throw new InvalidArgumentException('Unsupported contents: expected a string, stream or Stringable.');
    }
}
