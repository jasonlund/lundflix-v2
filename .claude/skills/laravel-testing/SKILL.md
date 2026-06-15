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
- **Mirror the domain tree:** `tests/Feature/{Domain}/` and `tests/Unit/{Domain}/`
  map to `app/Domains/{Domain}/` (e.g. `tests/Feature/Catalog/`).

## Patterns

- Use `RefreshDatabase` (Pest: `uses(RefreshDatabase::class)`) for DB tests.
- Build state with **model factories** (`Movie::factory()->create()`), never raw
  inserts or fixtures.
- HTTP: `$this->actingAs($user)->get(route('movies.index'))` then
  `->assertOk()`, `->assertRedirect()`, `->assertForbidden()`.
- Test **behavior, not implementation**: assert response/DB/side-effects the caller
  observes, not internal method calls.

## External HTTP / API fixtures

When a test fakes an external API, the faked response body must be a **byte-exact
slice of a real response** in the API's native wire format — never a hand-fabricated
shape (fabricated fixtures drift from what the API actually emits and still pass).

- Commit the slice under `tests/Fixtures/{Domain}/{source}/` in the exact format +
  extension the API returns (`.tsv.gz`, `.json`, …). Domained, sub-keyed by source.
  Curate a handful of real records covering the cases under test; document them in a
  comment at the top of the test file (compressed/opaque fixtures don't diff).
- Load bytes with the global `fixtureBytes($path)` helper (in `tests/Pest.php`),
  which wraps Pest's built-in `fixture()` (the latter returns the resolved path under
  `tests/Fixtures/` and asserts existence):

  ```php
  Http::fake(['*datasets.imdbws.com*' => Http::response(
      fixtureBytes('Catalog/imdb/title.basics.tsv.gz')
  )]);
  ```
- `Http::preventStrayRequests()` runs in a global `beforeEach` for Feature tests, so
  any un-faked external request fails the test. Fake every external call.
- Synthetic bodies are allowed **only** for inputs that can't exist in real data:
  malformed/corrupt payloads, blank lines, HTTP error statuses.
- NB: the "never raw inserts or fixtures" rule below is about **DB state** (use
  factories) — it does not apply to these external-response fixtures.

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
- **Arrange–Act–Assert (mandatory):** three blank-line-separated blocks, one Act
  per test.

```php
it('stores a movie', function () {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->post(route('movies.store'), ['title' => 'Heat']);

    // Assert
    $response->assertRedirect();
    expect(Movie::where('title', 'Heat')->exists())->toBeTrue();
});
```
- Run it; it must fail on the **assertion** (red for the right reason), not on a
  missing route/class crash that hides the real expectation. A "404/500 because not
  built yet" is acceptable red only when that status IS the behavior under test;
  otherwise stub the route so the assertion is what fails.

## REFACTOR targets (for tdd-refactorer)

- Extract fat controller logic into **actions**, **services**, or **form requests**.
- Move validation to form requests; authorization to policies.
- Remove duplication; clarify names. Keep all tests green; show the run.
