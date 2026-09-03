<?php

declare(strict_types=1);

/*
 | docs/01-ARCHITECTURE.md §5: the portal does not share a layout or component
 | namespace with the agency app, "so a mis-scoped Blade include cannot leak an
 | agency screen into the portal".
 |
 | That was prose. Extracting per-surface form components is the first thing
 | that could quietly erode it -- one <x-agency.form.input> in a portal view
 | looks harmless, and it is, right up until somebody follows the precedent
 | with a component that carries data.
 |
 | So the rule is asserted here rather than remembered. This is the same move
 | as ScopeBypassTest: a documented guarantee nothing enforced.
 */

/** Every Blade view belonging to one surface. */
function surfaceViews(string $surface): array
{
    $directory = resource_path("views/{$surface}");

    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('does not use another surface is components', function (string $surface, array $foreign): void {
    $offenders = [];

    foreach (surfaceViews($surface) as $path) {
        $source = (string) file_get_contents($path);

        foreach ($foreign as $other) {
            // Both <x-admin.button> and </x-admin.button> start this way.
            if (str_contains($source, "<x-{$other}.")) {
                $offenders[] = basename($path)." uses <x-{$other}.*>";
            }
        }
    }

    expect($offenders)->toBeEmpty(
        "Views under resources/views/{$surface} reach into another surface's "
        .'component namespace: '.implode(', ', $offenders)
    );
})->with([
    'agency' => ['agency', ['admin', 'portal']],
    'portal' => ['portal', ['agency', 'admin']],
    'admin' => ['admin', ['agency', 'portal']],
]);

it('keeps the layouts separate', function (): void {
    // The other half of the same rule: a portal screen extending the agency
    // layout would inherit its whole navigation.
    foreach (surfaceViews('portal') as $path) {
        expect((string) file_get_contents($path))
            ->not->toContain("@extends('layouts.agency')")
            ->not->toContain("@extends('layouts.admin')");
    }
});

it('gives the portal no component namespace of its own', function (): void {
    /*
     | Deliberate, not an oversight. The portal has two styled controls in
     | total; a component set for two call sites is scaffolding, and this
     | codebase has spent a while deleting things nothing calls.
     |
     | If that stops being true, delete this test rather than working around
     | it -- it exists to record a decision, not to forbid a future one.
     */
    expect(is_dir(resource_path('views/components/portal')))->toBeFalse();
});
