---
name: tdd-laravel-testing
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

## Anti-patterns

- **Tautological.** The expected value is recomputed the way the code computes it,
  so the test passes by construction and can never disagree with the code. A
  `foreach` in the test that re-derives the sum, a `Str::slug()` call in the
  assertion mirroring the one under test, an expectation built from the same enum
  map. The expected value must come from an **independent** source: a literal you
  worked out by hand, a value from the spec, a known-good capture.

  ```php
  // BAD — recomputes the implementation
  expect($movie->displayTitle())->toBe($movie->_tmdb_title ?? $movie->_imdb_title);

  // GOOD — an independent literal
  expect($movie->displayTitle())->toBe('Blade Runner');
  ```

- **Implementation-coupled.** Mocking an internal collaborator, asserting on call
  counts or ordering, or testing a private method. The tell: the test breaks under
  a refactor that changed no behavior.
- **Mock only at system seams** — a third-party HTTP API, a shelled process, the
  clock, randomness. **Never mock our own classes.** A domain Action, a service, a
  model: build the real thing. Our own collaborators are `in-process` or
  `local-substitutable` dependencies (see `codebase-design`), and both are testable
  for real.
- **Test names describe WHAT, not HOW** — `it('rejects a duplicate title')`, not
  `it('calls the uniqueness validator')`.

**Source:** the tautological, implementation-coupled, and mock-only-at-seams rules
are adapted from `mattpocock-skills:tdd` (`tests.md` and `mocking.md`). Offer to
explain the upstream reasoning when one of them decides a review call.

### Database assertions ARE behavior verification

`assertDatabaseHas` / `assertDatabaseCount` / `assertDatabaseMissing` are the
**correct** way to verify an ingest or sync module such as `SyncTmdbMovies`: the
persisted row *is* the observable behavior, so assert it and treat that as testing
at the seam — see `docs/adr/0002-database-assertions-verify-ingest-behavior.md`.

Where a read interface *does* exist and is the thing under test, prefer it — assert
on what the endpoint or accessor returns rather than re-querying the table behind it.

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
- Keep fixtures minimal in bytes — only **required** whitespace, no needless data. A
  real capture is committed exactly as the API emits it (usually already one line, no
  pretty-print). A **constructed** body (a synthetic shape, or real members you splice
  together) gets minified to a single line the same way — no indentation, no trailing
  whitespace — so it diffs cleanly and matches the sibling fixtures. Don't pad it with
  fields beyond what the captured/spliced members already carry.
- NB: the "never raw inserts or fixtures" rule below is about **DB state** (use
  factories) — it does not apply to these external-response fixtures.

### Design the client so one fake is one shape

Give an API service **one named method per external operation** (`fetchMovie()`,
`fetchChanges()`), not a single generic `request($endpoint, $options)` with
conditional logic inside. A generic fetcher forces the *fake* to grow the same
conditional — a `Http::fake` closure branching on the URL to decide which body to
return — and a test setup that has to think is a test setup people fabricate
bodies to avoid writing.

With one method per operation: each fake returns one fixture, the test shows at a
glance which endpoints it exercises, and a new endpoint can't silently change an
existing test's behavior.

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
frontend `tdd-react-testing` cycle for full-stack features.

## Test-comment standard (strict)

Unlike production code (where comments are trimmed to non-obvious *why*), test
comments are **deliberate and mandatory**. One canonical form, strictly enforced
by `tests/Unit/TestCommentStandardTest.php`:

1. **AAA labels are mandatory, one per block, label-only line.** `// Arrange`,
   `// Act`, `// Assert` — each on its own line carrying ONLY the label (or a
   collapsed concat, see #2). Never append prose to the label line.
2. **Collapse only when one statement serves two roles** (e.g. an exception test
   where `expect(fn () => …)->toThrow(...)` both acts and asserts). Use a single
   concatenated label joined by ` & ` (space-ampersand-space): `// Arrange & Act`,
   `// Act & Assert`. Only `&` — never `/` or a no-space variant.
3. **Missing / unneeded block → never silently absent.** Keep the label, put the
   reason on the next line (two lines):

   ```php
   // Arrange
   // enum under test, no state to set up
   ```
4. **Why-prose always on its own line(s), above the label it explains.** The AAA
   line stays label-only — never the fused `// Arrange: a query that…` form.
5. **Strict-keep (protected — endorse, never trim):**
   - **Fixture-provenance header banners** — byte-exact-vs-hand-authored, dedupe
     counts, why-faked-per-file.
   - **Inline why-comments** for non-obvious mechanics (binding-drop bugs,
     leak-under-test, on-demand-parsing proofs).

## Test-organization standard (strict)

How a test **file** is laid out, enforced by `tests/Unit/TestOrganizationStandardTest.php`
(which scans `tests/**/*.php` + `resources/js/**/*.test.ts(x)` through
`Tests\Support\TestOrganizationScanner`). Machine-checked rules first:

1. **Every `it()` lives inside a `describe()`.** Several top-level describes per
   file are fine — one per behavior area; nesting allowed. Never a top-level test.
2. **Canonical file skeleton**, in this order:
   `declare(strict_types=1)` → `use` imports → `uses(...)` → provenance banner →
   helper `function` declarations → file-level `beforeEach` → `describe`s.
   Helpers go **above** the tests that call them; a banner is rank-neutral (it may
   sit anywhere in the header).
3. **Helper function names are unique across the whole suite** — they share one
   global namespace. A helper wanted by a second file moves to `tests/Pest.php`.
   Both helper rules govern named `function` declarations only; a closure assigned
   to a variable (`$report = function () {…}`) is file-scoped, not global, so it is
   deliberately out of scope.
4. **`it()` descriptions** start lowercase, never start with "should", and are
   unique within their describe (the same description may repeat in another group).
5. **`describe` labels are unique within a file.**

Judgment rules the scanner can't check:

- **Label style: subject + facet**, method names keeping their parens —
  `describe('series() JWT auth', …)`, `describe('episodes() pagination', …)`.
- **Happy path first**, edge cases and failures last within a describe.
- **`beforeEach` may be file-level or per-`describe`**; prefer a per-`describe`
  one over repeating the same arrange in every `it()` of that group.

## RED checklist (for tdd-test-writer)

- A small cohesive set (2–6) of failing tests for one behavior slice; each describes
  one user-observable behavior.
- **Arrange–Act–Assert (mandatory):** three blank-line-separated blocks, one Act
  per test. Label form per the strict standard above.

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
