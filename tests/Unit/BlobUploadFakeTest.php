<?php

declare(strict_types=1);

use Source\Change;
use Source\Exceptions\StagingFull;
use Source\Exceptions\UnknownObject;
use Source\Source;
use Source\Support\Payload;

/*
 * The fake server's side of api.md 3.4.5: staged uploads count as held only in
 * their own repo, identical bytes are idempotent, expiry turns a commit into
 * unknown_object, and the two refusals are simulatable.
 */

function blobBody(string $seed, int $size = 70_000): string
{
    return substr(str_repeat($seed, intdiv($size, strlen($seed)) + 1), 0, $size);
}

it('treats a staged blob as present in its own repo only', function () {
    $fake = Source::fake();
    $contents = 'staged bytes';
    $oid = Payload::blobOid($contents);

    $upload = $fake->uploadBlob('acme', 'workspace', Payload::from($contents), $oid);

    expect($upload->oid)->toBe($oid)
        ->and($upload->existing)->toBeFalse()
        ->and($fake->presentObjects('acme', 'workspace', [$oid]))->toBe([$oid])
        ->and($fake->presentObjects('acme', 'other', [$oid]))->toBe([]);

    // Identical bytes again: the same oid, nothing new stored.
    $again = $fake->uploadBlob('acme', 'workspace', Payload::from($contents), $oid);

    expect($again->oid)->toBe($oid)
        ->and($again->existing)->toBeTrue()
        ->and($fake->hasReusedBlob($oid))->toBeTrue()
        ->and($fake->blobUploadCount())->toBe(2);
});

it('refuses an oid change in another repo and after the grace period', function () {
    $fake = Source::fake();
    $contents = 'cross-repo bytes';
    $oid = Payload::blobOid($contents);

    $fake->uploadBlob('acme', 'workspace', Payload::from($contents), $oid);

    // One repo only: the same oid in another repo is unknown (api.md 3.4.5).
    expect(fn () => $fake->sendChanges('acme', 'other', 'main', 'Elsewhere', null, [
        Change::reference('copy.bin', '', $oid),
    ]))->toThrow(UnknownObject::class);

    // Expiry drops it from objects/present and from the commit's allowed set.
    $fake->expireUploads();

    expect($fake->presentObjects('acme', 'workspace', [$oid]))->toBe([]);

    expect(fn () => $fake->sendChanges('acme', 'workspace', 'main', 'Expired', null, [
        Change::reference('copy.bin', '', $oid),
    ]))->toThrow(UnknownObject::class);
});

it('uploads a large file, names it in one commit and consumes the staging row', function () {
    $disk = fakeDisk();
    $large = blobBody('a');
    $oid = Payload::blobOid($large);

    $disk->commit('Upload', function ($files) use ($large) {
        $files->ifMissing()->put('assets/big.bin', $large);
        $files->ifMissing()->put('notes/small.txt', "small\n");
    });

    expect($disk->get('assets/big.bin'))->toBe($large)
        ->and(Source::fake()->commits())->toHaveCount(1);

    Source::assertBlobUploaded('assets/big.bin');
    Source::assertBlobUploaded($oid);
    Source::assertReferenced('assets/big.bin');

    // The commit made it reachable, so it is held even after expiry.
    Source::fake()->expireUploads();

    expect(Source::fake()->presentObjects('acme', 'workspace', [$oid]))->toBe([$oid]);
});

it('uploads nothing when the same content is committed again', function () {
    $disk = fakeDisk();
    $large = blobBody('b');
    $oid = Payload::blobOid($large);

    $disk->commit('First', function ($files) use ($large) {
        $files->ifMissing()->put('assets/big.bin', $large);
        $files->ifMissing()->put('notes/one.txt', "one\n");
    });

    $uploads = Source::fake()->blobUploadCount();

    $disk->commit('Second', function ($files) use ($oid, $large) {
        $files->ifUnchanged($oid)->put('assets/big.bin', $large);
        $files->ifUnchanged(Payload::blobOid("one\n"))->put('notes/one.txt', "two\n");
    });

    expect(Source::fake()->blobUploadCount())->toBe($uploads)
        ->and($disk->get('assets/big.bin'))->toBe($large)
        ->and(Source::fake()->commits())->toHaveCount(2);

    Source::assertReferenced('assets/big.bin');
});

it('re-uploads the bytes when the staged blob expires before the commit', function () {
    $disk = fakeDisk();
    $large = blobBody('c');
    $oid = Payload::blobOid($large);

    $expired = false;

    // The hook runs just before a commit is applied: the upload already
    // happened, and this is exactly the window the 24-hour grace period closes.
    Source::fake()->beforeWrite(function ($fake) use (&$expired) {
        if (! $expired) {
            $expired = true;
            $fake->expireUploads();
        }
    });

    $disk->commit('After expiry', function ($files) use ($large) {
        $files->ifMissing()->put('assets/big.bin', $large);
        $files->ifMissing()->put('notes/small.txt', "small\n");
    });

    expect($disk->get('assets/big.bin'))->toBe($large)
        ->and(Source::fake()->commits())->toHaveCount(1)
        // One upload that expired, one that the fallback sent again.
        ->and(Source::fake()->blobUploadCount())->toBe(2)
        ->and(Source::fake()->hasBlobUploaded($oid))->toBeTrue();

    Source::assertReferenced('assets/big.bin');
});

it('surfaces staging_full from the fake without retrying', function () {
    $disk = fakeDisk();
    $large = blobBody('d');

    Source::fake()->simulateStagingFull(times: 1, scope: 'server');

    try {
        $disk->commit('Full', function ($files) use ($large) {
            $files->ifMissing()->put('assets/big.bin', $large);
            $files->ifMissing()->put('notes/small.txt', "small\n");
        });

        $this->fail('The staging cap accepted the upload.');
    } catch (StagingFull $e) {
        expect($e->scope)->toBe('server')
            ->and(Source::fake()->blobUploadCount())->toBe(0)
            ->and(Source::fake()->commits())->toBe([]);
    }
});

it('works with simulateBusy on the upload path', function () {
    $disk = fakeDisk();
    $large = blobBody('e');

    Source::fake()->simulateBusy(times: 2);

    $disk->commit('Busy', function ($files) use ($large) {
        $files->ifMissing()->put('assets/big.bin', $large);
        $files->ifMissing()->put('notes/small.txt', "small\n");
    });

    // Two refusals, then the upload landed: the bytes are uploaded once.
    expect($disk->get('assets/big.bin'))->toBe($large)
        ->and(Source::fake()->blobUploadCount())->toBe(1)
        ->and(Source::fake()->commits())->toHaveCount(1);
});
