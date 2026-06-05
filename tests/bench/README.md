# Thumbro benchmark harness

Measures **file size**, **perceptual quality** (SSIMULACRA2), and **performance**
(wall time + peak RSS) for Thumbro's handlers against the MediaWiki baselines
(ImageMagick primary; GD where it is a real core path), and applies the acceptance
gate from `docs/superpowers/specs/2026-06-05-benchmark-harness-design.md` (§6).

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
- **Very small thumbnails (≤120px) give unstable, low SSIMULACRA2** regardless of real
  quality (the metric has too few scales). Treat ≤120px quality as advisory and
  corroborate via the `--visual` contact sheet. See spec §5.2.

## Acceptance gate (summary)

Production-to-production dominance vs each applicable baseline (smaller at ≥ quality, or
better at ≤ size) → PASS; baseline dominates or a hard constraint breached (quality < 50,
time > 3 s static / 10 s animated, RSS > 512 MB) → FAIL; a genuine trade-off →
INCOMPARABLE (record a decision). Full detail: spec §6.

## Add a fixture
Edit `bin/make_corpus.php`, run it, add an entry to `corpus/manifest.json`.

## Add a contender
Implement `src/Contender.php` under `src/contenders/`, register it in
`Orchestrator::__construct()` (as a baseline or candidate).
