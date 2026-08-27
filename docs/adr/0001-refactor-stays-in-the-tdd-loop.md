# Refactor stays inside the TDD loop

Canon TDD as taught by `mattpocock-skills:tdd` puts refactoring in the review
stage, outside the red → green loop; we instead keep REFACTOR as the third phase
of every cycle (`tdd-refactorer`), plus a PR-wide sweep
(`review-tdd-cross-ticket`). The trade-off: our per-slice refactor is already
cheaply gated — green in, green out, and free to skip when the code is minimal —
while deferring cleanup to review moves it past the moment the author still holds
the context that makes it obvious.
