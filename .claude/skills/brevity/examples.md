# Brevity — before/after examples

Each case shows the checklist applied. Reference, not exhaustive.

## 1. PHP docblock — cut boilerplate, keep type info

Two units in the same file get opposite verdicts. The verdict is about signal,
not about "docblocks are bad."

**Before**
```php
/**
 * The console command signature.
 *
 * @var string
 */
protected $signature = 'catalog:sync';

/**
 * Apply the supplied ratings to one table in a single bulk CASE update.
 *
 * @param  Builder<Movie>|Builder<Show>  $query
 * @param  array<string, array{num_votes: int, average_rating: float}>  $ratings
 */
public function handle(Builder $query, array $ratings): int
```

**After**
```php
protected $signature = 'catalog:sync';

/**
 * Apply the supplied ratings to one table in a single bulk CASE update.
 *
 * @param  Builder<Movie>|Builder<Show>  $query
 * @param  array<string, array{num_votes: int, average_rating: float}>  $ratings
 */
public function handle(Builder $query, array $ratings): int
```

- `@var string` over `$signature` → **cut**. The native `protected $signature`
  declaration already says it; the docblock restates the name. Pure boilerplate.
- The `handle` block → **keep**. `Builder<Movie>|Builder<Show>` and the
  `array{...}` shape are type info PHP can't express natively — Larastan and the
  IDE depend on it. The summary line earns its place by naming the *non-obvious*
  mechanism ("single bulk CASE update"), not by restating `handle`.

## 2. Instruction file — high-signal lines + reorder

**Before** (buried, verbose)
```markdown
## Some notes on testing

We generally like to write tests in a way that is clean and readable. It is
usually a good idea to follow the Arrange-Act-Assert pattern when you can, since
this makes tests easier to follow. One important thing: every test must have
exactly one Act — that is, one action under test. If you find you need a second
action, that's a strong signal you should write a second test instead.
```

**After** (critical rule first, signal only)
```markdown
## Testing

- **One Act per test.** Need a second action → write a second test.
- Follow Arrange–Act–Assert.
```

- The must-follow rule ("one Act per test") moves to the **top** (lost-in-the-
  middle: don't bury hard rules mid-paragraph).
- Hedging and filler ("we generally like", "usually a good idea", "one important
  thing") → **cut**; they carry no constraint.
- AAA stays — it's a real convention — but as one line, not a sentence.

## 3. Guardrail — over-pruning that must be REJECTED

This is what the skill must *refuse* to do, demonstrating "minimal ≠ short".

**Before**
```php
/**
 * Stream the kept, casted data rows as a lazy collection.
 *
 * IMPORTANT: the underlying gz handle is closed in a finally that only runs
 * when the generator completes or is garbage-collected. Callers MUST fully
 * consume the returned collection; abandoning it part-way leaks the handle.
 */
public function rows(): LazyCollection
```

**Tempting over-prune (WRONG)**
```php
/**
 * Stream the kept, casted data rows as a lazy collection.
 */
public function rows(): LazyCollection
```

**Verdict: keep the IMPORTANT block.** The summary line *is* inferable from the
name + return type, but the resource-leak contract is **not** derivable from the
signature — dropping it would cause callers to leak gz handles. Removing it fails
the "would this cause a mistake?" test. The guardrail trips → flag, do not cut.
(You may trim the summary line if the name carries it, but the contract stays.)
