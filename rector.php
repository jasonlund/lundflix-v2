<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Set\LaravelSetList;

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
    );
