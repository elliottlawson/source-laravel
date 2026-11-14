<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Source\Source;
use Source\SourceFilesystem;
use Source\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Conformance');

/**
 * A disk backed by a fresh in-memory source server.
 */
function fakeDisk(string $name = 'source'): SourceFilesystem
{
    Source::fake();

    return Storage::disk($name);
}

/*
 * Every test starts with a real transport and no cached source disks. A test
 * that wants the fake calls Source::fake() again, which then creates a fresh
 * in-memory server.
 */
beforeEach(function () {
    Source::restore();
});
