<?php

declare(strict_types=1);

use Source\Change;
use Source\Exceptions\FileChanged;
use Source\Testing\FakeClient;

it('uses real git blob ids', function () {
    // The empty blob, as in api.md's batch-commit example.
    expect(FakeClient::oid(''))->toBe('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391');
});

it('gives identical content the same version', function () {
    $fake = new FakeClient;

    $fake->sendChanges('acme', 'workspace', 'main', 'One', null, [Change::put('a.md', '', "same\n")]);
    $fake->sendChanges('acme', 'workspace', 'main', 'Two', null, [Change::put('b.md', '', "same\n")]);

    expect($fake->head('acme', 'workspace', 'main', 'a.md')->version)
        ->toBe($fake->head('acme', 'workspace', 'main', 'b.md')->version);
});

it('lists every mismatched path and who changed it', function () {
    $fake = new FakeClient;

    $fake->sendChanges('acme', 'workspace', 'main', 'Seed', null, [
        Change::put('a.md', '', "a\n"),
        Change::put('b.md', '', "b\n"),
    ]);

    try {
        $fake->sendChanges('acme', 'workspace', 'main', 'Stale', null, [
            Change::put('a.md', str_repeat('0', 40), "a2\n"),
            Change::put('b.md', str_repeat('1', 40), "b2\n"),
        ]);

        $this->fail('A stale batch was accepted.');
    } catch (FileChanged $e) {
        expect($e->paths)->toBe(['a.md', 'b.md'])
            ->and($e->changedBy('a.md')->commitOid)->toMatch('/^[0-9a-f]{40}$/')
            ->and($e->changedBy('b.md')->actor)->toBe('source');
    }

    // Nothing landed.
    expect($fake->contents('a.md'))->toBe("a\n")
        ->and($fake->contents('b.md'))->toBe("b\n");
});
