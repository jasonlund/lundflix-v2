# Refactor stays inside the TDD loop

Canon TDD as taught by the AI Hero `tdd` skill puts refactoring in the review
stage, outside the red → green loop. We keep REFACTOR as the third phase of every
cycle (`tdd-refactorer`), plus a PR-wide sweep (`review-tdd-cross-ticket`),
because our per-slice refactor is already cheaply gated — green in, green out, and
free to skip when the code is minimal — while deferring cleanup to review moves it
past the moment the author still holds the context that makes it obvious.

Adopted with the rest of the AI Hero skill fold (FLIX-277). Recorded so a future
architecture pass doesn't re-suggest moving it.
