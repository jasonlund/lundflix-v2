<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Rector\Config\RectorConfig;
use Rector\PostRector\Rector\NameImportingPostRector;
use Rector\Transform\Rector\FuncCall\FuncCallToStaticCallRector;
use Rector\Transform\ValueObject\FuncCallToStaticCall;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Set\LaravelSetList;

// PHP-native string functions → Str facade. No stock rector-laravel set covers
// these: that set maps Laravel's legacy global helpers (str_after, starts_with, …),
// not native functions — str_contains is the sole overlap, and it's dropped there by
// an internal-function filter on PHP 8+ (and that set isn't enabled here anyway), so
// every native → Str:: equivalent is declared by hand. Only positional-compatible maps belong here:
// array_key_exists → Arr::exists is excluded because Arr::exists($array, $key) swaps
// the argument order and FuncCallToStaticCall maps positionally.
$nativeStringFunctionsToStr = [
    new FuncCallToStaticCall('str_starts_with', Str::class, 'startsWith'),
    new FuncCallToStaticCall('str_ends_with', Str::class, 'endsWith'),
    new FuncCallToStaticCall('str_contains', Str::class, 'contains'),
    new FuncCallToStaticCall('str_replace', Str::class, 'replace'),
    new FuncCallToStaticCall('strtolower', Str::class, 'lower'),
    new FuncCallToStaticCall('strtoupper', Str::class, 'upper'),
    new FuncCallToStaticCall('ucfirst', Str::class, 'ucfirst'),
    new FuncCallToStaticCall('trim', Str::class, 'trim'),
    new FuncCallToStaticCall('ltrim', Str::class, 'ltrim'),
    new FuncCallToStaticCall('rtrim', Str::class, 'rtrim'),
    new FuncCallToStaticCall('substr', Str::class, 'substr'),
    new FuncCallToStaticCall('strlen', Str::class, 'length'),
    new FuncCallToStaticCall('str_repeat', Str::class, 'repeat'),
    new FuncCallToStaticCall('ucwords', Str::class, 'ucwords'),
];

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        // Rewrites $_ENV['X'] reads to Env::get('X'), but mis-fires inside unset():
        // unset(Env::get('X')) is a fatal error. This test deliberately clears the
        // superglobals to assert a config default, so the read-helper rule is wrong here.
        EnvVariableToEnvHelperRector::class => [
            __DIR__.'/tests/Feature/Catalog/TvdbApiServiceTest.php',
        ],
        // config('settings...table') is published by spatie/laravel-settings as an
        // explicit null, so config($key, 'settings') returns null (key exists) — only
        // `?? 'settings'` yields the fallback table name. The rule misfires here.
        ApplyDefaultInsteadOfNullCoalesceRector::class => [
            __DIR__.'/database/migrations/2022_12_14_083707_create_settings_table.php',
        ],
        // This test imports AssertableInertia both directly and aliased as Assert;
        // the name importer would collapse the direct reference to the alias, an
        // unrelated rewrite. Leave its imports untouched.
        NameImportingPostRector::class => [
            __DIR__.'/tests/Feature/Identity/SharedUserDataTest.php',
        ],
    ])
    ->withPhpSets()
    ->withSets([
        LaravelSetList::LARAVEL_130,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withImportNames(importDocBlockNames: false, importShortClasses: false, removeUnusedImports: false)
    ->withConfiguredRule(FuncCallToStaticCallRector::class, $nativeStringFunctionsToStr);
