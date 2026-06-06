# image/webp — encoding profile

**Current profile** (`extension.json` → `ThumbroOptions["image/webp"].outputOptions`):

```json
{ "strip": "true", "Q": "90", "smart_subsample": "true" }
```

Output format: **WebP** (re-encode + downscale). **Status:** active (2026-06-06).

## Rationale

A WebP source is downscaled and re-encoded to WebP. Q is kept high (90) with
`smart_subsample` on — a conservative, general-purpose profile: re-encoding already-compressed
WebP shouldn't add much loss, and `smart_subsample` protects sharp chroma edges (a WebP source
may be a graphic or a screenshot, not just a photo).

This block doubles as the **shared fallback**: any MIME whose own block is absent inherits
these save options, and the GIF → libvips delegation (opaque / over-threshold animation) uses
them too. So changing it affects more than WebP input — see `image-gif.md`.

## No baseline (not dominance-tested)

There is **no comparison table here.** MediaWiki's default scalers don't emit WebP, and the
benchmark has no WebP baseline contender — neither ImageMagick nor GD applies to WebP *input*
— so `image/webp` has no win/loss verdict against MediaWiki. The harness still renders the
thumbnail and tracks its quality (vs the lossless vips reference) for sanity, and the stress
caps apply, but this MIME is not gate-validated for "improvement over MediaWiki" the way
JPEG/PNG/GIF are.

Thumbro's own measurements (no baseline; for reference / regression-tracking):

| Image · width | Thumbro (WebP) |
| --- | --- |
| photo.webp · 180px | 26.9 KB / 79.3 |
| photo.webp · 250px | 38.4 KB / 87.8 |
| photo.webp · 400px | 68.5 KB / 86.8 |
| anim.webp · 250px (44f) | 223.8 KB / 84.8 |

Size / SSIMULACRA2. Performance well within the hard caps (peak RSS ≤ ~95 MB, wall time < 1 s).

## History

- **2026-06-06 — measured** on the redesigned gate (ADR-0001); profile unchanged. It has no
  baseline to validate against (see above) and is the shared WebP/fallback block, so it is
  recorded, not tuned.

## Reproduce

`php tests/bench/benchmark.php --mime=image/webp` (see `tests/bench/README.md`).
