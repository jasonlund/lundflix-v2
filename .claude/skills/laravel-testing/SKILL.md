---
name: laravel-testing
description: >-
  Conventions for writing Laravel backend tests (Pest/PHPUnit) for this app:
  Feature vs Unit tests, database refresh, factories, HTTP assertions, and
  Inertia response assertions. Loaded by the TDD subagents when the target is
  PHP. Use when writing or refactoring Laravel tests.
---

# Laravel testing conventions

> Stack: **Pest 4** + `pest-plugin-laravel`, `inertiajs/inertia-laravel` ^3.
> Tests in `tests/Feature` and `tests/Unit`.

## Runner & commands

- **Pest** (`./vendor/bin/pest`) or `php artisan test` (the `composer test` script
  clears config then runs `php artisan test`).
- Run one test file: `php artisan test tests/Feature/Foo/BarTest.php`
- Run by name filter: `php artisan test --filter='renders the movie list'`
- Always run the **single** test under work during a TDD cycle — not the whole suite —
  until the gate is met; run the broader suite before finishing GREEN.

## Test types

- `tests/Feature/` — integration / behavior through HTTP, the default for features.
  Hit routes, assert responses, DB state, and Inertia props.
- `tests/Unit/` — pure units (a service, a value object) with no framework boot.
  Use only when there's genuine isolated logic.

## Patterns

- Use `RefreshDatabase` (Pest: `uses(RefreshDatabase::class)`) for DB tests.
- Build state with **model factories** (`Movie::factory()->create()`), never raw
  inserts or fixtures.
- HTTP: `$this->actingAs($user)->get(route('movies.index'))` then
  `->assertOk()`, `->assertRedirect()`, `->assertForbidden()`.
- Test **behavior, not implementation**: assert response/DB/side-effects the caller
  observes, not internal method calls.

## Inertia assertions

For Inertia pages, assert the component name and props with `AssertableInertia`:

```php
use Inertia\Testing\AssertableInertia as Assert;

$this->get(route('movies.index'))
    ->assertInertia(fn (Assert $page) => $page
        ->component('movies/Index')   // lowercase: resolves resources/js/pages/movies/Index.tsx
        ->has('movies', 3)
        ->where('movies.0.title', 'Heat')
    );
```

This verifies the backend contract the React page depends on — pair it with the
frontend `react-testing` cycle for full-stack features.

## RED checklist (for tdd-test-writer)

- A small cohesive set (2–6) of failing tests for one behavior slice; each describes
  one user-observable behavior.
- Arrange with factories, act via route/HTTP, assert the observable result.
- Run it; it must fail on the **assertion** (red for the right reason), not on a
  missing route/class crash that hides the real expectation. A "404/500 because not
  built yet" is acceptable red only when that status IS the behavior under test;
  otherwise stub the route so the assertion is what fails.

## REFACTOR targets (for tdd-refactorer)

- Extract fat controller logic into **actions**, **services**, or **form requests**.
- Move validation to form requests; authorization to policies.
- Remove duplication; clarify names. Keep all tests green; show the run.
