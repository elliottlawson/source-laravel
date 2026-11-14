<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Commit trailers: the `Key: value` block at the end of a message.
 *
 * `withMeta()` writes metadata as trailers, so history has to read them back
 * out. A trailer block is the last paragraph of the message, and every line in
 * it has to look like a trailer; otherwise the paragraph is part of the
 * message. This is the same rule `git interpret-trailers` uses.
 */
final class Trailers
{
    /**
     * Split a commit message into its message and its trailers.
     *
     * @return array{message: string, meta: array<string, string>}
     */
    public static function parse(string $message): array
    {
        $message = str_replace("\r\n", "\n", $message);
        $trimmed = rtrim($message, "\n");

        if ($trimmed === '') {
            return ['message' => '', 'meta' => []];
        }

        $paragraphs = preg_split("/\n{2,}/", $trimmed) ?: [];

        if (count($paragraphs) < 2) {
            return ['message' => $trimmed, 'meta' => []];
        }

        $meta = self::parseBlock((string) end($paragraphs));

        if ($meta === []) {
            return ['message' => $trimmed, 'meta' => []];
        }

        array_pop($paragraphs);

        return [
            'message' => rtrim(implode("\n\n", $paragraphs)),
            'meta' => $meta,
        ];
    }

    /**
     * The message's first line.
     */
    public static function subject(string $message): string
    {
        $subject = strtok($message, "\n");

        return $subject === false ? '' : trim($subject);
    }

    /**
     * @return array<string, string>
     */
    private static function parseBlock(string $block): array
    {
        $meta = [];

        foreach (explode("\n", trim($block)) as $line) {
            if (! preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]*): (.*)$/', $line, $matches)) {
                return [];
            }

            $meta[$matches[1]] = $matches[2];
        }

        return $meta;
    }
}
