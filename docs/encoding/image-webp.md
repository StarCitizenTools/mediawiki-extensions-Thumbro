# image/webp — encoding profile

**Current profile** (`extension.json` → the `vips-webp` entry's `options` in `ThumbroOptions["image/webp"].encode`):

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

## How it compares to MediaWiki

A WebP *source* keeps its format through the pipeline, so MediaWiki's own scalers do apply
here: with `$wgUseImageMagick = true` ImageMagick re-encodes the WebP (the binding baseline),
and the out-of-the-box GD default decodes/re-encodes a *static* WebP (the floor). The IM WebP
baseline takes **no explicit `-quality`** (IM's default), faithfully representing an
unconfigured install; only the JPEG baseline pins a quality. The GD floor writes WebP at
**Q80** (`imagewebp(..., 80)`), mirroring GD's JPEG floor — GD has no "default" to fall back on
the way IM does, so a fixed quality is used. Each cell is **size / quality** (SSIMULACRA2,
0–100, higher is better). ImageMagick and GD output WebP; so does Thumbro.

| Image · width | ImageMagick | GD | Thumbro | Result |
| --- | --- | --- | --- | --- |
| photo.webp · 180px | 20.5 KB / 71.8 | 8.7 KB / 74.1 | 26.9 KB / 79.3 | ~ trade-off |
| photo.webp · 250px | 25.9 KB / 73.9 | 15.1 KB / 79.3 | 38.4 KB / 87.8 | ~ trade-off |
| photo.webp · 400px | 42.9 KB / 72.8 | 35.0 KB / 78.6 | 68.5 KB / 86.8 | ~ trade-off |
| anim.webp · 250px (44f) | 125.2 KB / 64.3 | — (animated) | 223.8 KB / 84.8 | ~ trade-off |

✅ **win** — smaller at no-worse quality · ~ **trade-off** — *here, larger but higher quality* (the
opposite direction from the jpeg/gif docs' smaller-but-lower-quality trade-offs); see note ·
✗ **loss** — bigger and no better

**Tally: 0 win · 4 trade-off · 0 loss.** Every cell is the *same* trade-off: Thumbro is larger
but markedly higher quality (+7.5 to +20.5 SSIMULACRA2). The current profile re-encodes at a high
Q (90), so for an already-compressed WebP source it buys quality at the cost of size rather
than the smaller-at-equal-quality win Thumbro targets elsewhere. This is the honest baseline
state and the motivation for the planned WebP encoding tuning — see *Accepted trade-offs* and
*History*. GD never dominates a static cell (it is smaller but enough lower in quality to clear
the noise tolerance), so the floor holds; GD has no animated path, so it is `UNAVAILABLE` for
animated WebP and applies no floor there (a default GD-only MediaWiki would drop the animation
to a static frame entirely — Thumbro preserves it). Numbers from `tests/bench/benchmark.php`.

## Accepted trade-offs

- **All four representative cells are INCOMPARABLE (larger but higher quality), pending the
  WebP tuning follow-up.** At Q90 Thumbro re-encodes a WebP source 31–79% larger than
  ImageMagick while scoring 7–20 SSIMULACRA2 higher (the animation, +20.5, is the widest gap —
  IM's coalesced WebP bands to *medium* at 64.3 where Thumbro holds *high* at 84.8). Under the
  trade-off principle this is a legitimate quality-for-size exchange, but inflating an
  already-compressed source is not the size win Thumbro aims for. **Decision:** record the
  trade-offs as the current state and carry the profile unchanged into the dedicated WebP
  tuning (the JPEG precedent — Q90 → Q80 turned 2 win / 4 trade-off / 3 loss into 6 win — is
  the template; the WebP knee is expected to convert several of these to wins).

**Performance:** Thumbro is well within the hard caps — static cells peak ~35 MB / < 0.15 s;
the 44-frame animation ~94 MB / ~0.74 s (animated time ceiling 10 s, RSS 512 MB).

## History

- **2026-06-06 — gated.** WebP gained a real baseline: ImageMagick (binding) and GD
  (static-only floor) now apply to `image/webp`, so it gets a dominance verdict like the other
  MIMEs (ADR-0001 §2). Result on the current Q90 profile: **0 win · 4 trade-off · 0 loss** —
  larger but higher quality across the board. Profile unchanged this round; the trade-offs set
  up the dedicated WebP tuning follow-up.
- **2026-06-06 — measured** (superseded by the row above). Recorded on the redesigned gate
  with no baseline yet, as the shared WebP/fallback block — tracked, not tuned.

## Reproduce

`php tests/bench/benchmark.php --mime=image/webp` (see `tests/bench/README.md`).
