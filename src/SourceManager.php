<?php

declare(strict_types=1);

namespace Source;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Assert as PHPUnit;
use Source\Flysystem\SourceAdapter;
use Source\Http\Client;
use Source\Http\HttpClient;
use Source\Testing\FakeClient;
use Throwable;

/**
 * The package's entry point: builds disks, holds the author resolver, and owns
 * the fake used in tests.
 */
class SourceManager
{
    protected ?FakeClient $fake = null;

    protected ?Closure $authorResolver = null;

    public function __construct(protected Application $app) {}

    /**
     * Build the Laravel disk for one `source` disk configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function disk(array $config): SourceFilesystem
    {
        $config = $this->normalize($config);

        $store = new SourceStore(
            client: $this->client($config),
            namespace: $this->required($config, 'namespace'),
            repo: $this->required($config, 'repo'),
            ref: $config['branch'],
            authorResolver: fn (): ?Author => $this->resolveAuthor(),
        );

        $adapter = new SourceAdapter($store);

        return new SourceFilesystem(new Filesystem($adapter), $adapter, $config, $store);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function client(array $config): Client
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        $this->required($config, 'url');

        return new HttpClient($config);
    }

    /**
     * Swap the transport for an in-memory server that follows the same rules.
     *
     * Calling it twice returns the same fake: a disk resolved after the first
     * call keeps working. Use restore() to drop the fake between tests.
     */
    public function fake(): FakeClient
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        $this->fake = new FakeClient;
        $this->forgetSourceDisks();

        return $this->fake;
    }

    /**
     * Stop faking: the next disk resolves against the real transport.
     */
    public function restore(): void
    {
        $this->fake = null;
        $this->forgetSourceDisks();
    }

    public function isFaking(): bool
    {
        return $this->fake !== null;
    }

    public function fakeOrFail(): FakeClient
    {
        if ($this->fake === null) {
            throw new \RuntimeException('Source::fake() has not been called.');
        }

        return $this->fake;
    }

    /**
     * Forget disks already resolved by the filesystem manager, so a disk
     * resolved after fake() uses the fake.
     */
    protected function forgetSourceDisks(): void
    {
        try {
            $manager = $this->app->make('filesystem');
            $disks = (array) $this->app->make('config')->get('filesystems.disks', []);

            foreach ($disks as $name => $config) {
                if (is_array($config) && ($config['driver'] ?? null) === 'source') {
                    $manager->forgetDisk($name);
                }
            }
        } catch (Throwable) {
            // No filesystem manager (a bare container): nothing cached to
            // forget.
        }
    }

    // --- author ------------------------------------------------------------

    /**
     * Resolve the author for a write: the app's resolver, or the authenticated
     * user if there is one.
     */
    public function resolveAuthor(): ?Author
    {
        if ($this->authorResolver !== null) {
            return Author::from(($this->authorResolver)());
        }

        return Author::from($this->authenticatedUser());
    }

    public function resolveAuthorUsing(?Closure $resolver): static
    {
        $this->authorResolver = $resolver;

        return $this;
    }

    protected function authenticatedUser(): mixed
    {
        try {
            if (! $this->app->bound('auth')) {
                return null;
            }

            return $this->app->make('auth')->guard()->user();
        } catch (Throwable) {
            // No guard, no session, or a broken one: fall back to the
            // server's default author.
            return null;
        }
    }

    // --- assertions --------------------------------------------------------

    public function assertCommitted(string $message): void
    {
        PHPUnit::assertTrue(
            $this->fakeOrFail()->hasCommitted($message),
            "Expected a commit with message [{$message}], but none was made.",
        );
    }

    public function assertNotCommitted(string $message): void
    {
        PHPUnit::assertFalse(
            $this->fakeOrFail()->hasCommitted($message),
            "Expected no commit with message [{$message}], but one was made.",
        );
    }

    public function assertWritten(string $path): void
    {
        PHPUnit::assertTrue(
            $this->fakeOrFail()->hasWritten($path),
            "Expected [{$path}] to be written, but no commit touched it.",
        );
    }

    public function assertNothingWritten(string $path): void
    {
        PHPUnit::assertFalse(
            $this->fakeOrFail()->hasWritten($path),
            "Expected [{$path}] not to be written, but a commit touched it.",
        );
    }

    public function assertNothingCommitted(): void
    {
        PHPUnit::assertSame(
            [],
            $this->fakeOrFail()->commits(),
            'Expected no commits, but the fake recorded some.',
        );
    }

    /**
     * Assert that a commit reverts the given commit (`Reverts: <oid>`).
     */
    public function assertReverted(string $commitOid): void
    {
        PHPUnit::assertTrue(
            $this->fakeOrFail()->hasReverted($commitOid),
            "Expected a commit reverting [{$commitOid}], but none carried a Reverts trailer.",
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function normalize(array $config): array
    {
        $config['branch'] = (string) ($config['branch'] ?? 'main');

        // A remote write that fails should be loud by default; set
        // 'throw' => false for Laravel's usual return-false behaviour.
        $config['throw'] = (bool) ($config['throw'] ?? true);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function required(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The source disk requires a non-empty [{$key}] option.");
        }

        return $value;
    }
}
