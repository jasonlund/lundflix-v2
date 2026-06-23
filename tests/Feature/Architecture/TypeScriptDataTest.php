<?php

declare(strict_types=1);

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Symfony\Component\Finder\Finder;

/**
 * Discover every class declared under `app/Domains/**` that carries the
 * `#[TypeScript]` attribute.
 *
 * We scan the source files directly (rather than relying on what the autoloader
 * happens to have loaded) so the fence cannot go hollow: every domain Data file
 * is loaded and reflected on, including ones nothing else has referenced yet.
 *
 * Reflection (not Pest's fluent `toHaveAttribute`) is deliberate: that selector
 * is the inverse of what we need — it asserts a targeted namespace's classes
 * *have* an attribute, whereas this fence asserts the conditional "classes that
 * *have* `#[TypeScript]` are well-formed". Do not "simplify" this into it.
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
 * Assert that no `#[TypeScript]`-annotated domain class violates the given rule.
 *
 * Discovers the annotated classes, keeps the ones the predicate flags as
 * offending, and asserts that set — reported by FQCN — is empty.
 *
 * @param  callable(ReflectionClass<object>): bool  $violates
 */
function expectNoAnnotatedClassViolates(callable $violates, string $message): void
{
    $offenders = array_filter(typeScriptAnnotatedDomainClasses(), $violates);

    expect(array_map(fn (ReflectionClass $class): string => $class->getName(), $offenders))
        ->toBe([], $message);
}

it('only annotates spatie Data classes with the TypeScript attribute', function () {
    expectNoAnnotatedClassViolates(
        fn (ReflectionClass $class): bool => ! $class->isSubclassOf(Data::class),
        'Every #[TypeScript] class must extend '.Data::class.'.',
    );
});

it('only annotates classes living in a domain Data namespace with the TypeScript attribute', function () {
    expectNoAnnotatedClassViolates(
        fn (ReflectionClass $class): bool => ! str_starts_with($class->getName(), 'App\\Domains\\')
            || ! str_contains($class->getName(), '\\Data\\'),
        'Every #[TypeScript] class must live under App\\Domains\\...\\Data\\.',
    );
});
