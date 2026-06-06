# Encoding profiles (per MIME)

Living records of **how Thumbro encodes each source MIME type to a thumbnail, and why**.
One file per MIME (`image-jpeg.md`, `image-png.md`, …). Each holds the *current* profile
(the `extension.json` → `ThumbroOptions["<mime>"].outputOptions` block), the rationale,
accepted trade-offs, and a history section — updated in place when the profile changes
(append to History; don't start a new file).

These are the benchmark gate's "recorded decision" home (see `AGENTS.md` →
"Benchmarking handlers"): every option-set choice, every INCOMPARABLE verdict, and every
accepted memory/time regression is captured in the relevant MIME's file, with numbers.

**Boundary with `docs/adr/`:** ADRs record *architecture and methodology* — how the gate
itself works (baselines, dominance, tolerance, corpus). These encoding files record the
*per-MIME tuning outcome* the gate produces. ADRs are durable and rare; encoding profiles
change whenever a sweep finds a better option set.

Numbers come from `tests/bench/benchmark.php` against the corpus; the live profiles are in
`extension.json`.

| MIME | Profile | Output |
| --- | --- | --- |
| [image/jpeg](image-jpeg.md) | `Q=80, smart_subsample=false, effort=6, strip` | WebP |
| [image/png](image-png.md) | `near_lossless, Q=60, strip` | WebP (near-lossless) |

(`image/webp` source has no MediaWiki baseline to gate against; `image/gif` uses the
libwebp/animation path — neither has a tuning record yet.)
