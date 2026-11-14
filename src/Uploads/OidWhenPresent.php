<?php

declare(strict_types=1);

namespace Source\Uploads;

use Source\Change;
use Source\Http\Client;

/**
 * Sends bytes only for the changes the repo does not already hold.
 *
 * api.md 3.4.3: hash each put the way Git does, ask `objects/present`, and name
 * a blob instead of carrying it. Re-committing identical content, or copying a
 * file the repo already has, then costs no bytes.
 *
 * The answer is a hint — a blob present now can be collected later — so a
 * commit that names one may still be refused with `422 unknown_object`; the
 * driver answers that by preparing again with those paths forced inline.
 *
 * `ParallelBlobUploads` is the default now that `POST /blobs` exists (api.md
 * 3.4.5); this class remains for callers that would rather send every missing
 * file inline than make an upload request per file.
 */
final class OidWhenPresent implements Uploader
{
    /**
     * The documented request bound for `objects/present`.
     */
    public const CHUNK = 1000;

    public function __construct(private readonly Client $client) {}

    /**
     * @param  list<Change>  $changes
     * @param  array<string, true>  $forceUpload
     * @return list<Change>
     */
    public function prepare(string $namespace, string $repo, string $ref, array $changes, array $forceUpload = []): array
    {
        $wanted = [];

        foreach ($changes as $change) {
            if ($change->isDelete() || $change->isReference() || isset($forceUpload[$change->path])) {
                continue;
            }

            $wanted[$change->path] = $change->blobOid();
        }

        if ($wanted === []) {
            return $changes;
        }

        $present = [];

        foreach (array_chunk(array_values(array_unique($wanted)), self::CHUNK) as $chunk) {
            foreach ($this->client->presentObjects($namespace, $repo, $chunk) as $oid) {
                $present[$oid] = true;
            }
        }

        if ($present === []) {
            return $changes;
        }

        $prepared = [];

        foreach ($changes as $change) {
            $oid = $wanted[$change->path] ?? null;

            $prepared[] = $oid !== null && isset($present[$oid])
                ? $change->asReference($oid)
                : $change;
        }

        return $prepared;
    }
}
