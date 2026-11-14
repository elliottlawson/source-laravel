<?php

declare(strict_types=1);

namespace Source\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Source\SourceServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SourceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.source', [
            'driver' => 'source',
            'url' => 'https://source.test',
            'token' => 'asm_tok_test',
            'namespace' => 'acme',
            'repo' => 'workspace',
            'branch' => 'main',
            'throw' => true,
        ]);

        $app['config']->set('filesystems.disks.source_other', [
            'driver' => 'source',
            'url' => 'https://source.test',
            'token' => 'asm_tok_test',
            'namespace' => 'acme',
            'repo' => 'workspace',
            'branch' => 'main',
            'throw' => true,
        ]);
    }
}
