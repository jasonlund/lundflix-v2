# Database assertions verify ingest behavior

The AI Hero `tdd` skill classifies verifying a write by querying the database as a
bad test ("verifying through external means instead of interface"). We reject that
for this app: `assertDatabaseHas`/`Count`/`Missing` remain the correct way to test
an ingest or sync module, because the persisted row *is* the observable behavior —
`SyncTmdbMovies` and its siblings have no read interface to verify through, and
adding one solely to satisfy the rule is the speculative generality that skill's
own smell baseline flags.

Where a read interface genuinely exists and is the subject under test, prefer it.
The carve-out covers ingest, not everything.

Recorded because it contradicts a cited authority, and because ~31 test files
depend on it — without this, an imported smell baseline would flag them all
(FLIX-277).
