# Unresolvable rows are deferred, not retired

`catalog:sync-shows-tmdb` walks every show that carries a resolvable id and no
`tmdb_synced_at` stamp, so a row TMDB cannot resolve is re-`/find`ed on every run
forever — 95,340 rows, ~55% of the show universe, per production run (FLIX-291).
The row cannot simply be stamped synced, because it never was: a `/find` miss is
genuinely retryable and TMDB may publish the crosswalk later. We count the failed
attempts on the row instead and push its next one out on a doubling interval
ceilinged at 64 days, so the residue thins toward a handful of attempts a year
without ever being retired. The trade-off is two bookkeeping columns and a real
lag — a crosswalk TMDB publishes today may go unnoticed for two months — taken
over a never-retry flag, which would have been cheaper and wrong.
