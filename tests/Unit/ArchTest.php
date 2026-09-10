<?php

declare(strict_types=1);

use App\Domains\Catalog\Console\Commands\ImdbSyncCommand;
use App\Domains\Catalog\Console\Commands\TmdbMoviesCommand;
use App\Domains\Catalog\Console\Commands\TmdbSyncCommand;
use App\Domains\Catalog\Console\Commands\TvdbShowsCommand;
use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Services\PooledTransport;
use App\Domains\Catalog\Support\PooledWindow;
use App\Domains\Catalog\Support\TokenBucket;
use App\Domains\PlexLibrary\Console\Commands\PlexLibraryCommand;
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
| Second half of the convention, over the same three roots: a class with NO
| parent is also `readonly`, so a parentless class reads `final readonly class X` —
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
    TmdbMoviesCommand::class,
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
 * The parentless classes exempt from the `readonly` rule.
 *
 * An entry earns its place by holding mutable state a `readonly class` cannot:
 * TokenBucket is a pacer, so its cursor, the rate currently in force and the
 * instant of the last penalty all move as requests flow through it;
 * PooledTransport owns the process's live connection handle plus the running
 * dispatch tally it reports; and PooledWindow is the work queue the pooled
 * window rolls over, so both the queue and the cursor into it move — and it is
 * parentless only because implementing an interface is not extending a class.
 *
 * Every entry is pinned by the staleness guard below — still declared, still
 * parentless, still non-readonly — so one that later gains `readonly` or is
 * renamed away fails loudly rather than silently exempting nothing.
 *
 * @var list<class-string>
 */
$statefulParentlessClasses = [
    PooledTransport::class,
    PooledWindow::class,
    TokenBucket::class,
];

/**
 * Every concrete, parentless class the repo owns — the target set of the
 * `readonly` rule, over the same three roots as the `final` rule.
 *
 * A reflection scan rather than an arch expectation: no `expect()` chain can
 * express "has no parent", and `toBeReadonly()`'s predicate reports every enum
 * as a violation. Classes are resolved from each file's `namespace` line plus
 * its basename, and skipped when the file declares no class of that name or
 * `class_exists()` is false — which between them drop interfaces, traits and
 * migrations. Abstract classes clear both and are rejected explicitly; the enum
 * reject is the backstop that keeps enums — implicitly final, never readonly-able,
 * and true to `class_exists()` — out if that declaration filter is ever loosened.
 *
 * @return Collection<int, class-string>
 */
$scanParentlessClasses = function (): Collection {
    // The Unit suite boots no framework, so resolve the roots from this file's
    // location rather than app_path()/database_path(). database/ contributes only
    // its two PSR-4-mapped halves: no other path under it is autoloadable.
    $root = dirname(__DIR__, 2);

    $files = Finder::create()->files()->name('*.php')->in([
        $root.'/app',
        $root.'/database/factories',
        $root.'/database/seeders',
        $root.'/tests',
    ]);

    return collect($files)
        ->map(function (SplFileInfo $file): ?string {
            $basename = $file->getBasename('.php');
            $contents = $file->getContents();

            // The namespace line alone would name a class for any file, and
            // class_exists() AUTOLOADS what it is handed. Under tests/ — where
            // `Tests\` maps to the whole tree — that would include a namespaced
            // Pest file and run its it()/describe() calls outside a Pest context,
            // so a matching class declaration is required before the name resolves.
            $declaresClass = preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) === 1
                && preg_match('/\bclass\s+'.preg_quote($basename, '/').'\b/', $contents) === 1;

            return $declaresClass ? $matches[1].'\\'.$basename : null;
        })
        ->filter(fn (?string $class): bool => $class !== null && class_exists($class))
        ->map(fn (string $class): ReflectionClass => new ReflectionClass($class))
        ->reject(fn (ReflectionClass $class): bool => $class->isEnum() || $class->isAbstract())
        ->filter(fn (ReflectionClass $class): bool => $class->getParentClass() === false)
        ->map(fn (ReflectionClass $class): string => $class->getName())
        ->sort()
        ->values();
};

describe('the final rule', function () use ($domainAbstractBases, $httpAbstractBases, $testsAbstractBases): void {
    it('declares every class in App final', function () use ($domainAbstractBases, $httpAbstractBases): void {
        // Arrange
        // no state to set up — the namespace tree is the subject

        // The root namespace, not its sub-namespaces one by one: composer maps
        // `App\` to the whole of app/, so an enumeration goes silently incomplete
        // the moment a `make:job`/`make:policy`/`make:command` writes a top-level
        // folder nobody thought to name here.
        // Act & Assert
        expect('App')
            ->classes()
            ->toBeFinal()
            ->ignoring([...$domainAbstractBases, ...$httpAbstractBases]);
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

        // An arch layer is filtered by fully-qualified NAME prefix, not by path, so
        // this reaches a test helper only while it is declared under `Tests\` —
        // a class declared in the global namespace inside a Pest file is invisible
        // here. That is why every helper class lives in tests/Support/ under
        // `Tests\Support\`, one per file, imported by the tests that use it.
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
});

describe('the readonly rule', function () use ($scanParentlessClasses, $statefulParentlessClasses): void {
    it('declares every parentless class the repo owns readonly', function () use ($scanParentlessClasses, $statefulParentlessClasses): void {
        // Arrange
        $targets = $scanParentlessClasses()->reject(
            fn (string $class): bool => in_array($class, $statefulParentlessClasses, true),
        );

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

    it('actually scans the three roots rather than silently finding nothing', function () use ($scanParentlessClasses): void {
        // A guard that scans an empty set passes forever. A bad path fails loudly
        // (Finder::in() throws), but a regressed namespace regex or basename join
        // degrades every entry to null in silence — pin a floor so that fails here.
        // Arrange
        $targets = $scanParentlessClasses();

        // Act
        $roots = $targets->map(fn (string $class): string => Str::before($class, '\\'))->unique();

        // Assert
        expect($targets)->not->toBeEmpty()
            ->and($targets->count())->toBeGreaterThan(50)
            ->and($roots->all())->toContain('App', 'Tests');
    });

    it('applies the readonly rule to no enum or abstract class', function () use ($scanParentlessClasses): void {
        // Arrange
        $outsideTheRule = [ArtworkType::class, PlexLibraryCommand::class];

        // Act
        $wronglyTargeted = $scanParentlessClasses()->intersect($outsideTheRule)->values()->all();

        // Assert
        expect($wronglyTargeted)->toBe([]);
    });

    it('exempts only genuinely stateful parentless classes from the readonly rule', function () use ($statefulParentlessClasses): void {
        // Arrange
        // the exemption list is the subject

        // An entry that gains `readonly`, gains a parent, or is renamed away stops
        // being an exemption and starts being a hole in the rule — all three read
        // the same from inside the reject above, so pin each condition here.
        // Act
        $stale = collect($statefulParentlessClasses)
            ->reject(function (string $class): bool {
                if (! class_exists($class)) {
                    return false;
                }

                $reflection = new ReflectionClass($class);

                return $reflection->getParentClass() === false && ! $reflection->isReadOnly();
            })
            ->values()
            ->all();

        // Assert
        expect($stale)->toBe([]);
    });
});
