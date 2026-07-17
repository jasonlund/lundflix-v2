<?php

declare(strict_types=1);

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Symfony\Component\Finder\Finder;

/**
 * Discover every `app/Domains/**` class carrying `#[TypeScript]`.
 *
 * Scans source files directly, not the autoloaded set, so the fence can't go
 * hollow — every domain Data file is reflected, including ones nothing else
 * references yet.
 *
 * Reflection (not Pest's `toHaveAttribute`) is deliberate: that selector asserts
 * a namespace's classes *have* an attribute — the inverse of the conditional we
 * need ("classes that *have* `#[TypeScript]` are well-formed"). Don't fold it in.
 *
 * @return list<ReflectionClass<object>>
 */
function typeScriptAnnotatedDomainClasses(): array
{
    $domainsPath = base_path('app/Domains');

    if (! is_dir($domainsPath)) {
        return [];
    }

    $reflections = [];

    foreach (Finder::create()->files()->in($domainsPath)->name('*.php') as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $fqcn = 'App\\Domains\\'.$relative;

        if (! class_exists($fqcn)) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->getAttributes(TypeScript::class) !== []) {
            $reflections[] = $reflection;
        }
    }

    return $reflections;
}

/**
 * Assert no `#[TypeScript]`-annotated domain class violates `$violates`.
 *
 * Offenders are reported by FQCN so a failure names the class to fix.
 *
 * @param  callable(ReflectionClass<object>): bool  $violates
 */
function expectNoAnnotatedClassViolates(callable $violates, string $message): void
{
    $offenders = array_filter(typeScriptAnnotatedDomainClasses(), $violates);

    expect(array_map(fn (ReflectionClass $class): string => $class->getName(), $offenders))
        ->toBe([], $message);
}

it('only annotates spatie Data classes with the TypeScript attribute', function (): void {
    expectNoAnnotatedClassViolates(
        fn (ReflectionClass $class): bool => ! $class->isSubclassOf(Data::class),
        'Every #[TypeScript] class must extend '.Data::class.'.',
    );
});

it('only annotates classes living in a domain Data namespace with the TypeScript attribute', function (): void {
    expectNoAnnotatedClassViolates(
        fn (ReflectionClass $class): bool => ! str_starts_with($class->getName(), 'App\\Domains\\')
            || ! str_contains($class->getName(), '\\Data\\'),
        'Every #[TypeScript] class must live under App\\Domains\\...\\Data\\.',
    );
});
