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

| MIME | Profile | Output | Baseline |
| --- | --- | --- | --- |
| [image/jpeg](image-jpeg.md) | `Q=80, smart_subsample=false, effort=6, strip` | WebP | IM (+ GD floor) |
| [image/png](image-png.md) | `near_lossless, Q=60, strip` | WebP (near-lossless) | IM (+ GD floor) |
| [image/gif](image-gif.md) | `libwebp`/gif2webp `mixed, q=80, m=4` (routed; see file) | WebP (anim / static) | IM only |
| [image/webp](image-webp.md) | `Q=90, smart_subsample=true, strip` (also the shared fallback) | WebP | none |

Baseline = which MediaWiki default scaler the gate compares against. `image/webp` input has no
MediaWiki baseline, so it is documented but not dominance-tested; `image/gif` has no GD path
(ImageMagick only).
