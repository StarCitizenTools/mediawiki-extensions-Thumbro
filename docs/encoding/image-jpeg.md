# image/jpeg — encoding profile

**Current profile** (`extension.json` → `ThumbroOptions["image/jpeg"].outputOptions`):

```json
{ "strip": "true", "Q": "80", "smart_subsample": "false", "effort": "6" }
```

Output format: **WebP**. **Status:** active (2026-06-06).

## Rationale

JPEG sources are photographic. The profile is tuned against the benchmark gate
(see `docs/adr/0001-image-benchmark-gate.md`): dominance vs ImageMagick (the realistic
configured MediaWiki scaler) on the 4K-class corpus at the 1.46 content widths 180/250/400.

- **`smart_subsample: false`** — chroma subsampling tuning helps sharp graphic/text edges,
  not photographs; dropping it is smaller at equal quality on photos.
- **`effort: 6`** — maximum encoder effort. Generation cost is paid once and cached; the
  size win is served on every view (the trade-off principle), and it stays well within the
  hard time cap.
- **`Q: 80`** — the gate knee. On the realistic corpus ImageMagick's JPEG@q80 scores
  modestly on photos, so WebP wins (smaller at no-worse quality) at a much lower Q than the
  original Q90. Sweeping Q through the gate: Q90 → 2 win, Q84 → 3 win, **Q80 → 6 win**, with
  higher Q shipping WebP *larger* than ImageMagick at small sizes.

## How it compares to MediaWiki

Each cell is **size / quality**, where quality is SSIMULACRA2 (0–100, higher is better).
Smaller and higher-quality is the goal. ImageMagick — the scaler most wikis configure — is
the bar Thumbro must beat; GD, the out-of-the-box default, is a floor it must not drop below.
ImageMagick and GD output JPEG; Thumbro outputs WebP.

| Image · width | ImageMagick | GD | Thumbro | Result |
| --- | --- | --- | --- | --- |
| photo · 180px | 7.0 KB / 58.6 | 7.2 KB / 58.5 | **6.4 KB / 65.8** | ✅ win |
| photo · 250px | 12.5 KB / 58.1 | 12.5 KB / 57.0 | **11.3 KB / 64.4** | ✅ win |
| photo · 400px | 28.3 KB / 67.6 | 27.9 KB / 66.3 | **24.3 KB / 72.5** | ✅ win |
| portrait · 180px | 3.9 KB / 93.0 | 4.4 KB / 91.5 | **3.0 KB / 91.3** | ✅ win |
| portrait · 250px | 6.8 KB / 92.1 | 7.3 KB / 90.6 | **4.9 KB / 89.4** | ✅ win |
| portrait · 400px | 15.5 KB / 79.7 | 16.3 KB / 76.8 | **11.2 KB / 77.3** | ✅ win |
| concept-art · 180px | 11.2 KB / 68.3 | 9.2 KB / 57.5 | 6.8 KB / 62.2 | ~ trade-off |
| concept-art · 250px | 19.3 KB / 82.1 | 15.5 KB / 78.9 | 11.0 KB / 73.9 | ~ trade-off |
| concept-art · 400px | 41.7 KB / 81.7 | 33.3 KB / 78.3 | 22.2 KB / 72.5 | ~ trade-off |

✅ **win** — smaller at no-worse quality · ~ **trade-off** — smaller but lower quality ·
✗ **loss** — bigger and no better

**Tally: 6 win · 3 trade-off · 0 loss.** Photo and portrait win across the board (6–28%
smaller); the three trade-offs are concept-art — smaller but lower quality (see *Accepted
trade-offs* below). GD never beats Thumbro on a JPEG cell, so the floor holds throughout.
Numbers from `tests/bench/benchmark.php` on the representative corpus.

## Accepted trade-offs

- **concept-art (detailed painterly content): 3 INCOMPARABLE cells.** At Q80 the digital
  painting is 39–47% smaller but 6–9 SSIMULACRA2 below ImageMagick (stays medium/high band).
  Visually corroborated: at thumbnail scale the Q80 WebP is indistinguishable from the
  MediaWiki JPEG (the SSIMULACRA2 gap overstates the perceptual difference, as with the
  small-thumbnail regime). Accepted: a large per-view size win with no *visible* regression,
  on a content type that is intrinsically hard to compress. Winning these cells would require
  a higher Q that forfeits the photo/portrait size wins and reintroduces losses — a net
  regression on the more common content.

(portrait wins at 180/250px are within the noise tolerance, not dominant — ImageMagick's tiny
JPEG is very efficient at small high-quality sizes — but they are still wins, not trade-offs.)

**Performance:** all cells well under the hard caps (peak RSS ≤ ~180 MB, wall time < 0.5 s)
— the 4K concept-art source drives the peak. Generation cost is paid once and cached.

## History

- **2026-06-06 — Q80** (this profile). Re-tuned on the redesigned gate + 4K corpus
  (ADR-0001). JPEG went from 2 win / 4 trade-off / 3 loss (Q90 on the new corpus) to
  6 win / 3 trade-off / 0 loss.
- **2026-06-06 — Q90, ss off, effort 6** (superseded). First dedicated JPEG profile, tuned
  on the pre-redesign gate/corpus (PR #72). Its numbers were invalidated by the gate
  redesign (broken GD baseline, unrealistic corpus/widths).

## Reproduce

`php tests/bench/benchmark.php --mime=image/jpeg` (see `tests/bench/README.md`). Parameter
exploration: `php tests/bench/bin/sweep-jpeg.php`.
