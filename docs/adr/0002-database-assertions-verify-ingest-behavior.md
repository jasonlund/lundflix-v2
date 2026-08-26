# Database assertions verify ingest behavior

`mattpocock-skills:tdd`'s `tests.md` classifies verifying a write by querying the
database as a bad test ("verifying through external means instead of interface");
we reject that for ingest and sync modules, where `assertDatabaseHas` / `Count` /
`Missing` stay the correct assertion because the persisted row *is* the observable
behavior. `SyncTmdbMovies` and its siblings have no read interface to verify
through, and adding one solely to satisfy the rule is the speculative generality
that `mattpocock-skills:code-review`'s smell baseline flags. Where a read
interface genuinely exists and is the subject under test, prefer it — the
carve-out covers ingest, not everything.
