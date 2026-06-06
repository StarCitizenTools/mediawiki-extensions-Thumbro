# image/gif — encoding profile

**Backend:** `libwebp` (`gif2webp`), with `inputOptions: { "n": "-1" }` (read all frames).
gif2webp flags (`ThumbroLibraries.libwebp.flags`): `{ "mixed": "", "q": "80", "m": "4" }`.
Output format: **WebP** (animated, or a static first frame). **Status:** active (2026-06-06).

## Routing

Unlike the other MIMEs, GIF has no single `outputOptions` block — the `Libwebp` backend owns
the runtime policy (see `AGENTS.md` → "Media handlers"):

- **Transparent animated GIF** under `$wgThumbroMaxAnimatedArea` (with `gif2webp` present) →
  encoded by **gif2webp** (`mixed` lossy/lossless, `q=80`, `m=4`). gif2webp handles per-frame
  alpha efficiently — this is the path that avoids the libvips animated-WebP alpha blow-up.
- **Opaque animated GIF**, and the **gif2webp-unavailable fallback** → delegated to **libvips**
  as animated WebP (all frames preserved; uses the shared `image/webp` save options).
- **Static GIF**, and **animation over the threshold** → delegated to **libvips** as a static
  first frame.

## How it compares to MediaWiki

Each cell is **size / quality** (SSIMULACRA2, 0–100, higher is better). ImageMagick is the
binding baseline; it outputs **GIF** (256-colour palette). **GD has no animated-GIF path, so
it is not a baseline for this MIME** (no floor row). Thumbro outputs WebP.

| Image · width | ImageMagick (GIF) | Thumbro (WebP) | Result |
| --- | --- | --- | --- |
| sprite · 120px | 5.6 KB / 42.9 | 8.1 KB / 73.0 | ~ trade-off |
| sprite · 250px | 16.4 KB / 33.7 | 23.3 KB / 52.0 | ~ trade-off |
| anim-opaque · 250px (44f) | 669.6 KB / 80.9 | **250.9 KB / 84.9** | ✅ win |

✅ **win** — smaller at no-worse quality · ~ **trade-off** — see note · ✗ **loss** — bigger and no better

The animation win is the headline: **63% smaller at higher quality** than ImageMagick's
coalesced GIF. The sprite trade-offs are favourable — Thumbro is larger but *dramatically*
higher quality (73.0 vs 42.9, 52.0 vs 33.7), because ImageMagick's 256-colour palette GIF
bands badly on the rasterised sprite while Thumbro's WebP does not. Numbers from
`tests/bench/benchmark.php`.

## Accepted trade-offs

- **`sprite.gif` (static): larger than ImageMagick, but far higher quality.** A small
  rasterised icon; Thumbro's WebP is ~40% larger yet visually much cleaner than IM's
  palette-banded GIF. Accepted — the size delta is small (single-digit KB) and the quality
  gain is large.
- **Transparent animation (stress):** `anim-transparent.gif` exercises the gif2webp alpha
  path. Quality is **advisory** on the stress tier (SSIMULACRA2 is unreliable on tiny
  transparent animation — see `tests/bench/README.md`); the real guards are size/time/RSS,
  which pass with room to spare (no blow-up).

**Performance:** within the hard caps (animated time ceiling 10 s, RSS 512 MB) — animated GIFs
peak well under both (e.g. anim-opaque ~66 MB / ~0.45 s vs ImageMagick's ~198 MB).

## History

- **2026-06-06 — measured** on the redesigned gate (ADR-0001); routing/flags unchanged,
  numbers recorded. The gif2webp flags were **not** swept: they affect only the
  transparent-animation path (a stress fixture with advisory quality), and the representative
  cells are governed by the shared `image/webp` block — so there is little gif-specific tuning
  headroom. gif2webp policy and the libvips-delegation seam landed earlier (PR #57).

## Reproduce

`php tests/bench/benchmark.php --mime=image/gif` (see `tests/bench/README.md`).
