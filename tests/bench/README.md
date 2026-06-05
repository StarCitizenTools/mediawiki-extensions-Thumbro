# Thumbro benchmark harness

Measures **file size**, **perceptual quality** (SSIMULACRA2), and **performance**
(wall time + peak RSS) for Thumbro's handlers against the MediaWiki baselines
(ImageMagick primary; GD where it is a real core path), and applies the acceptance
gate described under **Acceptance gate** below.

## Dev dependencies (Debian 12 dev image)

```sh
apt-get install -y imagemagick libvips-tools webp time
bash tests/bench/bin/install-ssimulacra2.sh   # SSIMULACRA2 metric (needs root + network)
```

- **SSIMULACRA2** (quality metric, v2 0–100): installed by `install-ssimulacra2.sh` as
  `ssimulacra2_rs`. NOTE: `libjxl-tools` 0.7 ships only SSIMULACRA **v1**, not v2, and
  no prebuilt v2 binary was available — so we install the pure-Python `ssimulacra2`
  PyPI package (pinned). It is correct but slow (~0.6 s/frame); swappable for a
  compiled binary later by editing `Ssimulacra2::$bin` + the install script.
- `convert` (imagemagick), `vips`/`vipsthumbnail` (libvips-tools): thumbnailing,
  reference downscale, and animated-frame extraction (`anim_dump` is NOT packaged, so
  animated-WebP frames are extracted with `convert -coalesce`).
- `time` (GNU time): peak-RSS capture.

## Run

```sh
php tests/bench/benchmark.php --out=/tmp/bench               # whole corpus
php tests/bench/benchmark.php --mime=image/jpeg --out=/tmp/bench
php tests/bench/benchmark.php --mime=image/gif --visual      # one MIME + contact sheet
```

Outputs `results.json`, a printed table, and (with `--visual`) `visual/index.html`.
Animation runs are slow (metric is per-frame ~0.6 s).

## Interpreting scores (read this)

- SSIMULACRA2 bands: ≥90 visually lossless, ≥70 high, ≥50 medium, <50 low.
- **Corpus must be structured content, never noise** — pure noise (e.g. plasma) scores
  terribly under a reference metric and is meaningless.
  (Exception: `tiny.png` is a perf/size-extreme fixture at near-zero downscale, where content doesn't affect the score.)
- **Very small thumbnails (≤120px) give unstable, low SSIMULACRA2** regardless of real
  quality (the metric has too few scales). Treat ≤120px quality as advisory and
  corroborate via the `--visual` contact sheet.
- **Transparent content** is flattened over a fixed grey background before scoring
  (SSIMULACRA2 is RGB-only; raw alpha yields garbage/negative scores). Both candidate
  and reference are flattened identically, so visible content is compared fairly — but
  alpha-channel fidelity itself is *not* scored. Corroborate transparent fixtures via
  the `--visual` contact sheet.
- **Animated fixtures are scored at 84px only.** The pure-Python SSIMULACRA2 costs
  ~0.6 s/frame at 84px but ~6 s/frame at 320px, so a many-frame fixture at 320px makes
  the run intractable. File **size** at larger sizes is the size axis (no metric needed)
  and can be checked directly with `vipsthumbnail`/`gif2webp`. A compiled SSIMULACRA2
  binary would lift this limit (see `Ssimulacra2::$bin`).

## Acceptance gate (summary)

Production-to-production dominance vs each applicable baseline (smaller at ≥ quality, or
better at ≤ size) → PASS; baseline dominates or a hard constraint breached (quality < 50,
time > 3 s static / 10 s animated, RSS > 512 MB) → FAIL; a genuine trade-off →
INCOMPARABLE (record a decision in the PR).

## Add a fixture
Edit `bin/make_corpus.php`, run it, add an entry to `corpus/manifest.json`.

## Add a contender
Implement `src/Contender.php` under `src/Contenders/`, register it in
`Orchestrator::__construct()` (as a baseline or candidate).
