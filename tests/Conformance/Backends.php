<?php

declare(strict_types=1);

namespace Source\Tests\Conformance;

use Illuminate\Support\Facades\Storage;
use Source\Source;
use Source\SourceFilesystem;
use Source\Tests\TestCase;

/**
 * Builds the two disk instances a conformance test writes through.
 *
 * Two disks, not one: "another writer changed the file" is the whole point of
 * the version rules, and a second disk is a second client on the same server.
 *
 * The fake is always available. The live backend is opt-in via
 * SOURCE_TEST_URL, SOURCE_TEST_TOKEN, SOURCE_TEST_NAMESPACE and
 * SOURCE_TEST_REPO; without them the live run of each test is skipped.
 */
final class Backends
{
    /**
     * @return array{0: SourceFilesystem, 1: SourceFilesystem}
     */
    public static function fake(): array
    {
        Source::fake();

        return [Storage::disk('source'), Storage::disk('source_other')];
    }

    /**
     * @return array{0: SourceFilesystem, 1: SourceFilesystem}|null
     */
    public static function live(): ?array
    {
        $config = [
            'driver' => 'source',
            'url' => env('SOURCE_TEST_URL'),
            'token' => env('SOURCE_TEST_TOKEN'),
            'namespace' => env('SOURCE_TEST_NAMESPACE'),
            'repo' => env('SOURCE_TEST_REPO'),
            'branch' => env('SOURCE_TEST_BRANCH') ?: 'main',
            'throw' => true,
        ];

        foreach (['url', 'token', 'namespace', 'repo'] as $key) {
            if (! is_string($config[$key]) || $config[$key] === '') {
                return null;
            }
        }

        config()->set('filesystems.disks.source_live', $config);
        config()->set('filesystems.disks.source_live_other', $config);

        return [Storage::disk('source_live'), Storage::disk('source_live_other')];
    }

    /**
     * A second repo in the same namespace, for the repo() isolation test.
     *
     * The fake always has one. The live run needs SOURCE_TEST_REPO_OTHER,
     * because creating a repo is an operator-scoped call and the conformance
     * token is a repo token.
     */
    public static function otherRepo(): ?string
    {
        $repo = env('SOURCE_TEST_REPO_OTHER');

        return is_string($repo) && $repo !== '' ? $repo : null;
    }

    /**
     * @return array{0: SourceFilesystem, 1: SourceFilesystem, 2: string}
     */
    public static function make(TestCase $test, string $backend): array
    {
        $disks = $backend === 'live' ? self::live() : self::fake();

        if ($disks === null) {
            $test->markTestSkipped(
                'Set SOURCE_TEST_URL, SOURCE_TEST_TOKEN, SOURCE_TEST_NAMESPACE and SOURCE_TEST_REPO to run the live conformance suite.',
            );
        }

        return [$disks[0], $disks[1], 'conformance/'.bin2hex(random_bytes(6))];
    }
}
