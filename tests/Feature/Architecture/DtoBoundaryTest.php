<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Fence: a domain boundary (`App\Domains\*\Actions\*`, `App\Domains\*\Services\*`)
 * speaks in DTOs, not bare `array`. Any public method there whose parameter or
 * return type is `array` (or `?array`) is an offender unless it is on one of the
 * documented exemption lists below.
 *
 * Known blind spot: the fence reads native `array` types only, so a boundary
 * method that hands arrays back *inside another container type* is invisible to
 * it — `PlexApiService::getUserResources(): Collection` (a Collection of raw Plex
 * resource payloads) and `PlexLibraryService::fetchSectionItems(): Generator`
 * (pages of raw metadata) both slip past today. A container's element type cannot
 * be reflected, so a typed-`array` check is the cheap 90% and those have to be
 * caught by review, not by this test.
 */

/**
 * Boundary methods exempt outright — both parameters and return type.
 *
 * Keyed `Fully\Qualified\Class::method` => why it stays an array. Every key must
 * resolve to a real public method; `assertDtoBoundaryExemptionsResolve()` below
 * fails the suite on a stale one rather than letting it exempt nothing forever.
 *
 * NB: `App\Domains\Identity\Actions\PasswordValidationRules::passwordRules` is
 * deliberately absent — it is a `protected` method on a trait, so it is never a
 * public method on a boundary class and an entry would exempt nothing (the
 * resolution guard would reject it).
 *
 * @var array<string, string>
 */
const DTO_BOUNDARY_EXEMPT_METHODS = [
    // Raw upstream payload: the wire shape is the API's, not ours.
    'App\Domains\Catalog\Actions\UpsertTmdbMovies::handle' => 'raw TMDB movie payloads',
    'App\Domains\Catalog\Actions\UpsertTmdbShows::handle' => 'raw TMDB show payloads',
    'App\Domains\Catalog\Actions\UpsertTmdbImages::handle' => 'raw TMDB image payloads',
    'App\Domains\Catalog\Actions\UpsertTvdbShows::handle' => 'raw TVDB series payloads',
    'App\Domains\Catalog\Actions\UpsertTvdbEpisodes::handle' => 'raw TVDB episode payloads',
    'App\Domains\Catalog\Actions\UpsertTvdbSeasons::handle' => 'raw TVDB season payloads',
    'App\Domains\Catalog\Actions\UpsertTvdbArtworks::handle' => 'raw TVDB artwork payloads',
    'App\Domains\PlexLibrary\Actions\ReconcilePlexLibraries::handle' => 'raw Plex section payloads',
    'App\Domains\PlexLibrary\Actions\ReconcilePlexEpisodes::handle' => 'raw Plex children/leaves payloads',
    // Listed on the using classes, not on the `MarksAndSweepsPlexRows` trait that
    // declares `upsertPage` — a trait method's declaring class is the composing class.
    'App\Domains\PlexLibrary\Actions\ReconcilePlexMovies::upsertPage' => 'raw Plex metadata page',
    'App\Domains\PlexLibrary\Actions\ReconcilePlexShows::upsertPage' => 'raw Plex metadata page',
    'App\Domains\Catalog\Services\TmdbApiService::movie' => 'raw TMDB response body',
    'App\Domains\Catalog\Services\TmdbApiService::movies' => 'raw TMDB response bodies',
    'App\Domains\Catalog\Services\TmdbApiService::tv' => 'raw TMDB response body',
    'App\Domains\Catalog\Services\TmdbApiService::tvShows' => 'raw TMDB response bodies',
    'App\Domains\Catalog\Services\TmdbApiService::findByImdbId' => 'raw TMDB response body',
    'App\Domains\Catalog\Services\TmdbApiService::findManyByImdbId' => 'raw TMDB response bodies',
    'App\Domains\Catalog\Services\TmdbApiService::configuration' => 'raw TMDB response body',
    'App\Domains\Catalog\Services\TvdbApiService::series' => 'raw TVDB response body',
    'App\Domains\Catalog\Services\TvdbApiService::episodes' => 'raw TVDB response body',
    'App\Domains\Catalog\Services\TvdbApiService::allSeries' => 'raw TVDB response body',
    'App\Domains\Catalog\Services\TvdbApiService::seriesMany' => 'raw TVDB id batch',
    'App\Domains\PlexLibrary\Services\PlexLibraryService::fetchSections' => 'raw Plex response body',
    'App\Domains\PlexLibrary\Services\PlexLibraryService::fetchShowChildren' => 'raw Plex response body',
    'App\Domains\PlexLibrary\Services\PlexLibraryService::fetchShowLeaves' => 'raw Plex response body',

    // Framework-fixed signature: Fortify hands these an unvalidated input array.
    'App\Domains\Identity\Actions\CreateUser::create' => 'Fortify CreatesNewUsers contract',
    'App\Domains\Identity\Actions\UpdateUserProfile::update' => 'Fortify UpdatesUserProfileInformation contract',
    'App\Domains\Identity\Actions\ResetUserPassword::reset' => 'Fortify ResetsUserPasswords contract',
    'App\Domains\Identity\Actions\UpdateUserPassword::update' => 'Fortify UpdatesUserPasswords contract',

    // Scalar list: nothing to model, a DTO would only wrap ints.
    'App\Domains\Catalog\Actions\ReconcileImdbOnlyShows::handle' => 'list<int> of reconciled ids',
];

/**
 * Boundary methods whose PARAMETERS only are exempt — their `array` return type
 * is still an offender.
 *
 * The IMDb importers take raw dataset rows (exempt) but hand back an outcome
 * summary that becomes a DTO in FLIX-243 slice 7, so the return must stay flagged.
 *
 * @var array<string, string>
 */
const DTO_BOUNDARY_EXEMPT_PARAMETERS = [
    'App\Domains\Catalog\Actions\ImportImdbTitles::handle' => 'raw IMDb title rows',
    'App\Domains\Catalog\Actions\ImportImdbAkas::handle' => 'raw IMDb aka rows',
    'App\Domains\Catalog\Actions\UpdateImdbRatings::handle' => 'raw IMDb rating rows',
];

/**
 * Discover every `app/Domains/**` class living in an `Actions` or `Services`
 * namespace segment.
 *
 * Scans source files directly, not the autoloaded set, so the fence can't go
 * hollow — every boundary class is reflected, including ones nothing else
 * references yet. `class_exists` also filters out the `Concerns` traits, whose
 * methods are reached through the classes that compose them.
 *
 * @return list<ReflectionClass<object>>
 */
function dtoBoundaryClasses(): array
{
    $domainsPath = base_path('app/Domains');

    if (! is_dir($domainsPath)) {
        return [];
    }

    $reflections = [];

    foreach (Finder::create()->files()->in($domainsPath)->name('*.php') as $file) {
        $relative = Str::replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $fqcn = 'App\\Domains\\'.$relative;

        if (! Str::contains($fqcn, ['\\Actions\\', '\\Services\\'])) {
            continue;
        }

        if (! class_exists($fqcn)) {
            continue;
        }

        $reflections[] = new ReflectionClass($fqcn);
    }

    return $reflections;
}

/**
 * Fail loudly on an exemption entry that no longer names a real boundary method.
 *
 * A stale key exempts nothing and can never fail on its own, so the lists would
 * rot silently as classes are renamed or converted. Resolution mirrors the scan
 * below exactly — only a public method *declared on* the named class is ever
 * consulted — so an inherited or non-public entry is stale too.
 *
 * @throws RuntimeException
 */
function assertDtoBoundaryExemptionsResolve(): void
{
    $entries = [
        ...array_keys(DTO_BOUNDARY_EXEMPT_METHODS),
        ...array_keys(DTO_BOUNDARY_EXEMPT_PARAMETERS),
    ];

    foreach ($entries as $entry) {
        // A key missing its `::` separator lands here as an empty method name,
        // which `method_exists` rejects like any other unresolvable entry.
        [$class, $method] = array_pad(explode('::', $entry, 2), 2, '');

        if (! class_exists($class) || ! method_exists($class, $method)) {
            throw new RuntimeException("Stale DTO boundary exemption: {$entry} names no existing class or method. Remove the entry or fix the name.");
        }

        $reflection = new ReflectionMethod($class, $method);

        if (! $reflection->isPublic() || $reflection->getDeclaringClass()->getName() !== $class) {
            throw new RuntimeException("Stale DTO boundary exemption: {$entry} is not a public method declared on {$class}, so it exempts nothing. Remove the entry.");
        }
    }
}

/**
 * The public methods on $classes typed `array`/`?array` in a parameter or return
 * position, minus the documented exemptions.
 *
 * Offenders are reported as `FQCN::method` so a failure names what to convert.
 *
 * @param  list<ReflectionClass<object>>  $classes
 * @return list<string>
 *
 * @throws RuntimeException on a stale exemption entry
 */
function arrayTypedBoundaryMethods(array $classes): array
{
    assertDtoBoundaryExemptionsResolve();

    $offenders = [];

    foreach ($classes as $class) {
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Inherited framework/base-class methods belong to whoever declares them.
            // A trait's method declares onto the using class, so composed methods
            // are still reflected here (and are exempted on the using class).
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            if ($method->isConstructor()) {
                continue;
            }

            $signature = $class->getName().'::'.$method->getName();

            if (array_key_exists($signature, DTO_BOUNDARY_EXEMPT_METHODS)) {
                continue;
            }

            if (dtoBoundaryMethodSpeaksArray($method, $signature)) {
                $offenders[] = $signature;
            }
        }
    }

    return $offenders;
}

/**
 * Whether a boundary method speaks `array`: in its return type, or — unless its
 * parameters are exempt — in any parameter type.
 */
function dtoBoundaryMethodSpeaksArray(ReflectionMethod $method, string $signature): bool
{
    if (dtoBoundaryTypeIsArray($method->getReturnType())) {
        return true;
    }

    if (array_key_exists($signature, DTO_BOUNDARY_EXEMPT_PARAMETERS)) {
        return false;
    }

    foreach ($method->getParameters() as $parameter) {
        if (dtoBoundaryTypeIsArray($parameter->getType())) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a reflected type is `array` or `?array`.
 */
function dtoBoundaryTypeIsArray(?ReflectionType $type): bool
{
    return $type instanceof ReflectionNamedType && $type->getName() === 'array';
}

describe('arrayTypedBoundaryMethods() offender detection', function (): void {
    it('flags an array-typed public method on a domain boundary', function (): void {
        // Arrange
        $double = new class
        {
            public function handle(array $rows): void {}
        };

        // Act
        $offenders = arrayTypedBoundaryMethods([new ReflectionClass($double)]);

        // Assert
        expect($offenders)->toHaveCount(1);
        expect($offenders[0])->toEndWith('::handle');
    });

    it('does not flag a documented exemption', function (): void {
        // Arrange
        $exempt = new ReflectionClass(UpsertTmdbMovies::class);

        // Act
        $offenders = arrayTypedBoundaryMethods([$exempt]);

        // Assert
        expect($offenders)->toBe([], UpsertTmdbMovies::class.'::handle takes raw TMDB payloads and is exempt.');
    });
});

describe('the app/Domains boundary fence', function (): void {
    it('has no domain boundary method typed array outside the exemption list', function (): void {
        // Arrange
        // the real app/Domains tree is the subject, no state to set up

        // Act
        $offenders = arrayTypedBoundaryMethods(dtoBoundaryClasses());

        // Assert
        expect($offenders)->toBe([], 'Actions/Services boundaries must speak in DTOs, not array. Offenders: '.implode(', ', $offenders));
    });
});
