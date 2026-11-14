<?php

declare(strict_types=1);

namespace Source;

use Illuminate\Support\ServiceProvider;

class SourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourceManager::class, fn ($app) => new SourceManager($app));
    }

    public function boot(): void
    {
        $this->app->make('filesystem')->extend('source', function ($app, array $config) {
            return $app->make(SourceManager::class)->disk($config);
        });
    }
}
