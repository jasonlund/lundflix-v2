<?php

declare(strict_types=1);

use App\Domains\Catalog\Console\Commands\ImdbSyncCommand;
use App\Domains\Catalog\Console\Commands\TmdbSyncCommand;
use App\Domains\Catalog\Console\Commands\TvdbShowsCommand;
use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\PlexLibrary\Console\Commands\PlexLibraryCommand;
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
| Every non-abstract class this repo declares is `final` — across app/, the two
| PSR-4-mapped halves of database/ (factories, seeders) and the helper classes
| under tests/. Inheritance is opt-in, and the handful of shared bases that opt
| in say so by being `abstract`.
|
| The targets are narrowed with `->classes()` on purpose: `toBeFinal()`'s
| predicate reports every enum as a violation (an enum is implicitly final but
| the expectation excludes `enum_exists` names), so an un-narrowed namespace
| target would fail on enums, interfaces and traits instead of on real
| non-final classes.
|
| Second half of the convention, scoped to app/: a class with NO parent is also
| `readonly`, so a parentless class reads `final readonly class X` —
| mechanically, including stateless and static-only helpers. A class that
| extends something is outside the rule by language constraint: PHP rejects a
| readonly class extending a non-readonly one ("Readonly class C cannot extend
| non-readonly class P"), so models, commands, factories, exceptions, data
| objects, providers, middleware and Filament pages can never be readonly.
| Enums, traits, interfaces and abstract classes are outside the rule entirely.
*/

/**
 * The abstract bases exempt from the `final` rule, one list per namespace target.
 *
 * Every entry of every list is pinned as genuinely abstract by the staleness
 * guard below, so a base that later drops `abstract` (or is renamed away) fails
 * loudly rather than silently exempting a concrete class from the rule.
 *
 * @var list<class-string>
 */
$domainAbstractBases = [
    ImdbSyncCommand::class,
    TmdbSyncCommand::class,
    TvdbShowsCommand::class,
    PlexLibraryCommand::class,
];

/** @var list<class-string> */
$httpAbstractBases = [
    Controller::class,
];

/**
 * The base every Feature/Browser test extends.
 *
 * @var list<class-string>
 */
$testsAbstractBases = [
    TestCase::class,
];

/**
 * Every concrete, parentless class under app/ — the target set of the `readonly` rule.
 *
 * A reflection scan rather than an arch expectation: no `expect()` chain can
 * express "has no parent", and `toBeReadonly()`'s predicate reports every enum
 * as a violation. Classes are resolved from each file's `namespace` line plus
 * its basename, and skipped when `class_exists()` is false — which already
 * drops interfaces and traits; enums and abstract classes are rejected
 * explicitly.
 *
 * @return Collection<int, class-string>
 */
$scanParentlessClasses = function (): Collection {
    // The Unit suite boots no framework, so resolve app/ from this file's
    // location rather than app_path().
    $files = Finder::create()->files()->in(dirname(__DIR__, 2).'/app')->name('*.php');

    return collect($files)
        ->map(function (SplFileInfo $file): ?string {
            $matched = preg_match('/^namespace\s+([^;]+);/m', $file->getContents(), $matches) === 1;

            return $matched ? $matches[1].'\\'.$file->getBasename('.php') : null;
        })
        ->filter(fn (?string $class): bool => $class !== null && class_exists($class))
        ->map(fn (string $class): ReflectionClass => new ReflectionClass($class))
        ->reject(fn (ReflectionClass $class): bool => $class->isEnum() || $class->isInterface() || $class->isTrait() || $class->isAbstract())
        ->filter(fn (ReflectionClass $class): bool => $class->getParentClass() === false)
        ->map(fn (ReflectionClass $class): string => $class->getName())
        ->sort()
        ->values();
};

it('declares every class in App\Domains final', function () use ($domainAbstractBases): void {
    // Arrange
    // no state to set up — the namespace tree is the subject

    // Act & Assert
    expect('App\Domains')
        ->classes()
        ->toBeFinal()
        ->ignoring($domainAbstractBases);
});

it('declares every class in App\Http final', function () use ($httpAbstractBases): void {
    // Arrange
    // no state to set up — the namespace tree is the subject

    // Act & Assert
    expect('App\Http')
        ->classes()
        ->toBeFinal()
        ->ignoring($httpAbstractBases);
});

it('declares every class in App\Providers and App\Filament final', function (): void {
    // Arrange
    // no state to set up — the namespace trees are the subject

    // Act & Assert
    expect(['App\Providers', 'App\Filament'])
        ->classes()
        ->toBeFinal();
});

it('declares every class in Database\Seeders and Database\Factories final', function (): void {
    // Arrange
    // no state to set up — the namespace trees are the subject

    // Naming the two mapped namespaces is what keeps database/migrations out:
    // composer PSR-4 maps only database/factories and database/seeders, so no
    // namespace target can resolve a migration — and its `return new class`
    // body is anonymous, where `final` has nothing to attach to.
    // Act & Assert
    expect(['Database\Seeders', 'Database\Factories'])
        ->classes()
        ->toBeFinal();
});

it('declares every helper class under tests/ final', function () use ($testsAbstractBases): void {
    // Arrange
    // no state to set up — the namespace tree is the subject

    // A Pest test file declares no class, so this targets only the helper
    // classes tests declare for themselves — notifications, command hosts, and
    // the one remaining PHPUnit-style test case.
    // Act & Assert
    expect('Tests')
        ->classes()
        ->toBeFinal()
        ->ignoring($testsAbstractBases);
});

it('exempts only genuinely abstract bases from the final rule', function () use ($domainAbstractBases, $httpAbstractBases, $testsAbstractBases): void {
    // Arrange
    $exempt = [...$domainAbstractBases, ...$httpAbstractBases, ...$testsAbstractBases];

    // Act
    $stale = collect($exempt)
        ->reject(fn (string $class): bool => class_exists($class) && (new ReflectionClass($class))->isAbstract())
        ->values()
        ->all();

    // Assert
    expect($stale)->toBe([]);
});

it('declares every parentless class under app/ readonly', function () use ($scanParentlessClasses): void {
    // Arrange
    $targets = $scanParentlessClasses();

    // Act
    $violators = $targets
        ->reject(fn (string $class): bool => (new ReflectionClass($class))->isReadOnly())
        ->values()
        ->all();

    // Assert
    expect($violators)->toBe([], sprintf(
        "%d parentless classes are missing `readonly`:\n  - %s",
        count($violators),
        implode("\n  - ", $violators),
    ));
});

it('applies the readonly rule to no enum, trait, interface or abstract class', function () use ($scanParentlessClasses): void {
    // Arrange
    $outsideTheRule = [ArtworkType::class, PlexLibraryCommand::class];

    // Act
    $wronglyTargeted = $scanParentlessClasses()->intersect($outsideTheRule)->values()->all();

    // Assert
    expect($wronglyTargeted)->toBe([]);
});
