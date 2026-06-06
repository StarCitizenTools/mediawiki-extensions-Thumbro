# Benchmark tuning decisions

Recorded per the acceptance gate (see `AGENTS.md` → "Benchmarking handlers"). One entry
per handler/option-set change. Record the verdict, the representative numbers, and any
accepted trade-off. Every INCOMPARABLE verdict and every accepted memory/time regression
must be recorded here, with the reasoning.

## 2026-06-06 — image/png: near-lossless WebP

**Change:** `image/png` `outputOptions` → `{ near_lossless: true, Q: 60, strip: true }`
(previously inherited the shared `image/webp` block).

**Verdict:** dominates both MediaWiki baselines (ImageMagick and GD) on every PNG fixture
in the corpus. Representative: transparent PNG at 320px, 5754 B → 3752 B (−35%) at
SSIMULACRA2 97.8 — flipping a prior baseline FAIL into a win. Alternatives swept and
rejected: `Q=80` and full `lossless` were both worse on size at equal-or-lower quality.

**Accepted trade-off:** near-lossless raises peak RSS at small sizes (transparent PNG at
320px: ~45 MB → ~64 MB) and adds a little wall time. Both stay well within the hard caps
(512 MB RSS, 3 s static). Accepted under the trade-off principle: the extra generation
cost is paid once and cached, while the smaller, higher-quality thumbnail is served on
every view.

**Why PNG specifically:** PNG's real-world use is graphics, logos, and transparency —
exactly the content near-lossless improves — so the win lands where it matters and the
memory cost lands on the format that benefits most.

**Full harness results:** PR #64.

## 2026-06-06 — image/jpeg: photographic WebP profile (drop subsampling, max effort)

**Change:** `image/jpeg` gets its own `outputOptions` →
`{ strip: true, Q: 90, smart_subsample: false, effort: 6 }` (previously empty, so it
inherited the shared `image/webp` block: `{ strip, Q: 90, smart_subsample: true }` at the
vips-default effort 4).

**Method:** parameter sweep via `tests/bench/bin/sweep-jpeg.php` over
`Q × smart_subsample × preset × effort` on the representative JPEG fixtures
(`photo.jpg`, `portrait.jpg`), scored with SSIMULACRA2 against the gate's own reference,
then confirmed with `benchmark.php --mime=image/jpeg`.

**Verdict vs ImageMagick (primary baseline): 5 win · 1 INCOMPARABLE · 0 loss**, up from
the inherited profile's **2 win · 4 trade-off**. The new profile is smaller on every cell
(−2.7% to −12.5% vs the old profile) and flips three cells from trade-off to clean win:

| cell | old (Q90, ss on, effort 4) | new (Q90, ss off, effort 6) | IM baseline |
| --- | --- | --- | --- |
| photo @640 | 146 438 B / 82.9 — *trade-off* | 139 912 B / 82.9 — **win** | 142 630 B / 78.5 |
| portrait @250 | 14 548 B / 80.1 — *trade-off* | 13 974 B / 78.9 — **win** | 14 411 B / 76.8 |
| portrait @640 | 57 530 B / 82.8 — *trade-off* | 50 364 B / 82.1 — **win** | 52 317 B / 80.2 |

**Headline finding:** for photographic JPEG→WebP the byte savings come from **dropping
`smart_subsample` and maxing `effort`, not from lowering Q.** Lowering Q forfeits
dominance: the gate requires `candQuality ≥ baseQuality` to win, and IM's JPEG baseline
quality is high (≈76–80 at 250/640px), so e.g. `Q=82` lands *smaller but lower-quality*
(INCOMPARABLE) and also breaches the 50 floor at portrait @120 (45.9). `preset=photo`
was swept and rejected — it lowered SSIMULACRA2 without shrinking output.

**INCOMPARABLE (recorded):** portrait @120px — 7 538 B / 57.5 vs IM 7 854 B / 59.5:
smaller but 2 points lower SSIMULACRA2, so neither dominates. This is the unstable
≤120px regime the README flags as advisory, and it clears the hard quality floor (50).
Accepted: a 2-point metric wobble on the smallest thumbnail is not worth raising Q
across all sizes for.

**GD baseline:** 6 INCOMPARABLE (unchanged from before). GD emits tiny, visually broken
JPEGs (SSIMULACRA2 as low as 4.8 at 640px), so WebP can never be "smaller at ≥ quality"
against it but is dramatically higher quality. Pre-existing behaviour, not a regression.

**Accepted trade-off:** `effort=6` raises generation wall time (e.g. photo @640
~209 ms → ~272 ms); peak RSS is unchanged-to-lower. Well within the hard caps (3 s
static, 512 MB). Accepted under the trade-off principle — generation cost is paid once
and cached, the smaller file is served on every view.

**Full harness results:** `benchmark.php --mime=image/jpeg` before/after captured this
change; sweep raw data in `sweep-results.json`.

## 2026-06-06 — gate: advisory quality at ≤120px

**Change:** `AcceptanceGate::evaluate()` now takes a `qualityAdvisory` flag; the Orchestrator
sets it for representative cells at or below `GateThresholds::qualityAdvisoryMaxWidth` (120px).
When advisory, a sub-floor SSIMULACRA2 is recorded as a `quality-floor-advisory` flag instead
of a hard FAIL, and a quality gap within `qualityWithinOfBaseline` (5.0) counts as a tie for
dominance. `evaluateCaps()` (stress tier) is unchanged.

**Why:** the README already documents that SSIMULACRA2 is unstable at ≤120px ("treat as
advisory, corroborate visually"), but the gate still hard-failed on those scores — a
contradiction. Corroborated visually: at portrait @120px a Q84 thumbnail scoring S2 47.5 is
indistinguishable from the MediaWiki JPEG baseline scoring S2 59.5 (3× point-zoom), confirming
the gap is metric jitter, not real degradation.

**Effect:** at Q90 the image/jpeg result goes from 5 win / 1 trade-off to **6 win / 0
trade-off vs ImageMagick** — portrait @120 (7 538 B / 57.5 vs IM 7 854 B / 59.5) was the lone
trade-off and is now a clean win. The rule is monotonic — it can only soften a ≤120px verdict
(FAIL→flag, or trade-off→win within the noise band), never harden one — so no other MIME's
verdict regresses. GD comparisons are unchanged (its tiny broken thumbnails remain INCOMPARABLE
on size).

**Scope note:** the flag keys on target width. Animation is scored at 84px regardless of
target (a separate, documented harness limitation) and is not folded into this rule; its cells
go through the strict `evaluateCaps` (stress) or non-advisory `evaluate` (representative)
paths as before.

**Superseded (2026-06-06, ADR-0001):** the size-keyed ≤120px advisory was generalised into
a single uniform quality noise-tolerance applied at every width, and the representative
corpus no longer measures ≤120px; the `qualityAdvisory`/`qualityAdvisoryMaxWidth` mechanism
was removed.

## 2026-06-06 — gate redesign (see ADR-0001)

The image benchmark gate was redesigned (`docs/adr/0001-image-benchmark-gate.md`): dominance
vs ImageMagick with a SSIMULACRA2 noise-tolerance, GD as a never-regress floor (fixed to
`imagecopyresampled`, faithful to `BitmapHandler::transformGd`), performance retained as
hard safety caps + soft budgets (not a dominance axis), and the representative corpus refit
to 180/250/400 with 4K-class sources.

**GD numbers in earlier entries are invalid** — they came from a GD contender that used
`imagescale()` and produced broken scores. Per-MIME options (JPEG, PNG) must be
re-validated against the new gate + corpus, and all numbers regenerated. That re-validation
is the immediate follow-up PR and is **not** done here.
