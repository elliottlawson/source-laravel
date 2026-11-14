<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Source\Author;
use Source\Exceptions\FileChanged;
use Source\Source;
use Source\Testing\FakeClient;

it('asserts commits and writes on the fake', function () {
    $disk = fakeDisk();

    Source::assertNothingCommitted();

    $disk->put('notes/plan.md', "# Plan\n");

    Source::assertCommitted('Create notes/plan.md');
    Source::assertNotCommitted('Create notes/other.md');
    Source::assertWritten('notes/plan.md');
    Source::assertNothingWritten('notes/other.md');
});

it('generates Create, Update, Delete and Move messages', function () {
    $disk = fakeDisk();

    $disk->put('a.md', "one\n");
    $disk->put('a.md', "two\n");
    $disk->delete('a.md');

    $disk->put('b.md', "b\n");
    $disk->move('b.md', 'c.md');
    $disk->put('d.md', "d\n");
    $disk->copy('d.md', 'e.md');

    Source::assertCommitted('Create a.md');
    Source::assertCommitted('Update a.md');
    Source::assertCommitted('Delete a.md');
    Source::assertCommitted('Move b.md to c.md');
    Source::assertCommitted('Copy d.md to e.md');
});

it('makes a strict write fail after an outside change', function () {
    $disk = fakeDisk();
    Source::assertNothingCommitted();

    $disk->put('notes/plan.md', "original\n");
    $version = $disk->version('notes/plan.md');

    Source::fake()->simulateChange('notes/plan.md');

    expect(fn () => $disk->ifUnchanged($version)->put('notes/plan.md', "mine\n"))
        ->toThrow(FileChanged::class);

    expect($disk->get('notes/plan.md'))->not->toBe("original\n");
});

it('records the author and metadata as trailers', function () {
    $disk = fakeDisk();

    $disk->as(new Author('Elliott', 'elliott@example.com'))
        ->withMeta(['run' => '01JRUN', 'conversation' => '01JCONV'])
        ->put('notes/plan.md', "# Plan\n");

    $commit = Source::fakeOrFail()->commits()[0];

    expect($commit['author']->name)->toBe('Elliott')
        ->and($commit['author']->email)->toBe('elliott@example.com')
        ->and($commit['message'])->toBe("Create notes/plan.md\n\nrun: 01JRUN\nconversation: 01JCONV");
});

it('keeps the shared disk immutable when configured copies are made', function () {
    $disk = fakeDisk();

    $configured = $disk->as(new Author('Someone', 'someone@example.com'))
        ->withMeta(['run' => '01JRUN']);

    $disk->put('a.md', "a\n");
    $configured->put('b.md', "b\n");

    $commits = Source::fakeOrFail()->commits();

    expect($commits[0]['author'])->toBeNull()
        ->and($commits[0]['message'])->toBe('Create a.md')
        ->and($commits[1]['author']->name)->toBe('Someone')
        ->and($commits[1]['message'])->toBe("Create b.md\n\nrun: 01JRUN");

    // The original disk still writes its own author and metadata, not the
    // configured copy's.
    $disk->withMeta(['run' => 'other'])->put('c.md', "c\n");

    expect(Source::fakeOrFail()->commits()[2]['message'])->toBe("Create c.md\n\nrun: other");
});

it('resolves the author through the app resolver', function () {
    $disk = fakeDisk();

    Source::resolveAuthorUsing(fn () => ['name' => 'Resolver', 'email' => 'r@example.com']);

    $disk->put('a.md', "a\n");

    expect(Source::fakeOrFail()->commits()[0]['author']->name)->toBe('Resolver');
});

it('falls back to the authenticated user for the author', function () {
    $disk = fakeDisk();

    $this->app->instance('auth', new class
    {
        public function guard(): object
        {
            return new class
            {
                public function user(): object
                {
                    return new Author('Signed In', 'signed@example.com');
                }
            };
        }
    });

    $disk->put('a.md', "a\n");

    expect(Source::fakeOrFail()->commits()[0]['author']->name)->toBe('Signed In');
});

it('reads the stored file inside a commit closure, not the pending write', function () {
    $disk = fakeDisk();

    $disk->put('a.md', "stored\n");

    $seen = null;

    $disk->commit('Change a', function ($files) use (&$seen) {
        $files->put('a.md', "pending\n");
        $seen = $files->get('a.md');
    });

    expect($seen)->toBe("stored\n")
        ->and($disk->get('a.md'))->toBe("pending\n")
        ->and(Source::fakeOrFail()->contents('a.md'))->toBe("pending\n");
});

it('retries a plain write when another writer lands in between', function () {
    $fake = Source::fake();
    $disk = Storage::disk('source');
    $other = Storage::disk('source_other');

    $disk->put('a.md', "one\n");

    $raced = false;

    $fake->beforeWrite(function (FakeClient $fake) use (&$raced, $other) {
        if ($raced) {
            return;
        }

        $raced = true;
        $other->put('a.md', "concurrent\n");
    });

    expect($disk->put('a.md', "mine\n"))->toBeTrue();

    $fake->beforeWrite(null);

    expect($raced)->toBeTrue()
        ->and($disk->get('a.md'))->toBe("mine\n");
});

it('gives up after three attempts and wraps FileChanged', function () {
    $fake = Source::fake();
    $disk = Storage::disk('source');
    $other = Storage::disk('source_other');

    $disk->put('a.md', "one\n");

    $attempts = 0;

    $fake->beforeWrite(function (FakeClient $fake) use (&$attempts, $other) {
        $attempts++;
        $other->put('a.md', "concurrent {$attempts}\n");
    });

    try {
        $disk->put('a.md', "mine\n");
        $this->fail('The write was accepted.');
    } catch (UnableToWriteFile $e) {
        expect($e->getPrevious())->toBeInstanceOf(FileChanged::class)
            ->and($e->getPrevious()->paths)->toBe(['a.md']);
    }

    expect($attempts)->toBe(3);
});

it('honours the throw option and returns false instead', function () {
    config()->set('filesystems.disks.source_quiet', [
        'driver' => 'source',
        'namespace' => 'acme',
        'repo' => 'workspace',
        'throw' => false,
    ]);

    $fake = Source::fake();
    $disk = Storage::disk('source_quiet');
    $other = Storage::disk('source_other');

    $disk->put('a.md', "one\n");

    $fake->beforeWrite(fn (FakeClient $fake) => $other->put('a.md', 'concurrent '.uniqid()));

    expect($disk->put('a.md', "mine\n"))->toBeFalse();

    $fake->beforeWrite(null);
});

it('stores uploads and streams through the driver', function () {
    $disk = fakeDisk();

    $file = UploadedFile::fake()->createWithContent('report.txt', "report body\n");

    $stored = $file->store('uploads', 'source');

    expect($stored)->toStartWith('uploads/')
        ->and($disk->get($stored))->toBe("report body\n");

    $path = $disk->putFileAs('uploads', UploadedFile::fake()->createWithContent('notes.md', "notes\n"), 'notes.md');

    expect($path)->toBe('uploads/notes.md')
        ->and($disk->get($path))->toBe("notes\n");

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, "from a stream\n");
    rewind($stream);

    expect($disk->put('streams/a.md', $stream))->toBeTrue()
        ->and($disk->get('streams/a.md'))->toBe("from a stream\n");
});

it('refuses a commit with no changes', function () {
    $disk = fakeDisk();

    expect(fn () => $disk->commit('Empty', function ($files) {
        // nothing
    }))->toThrow(InvalidArgumentException::class, 'at least one change');
});

it('passes author and meta through commit()', function () {
    $disk = fakeDisk();

    $disk->commit(
        'Draft Q3 plan',
        function ($files) {
            $files->put('notes/plan.md', "# Plan\n");
        },
        author: ['name' => 'Elliott', 'email' => 'elliott@example.com'],
        meta: ['run' => '01JRUN'],
    );

    $commit = Source::fakeOrFail()->commits()[0];

    expect($commit['message'])->toBe("Draft Q3 plan\n\nrun: 01JRUN")
        ->and($commit['author']->name)->toBe('Elliott');
});

it('deletes a whole directory in one commit', function () {
    $disk = fakeDisk();

    $disk->put('notes/a.md', "a\n");
    $disk->put('notes/sub/b.md', "b\n");
    $disk->put('other/c.md', "c\n");

    expect($disk->deleteDirectory('notes'))->toBeTrue()
        ->and($disk->fileExists('notes/a.md'))->toBeFalse()
        ->and($disk->fileExists('notes/sub/b.md'))->toBeFalse()
        ->and($disk->fileExists('other/c.md'))->toBeTrue();

    Source::assertCommitted('Delete notes');
});

it('reports size, last modified and mime type', function () {
    $disk = fakeDisk();

    $disk->put('notes/plan.md', "# Plan\n");

    expect($disk->size('notes/plan.md'))->toBe(7)
        ->and($disk->lastModified('notes/plan.md'))->toBeGreaterThan(0)
        ->and($disk->mimeType('notes/plan.md'))->toBe('text/plain');
});

it('copies a file', function () {
    $disk = fakeDisk();

    $disk->put('a.md', "copy me\n");
    $version = $disk->version('a.md');

    expect($disk->copy('a.md', 'b.md'))->toBeTrue()
        ->and($disk->get('b.md'))->toBe("copy me\n")
        ->and($disk->version('b.md'))->toBe($version)
        ->and($disk->get('a.md'))->toBe("copy me\n");

    Source::assertCommitted('Copy a.md to b.md');
});

it('refuses a stale strict delete', function () {
    $disk = fakeDisk();

    $disk->put('a.md', "a\n");
    $version = $disk->version('a.md');

    Source::fake()->simulateChange('a.md');

    expect(fn () => $disk->ifUnchanged($version)->delete('a.md'))
        ->toThrow(FileChanged::class);

    expect($disk->fileExists('a.md'))->toBeTrue();
});
