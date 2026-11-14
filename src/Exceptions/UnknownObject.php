<?php

declare(strict_types=1);

namespace Source\Exceptions;

/**
 * A change named a blob the repo does not hold (422 unknown_object, api.md
 * 3.4.3).
 *
 * Nothing was written. The client resends those paths' bytes and tries again,
 * which is what the driver does once before letting this escape.
 */
class UnknownObject extends ValidationFailed
{
    /**
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ((array) ($this->details['paths'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }

    /**
     * The oid the server refused for each path.
     *
     * @return array<string, string>
     */
    public function oids(): array
    {
        $oids = [];

        foreach ((array) ($this->details['paths'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null) && is_string($entry['oid'] ?? null)) {
                $oids[$entry['path']] = $entry['oid'];
            }
        }

        return $oids;
    }
}
