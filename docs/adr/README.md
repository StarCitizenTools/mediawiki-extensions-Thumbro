# Architecture Decision Records

This directory records **architectural / methodological** decisions for Thumbro — the
"why the system is shaped this way" choices that future contributors will question.

Format: **MADR-lite** — Context · Decision · Options considered · Consequences. Keep them
short. Number sequentially (`NNNN-kebab-title.md`), never renumber, never delete; supersede
instead (set the old one's status to `Superseded by ADR-XXXX`).

**Scope boundary with `tests/bench/DECISIONS.md`:** that file logs per-MIME benchmark
*option-set results* ("we chose Q90 for JPEG, here are the numbers"). ADRs are a higher
altitude — *how the system is designed*, including how the benchmark gate itself works.
When an ADR constrains benchmark tuning, it links to `DECISIONS.md`; the reverse too.

Don't backfill old decisions wholesale — write one only when the decision is being made or
revisited.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-image-benchmark-gate.md) | Image benchmark gate: prove improvement over default MediaWiki | Accepted |
