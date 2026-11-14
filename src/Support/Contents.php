<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Turns whatever a caller handed `put()` into a string.
 *
 * Single-file writes no longer come through here: they stream (api.md 3.4.2,
 * see Payload). This remains for the places that genuinely need bytes in
 * memory — the changes of a batch commit, whose JSON body carries them until
 * the blob endpoint lands (pickups/source-blob-upload.md).
 */
final class Contents
{
    public static function toString(mixed $contents): string
    {
        return Payload::from($contents)->contents();
    }
}
