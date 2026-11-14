<?php

declare(strict_types=1);

namespace Source;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Source\SourceFilesystem disk(array $config)
 * @method static \Source\Http\Client client(array $config)
 * @method static \Source\Testing\FakeClient fake()
 * @method static bool isFaking()
 * @method static \Source\Testing\FakeClient fakeOrFail()
 * @method static void restore()
 * @method static \Source\Author|null resolveAuthor()
 * @method static \Source\SourceManager resolveAuthorUsing(\Closure|null $resolver)
 * @method static void assertCommitted(string $message)
 * @method static void assertNotCommitted(string $message)
 * @method static void assertWritten(string $path)
 * @method static void assertNothingWritten(string $path)
 * @method static void assertNothingCommitted()
 *
 * @see SourceManager
 */
class Source extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SourceManager::class;
    }
}
