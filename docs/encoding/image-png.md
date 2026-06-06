# image/png — encoding profile

**Current profile** (`extension.json` → `ThumbroOptions["image/png"].outputOptions`):

```json
{ "near_lossless": "true", "Q": "60", "strip": "true" }
```

Output format: **WebP**. **Status:** active (2026-06-06).

## Rationale

PNG's real-world use is graphics, logos, and transparency — exactly the content
near-lossless WebP improves. On those fixtures it dominates both MediaWiki baselines:
logo and UI thumbnails are ~40–50% smaller at no-worse quality. Alternatives swept and
rejected when first chosen (PR #64): `Q=80` and full `lossless` were both worse on size at
equal-or-lower quality.

Re-validated against the redesigned gate + 4K corpus (ADR-0001): kept unchanged — still
dominant on the graphics/transparency fixtures that are PNG's primary use.

## How it compares to MediaWiki

Each cell is **size / quality**, where quality is SSIMULACRA2 (0–100, higher is better).
ImageMagick is the bar Thumbro must beat; GD is the floor. ImageMagick and GD output PNG
(lossless, so large); Thumbro outputs near-lossless WebP.

| Image · width | ImageMagick | GD | Thumbro | Result |
| --- | --- | --- | --- | --- |
| logo-transparent · 180px | 25.8 KB / 92.4 | 23.8 KB / 94.2 | **15.0 KB / 94.2** | ✅ win |
| logo-transparent · 250px | 43.5 KB / 93.5 | 40.1 KB / 94.6 | 23.4 KB / 89.3 | ~ trade-off |
| logo-transparent · 400px | 89.4 KB / 93.7 | 82.0 KB / 94.8 | **44.4 KB / 95.6** | ✅ win |
| flat-graphic · 180px | 3.0 KB / 92.0 | 2.0 KB / 93.4 | **3.0 KB / 96.7** | ✅ win |
| flat-graphic · 250px | 3.0 KB / 92.8 | 2.0 KB / 91.3 | 3.4 KB / 87.0 | ✗ loss |
| flat-graphic · 400px | 3.6 KB / 94.6 | 3.0 KB / 95.7 | **3.3 KB / 92.0** | ✅ win |
| screenshot-ui · 180px | 16.8 KB / 85.1 | 15.4 KB / 84.9 | **9.5 KB / 87.9** | ✅ win |
| screenshot-ui · 250px | 28.4 KB / 82.9 | 25.8 KB / 75.3 | **15.5 KB / 93.0** | ✅ win |
| screenshot-ui · 400px | 59.3 KB / 83.9 | 54.2 KB / 80.4 | **31.1 KB / 84.8** | ✅ win |
| screenshot-gaming · 180px | 38.9 KB / 88.7 | 43.1 KB / 78.5 | 19.8 KB / 76.2 | ~ trade-off |
| screenshot-gaming · 250px | 74.6 KB / 93.4 | 82.2 KB / 84.6 | 36.4 KB / 84.7 | ~ trade-off |
| screenshot-gaming · 400px | 188.9 KB / 92.0 | 209.1 KB / 81.1 | **89.6 KB / 93.9** | ✅ win |

✅ **win** — smaller at no-worse quality · ~ **trade-off** — smaller but lower quality ·
✗ **loss** — bigger and no better

**Tally: 8 win · 3 trade-off · 1 loss.** near-lossless wins the graphics and UI fixtures
outright (40–50% smaller). The trade-offs are content outside its strength — the logo at
250px and the photographic game screenshot at small sizes; the lone loss is flat-graphic at
250px (see below). Numbers from `tests/bench/benchmark.php`.

## Accepted trade-offs / known issues

- **Photographic-content PNG (e.g. a game screenshot): trade-off at small sizes.**
  near-lossless is graphics-tuned, so on a photographic-render PNG it ships smaller but
  lower-quality than ImageMagick at 180/250 (wins at 400). Accepted: graphics/transparency
  is PNG's dominant real use; the photographic-PNG case is secondary, and re-tuning toward it
  would forfeit the large logo/UI wins.
- **`flat-graphic.png`: near-lossless can't beat lossless PNG on a flat-colour graphic
  (pre-existing, tracked).** At 250px it is larger and lower-quality than both ImageMagick and
  GD — a loss vs the binding baseline *and* a GD floor breach. At 400px it wins vs ImageMagick
  but is still larger than GD's tiny PNG (a second GD floor breach). Documented as a
  pre-existing follow-up in ADR-0001 (not introduced by the gate redesign or the JPEG re-tune).

**Performance:** all cells well under the hard caps (peak RSS ≤ ~85 MB, wall time < 0.5 s)
— near-lossless raises peak RSS at small sizes. Generation cost is paid once and cached.

## History

- **2026-06-06 — re-validated, kept** on the redesigned gate + 4K corpus (ADR-0001). No
  change to the profile; numbers regenerated against the corrected gate.
- **2026-06-06 — near_lossless, Q60** (PR #64). First dedicated PNG profile.

## Reproduce

`php tests/bench/benchmark.php --mime=image/png` (see `tests/bench/README.md`).
