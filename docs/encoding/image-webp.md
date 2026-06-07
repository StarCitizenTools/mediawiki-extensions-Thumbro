# image/webp — encoding profile

**Current profile** (`extension.json` → `ThumbroOptions["image/webp"].encode`):

- **Static** WebP → **cwebp** (libwebp): `{ "q": "80", "m": "6" }` (vips resizes; cwebp encodes).
- **Animated** WebP → **vips-webp**: `{ "strip": "true", "Q": "90", "smart_subsample": "true" }`
  (cwebp cannot encode animation).

Output format: **WebP**. **Status:** active (2026-06-07).

## Rationale

A WebP source is downscaled (always by libvips) and re-encoded to WebP. The encoder is split by
animation:

- **Static → cwebp.** libwebp's `cwebp` is markedly more byte-efficient than libvips `webpsave`
  on the same pixels at the same quality (measured ~2× smaller at tied SSIMULACRA2). At **q80** it
  is the encoder that turns WebP from a size *trade-off* into a clean gate **win** (see below): it
  is smaller than ImageMagick at no-worse quality *and* smaller than the GD floor. (q84+ would
  breach the GD floor on the larger sizes; q80 is the floor-safe knee.)
- **Animated → vips-webp.** cwebp is single-image only; animated WebP keeps the libvips path
  (`Q=90`, all frames). A `vips-webp` catch-all also covers a missing cwebp binary, so static WebP
  degrades gracefully to libvips when libwebp is absent.

The `vips-webp` entries here remain the **shared webpsave fallback** the GIF → libvips delegation
uses (opaque / over-threshold animation) — see `image-gif.md`. cwebp is webp-static-only and is
not a fallback for other MIMEs.

## How it compares to MediaWiki

A WebP *source* keeps its format through the pipeline, so MediaWiki's own scalers apply: with
`$wgUseImageMagick = true` ImageMagick re-encodes the WebP (binding baseline, no explicit
`-quality`), and the out-of-the-box GD default decodes/re-encodes a *static* WebP (floor, written
at Q80). Each cell is **size / quality** (SSIMULACRA2, 0–100, higher is better).

| Image · width | ImageMagick | GD | Thumbro | Result |
| --- | --- | --- | --- | --- |
| photo.webp · 180px | 20.5 KB / 71.8 | 8.7 KB / 74.1 | **8.3 KB / 73.8** | ✅ win |
| photo.webp · 250px | 25.9 KB / 73.9 | 15.1 KB / 79.3 | **14.4 KB / 78.7** | ✅ win |
| photo.webp · 400px | 42.9 KB / 72.8 | 35.0 KB / 78.6 | **33.1 KB / 78.1** | ✅ win |
| anim.webp · 250px (44f) | 125.2 KB / 64.3 | — (animated) | 223.8 KB / 84.8 | ~ trade-off |

✅ **win** — smaller at no-worse quality · ~ **trade-off** — see note · ✗ **loss** — bigger and no better

**Tally (static): 3 win · 0 loss.** With cwebp at q80, every static cell is **smaller than
ImageMagick at no-worse quality** and **smaller than the GD floor** (so the floor holds, decisively
— Thumbro now beats it outright). This is the win the Q90 libvips profile could not reach: libvips
`webpsave` produced a *larger-but-higher-quality* trade-off, and dropping its Q to win on size
breached the GD floor (GD's soft downscale compresses unusually small). cwebp threads that needle.

**Animation stays a trade-off.** `anim.webp` goes through libvips (cwebp can't animate): larger
than ImageMagick (223.8 vs 125.2 KB) but markedly higher quality (84.8 vs 64.3 — *high* vs
*medium* band). A legitimate quality-for-size exchange under the trade-off principle; improving it
would need an animation-capable libwebp encoder (`img2webp`), out of scope. Numbers from
`tests/bench/benchmark.php`.

## Decisions

- **Static WebP → cwebp q80 (2026-06-07).** Swept cwebp q at `-m 6` and compared cwebp@knee vs the
  vips-webp Q90 profile against the gate. cwebp q80 wins all three static widths (smaller than IM
  *and* GD); q84+ breaches the GD floor at 250/400px. Chosen.
- **JPEG and PNG stay vips-webp (cwebp evaluated, not adopted).** See `image-jpeg.md` /
  `image-png.md`: cwebp ties vips-webp on JPEG (no win) and loses badly on PNG (catastrophic on
  screenshots/line-art; cannot reach IM quality on the transparent logo even at q90). vips
  `webpsave` (with `near_lossless` for PNG) stays.
- **Animation stays vips-webp** — cwebp is single-image only.

**Performance:** within the hard caps. Static cwebp adds a vips-resize→PNG→cwebp two-step (a
temp PNG + a second process) — generation-time cost, paid once and cached, for a per-view size
win. The 44-frame animation (libvips) peaks ~94 MB / ~0.74 s (caps: 10 s, 512 MB).

## History

- **2026-06-07 — static→cwebp q80** (this profile). Added the cwebp encoder and routed static
  WebP to it: **0 win · 4 trade-off → 3 static win** (+ the animation trade-off unchanged). cwebp
  is ~2× more byte-efficient than libvips `webpsave`, converting the Q90 trade-offs into wins
  while clearing the GD floor.
- **2026-06-06 — gated, Q90 libvips** (superseded). WebP gained a baseline (IM binding, GD
  static-only floor; ADR-0001 §2); the Q90 libvips profile scored 0 win / 4 trade-off (larger but
  higher quality). That trade-off motivated the cwebp work above.
- **2026-06-06 — measured** (superseded). Recorded on the redesigned gate with no baseline yet.

## Reproduce

`php tests/bench/benchmark.php --mime=image/webp` (see `tests/bench/README.md`). cwebp parameter
exploration: `php tests/bench/bin/sweep-cwebp.php`.
