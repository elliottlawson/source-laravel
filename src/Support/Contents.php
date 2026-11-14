<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Turns whatever a caller handed `put()` into a string.
 *
 * Single-file writes no longer come through here: they stream (api.md 3.4.2,
 * see Payload). This remains for the places that genuinely need bytes in
 * memory — the changes queued by a `commit()` closure. Those bytes then either
 * upload as their own blob (api.md 3.4.5) or, when they are tiny, travel inside
 * the commit body; either way the closure's queued change is a string.
 */
final class Contents
{
    public static function toString(mixed $contents): string
    {
        return Payload::from($contents)->contents();
    }
}
