# Refused titles are stored, not dropped

TMDB and IMDb both ship records the app deliberately never surfaces — adult,
softcore, and promo (`video:true`) titles — and the ingest legs used to drop them
before the upsert. A dropped record gets no row, so it never gets a
`tmdb_synced_at` stamp, so the membership probe reports it missing on every
subsequent run: 61,765 of the TMDB `movie_ids` export's 1,236,627 rows are
`video:true`, and re-hydrating them accounted for 94% of the gap between the
export and the catalog (FLIX-286). We store the row and filter at read instead.
The trade-off is a catalog table holding rows no user will ever see and a filter
every read path has to carry, taken over a separate rejected-ids table because
the columns already exist as raw source fields and a second table would need its
own reconciliation.
