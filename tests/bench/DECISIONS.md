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
