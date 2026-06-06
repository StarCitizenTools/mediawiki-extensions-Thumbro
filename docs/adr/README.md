# Architecture Decision Records

This directory records **architectural / methodological** decisions for Thumbro — the
"why the system is shaped this way" choices that future contributors will question.

Format: **MADR-lite** — Context · Decision · Options considered · Consequences. Keep them
short. Number sequentially (`NNNN-kebab-title.md`), never renumber, never delete; supersede
instead (set the old one's status to `Superseded by ADR-XXXX`).

**Scope boundary with `docs/encoding/`:** those per-MIME living docs record the
*encoding-profile outcome* for each MIME ("the JPEG profile is Q80, here's why and the
numbers"). ADRs are a higher altitude — *how the system is designed*, including how the
benchmark gate itself works. When an ADR constrains benchmark tuning, it links to
`docs/encoding/`; the reverse too.

Don't backfill old decisions wholesale — write one only when the decision is being made or
revisited.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-image-benchmark-gate.md) | Image benchmark gate: prove improvement over default MediaWiki | Accepted |
