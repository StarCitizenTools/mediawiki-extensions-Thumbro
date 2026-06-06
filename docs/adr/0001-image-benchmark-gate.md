# 1. Image benchmark gate: prove improvement over default MediaWiki

- Status: Accepted
- Date: 2026-06-06

## Context

Thumbro exists to be a demonstrable improvement over default MediaWiki thumbnailing:
**equal-or-better perceptual quality at a smaller file size**, using WebP for its wide
browser compatibility (AVIF is not used: slower encoding and weaker browser support).

The benchmark gate (`tests/bench/`) is what proves that claim. As built, it did **not**
faithfully measure "improvement over default MediaWiki" in several ways:

1. **Strict dominance is brittle.** A win required `candQuality >= baselineQuality` to the
   exact decimal, so a sub-perceptual SSIMULACRA2 wobble (a few tenths) at a clearly
   smaller size was logged as INCOMPARABLE. The metric's own noise is larger than the
   differences being gated on.
2. **The GD baseline did not match MediaWiki's real GD path.** A faithful baseline must
   reproduce what a default install actually produces — MediaWiki's GD scaler
   (`BitmapHandler::transformGd`) uses `imagecopyresampled()`.
3. **The baseline framing was inverted.** AGENTS called ImageMagick the "primary" baseline,
   but a default MediaWiki install has `$wgUseImageMagick = false` — with no Imagick
   extension or custom convert, it falls through to **GD**. GD is the *literal* default;
   ImageMagick is the *typical configured* path.
4. **The corpus measured the wrong things.** Targets were 120/250/640; MediaWiki 1.46
   standardised article-content thumbnails to **180 / 250 / 400** (tasks T424909 / T376152;
   250 is the default unsized thumb). Sources were 1–2K, so the gate measured ~5x
   downscales while real workloads (e.g. 4K screenshots and concept art) are ~10x. Wikimedia
   production's hard-limited set (T414805: 20/40/.../3840) is deliberately *not* the target —
   Thumbro tracks the MediaWiki **default**, not WMF production.

## Decision

### 1. Pass criterion — dominance with a quality noise-tolerance

Pass = a real size win with no *visible* quality regression vs the binding baseline:

- PASS if `candBytes < baseBytes AND candQuality >= baseQuality - T`, or
  `candQuality > baseQuality + T AND candBytes <= baseBytes`.
- `T` is a named, overridable `GateThresholds` value, default **3** SSIMULACRA2 points
  (comfortably sub-band; bands are 20 wide). It is a heuristic; the exact value barely
  affects verdicts — genuine wins and genuine regressions fall well outside so narrow a
  band — so revisit only if a real borderline case appears.

The tolerance applies uniformly at every width: differences within the metric's noise band
are ties, so a smaller file is not denied a win by jitter, and the baseline cannot win on
jitter either. The hard quality floor of 50 remains a safety cap in the representative tier;
on the **stress** tier it is advisory (flagged, not failed), because those fixtures are
synthetic pathologies — never served — on which SSIMULACRA2 is unreliable (see the stress
note in `tests/bench/README.md`). The stress tier's hard caps are wall time and peak RSS.

**Performance is a safety cap, not a dominance axis.** Generation cost is paid once and
cached while the size/quality win is served on every view (the trade-off principle), so wall
time and peak RSS are kept out of the dominance comparison — a smaller, higher-quality
thumbnail is never denied for being slower or heavier to produce. They are bounded two ways
instead: a **hard cap** (exceeding the wall-time or peak-RSS ceiling is an outright FAIL, so
generation cannot choke the server) and a **soft budget** (a material regression versus the
baseline is flagged for a recorded decision). The concrete ceilings and budgets are named
values in `GateThresholds`.

Rejected alternatives: **page-weight-first** (allow a quality give-back for big size wins)
— authorises shipping thumbnails visibly worse than MediaWiki, contradicting the product
claim; **quality-first / size optional** — would "pass" parity with MediaWiki, adding
dependency and encode cost for no win.

### 2. Baselines — GD (literal default) and ImageMagick (typical configured)

- `GdBaseline` reproduces MediaWiki's GD path — `imagecopyresampled()`, as
  `BitmapHandler::transformGd` does — so the baseline reflects a real default install.
- **ImageMagick is the binding pass/fail bar** — the demanding, realistic baseline where
  "improvement" is actually contested.
- **GD is shown and acts as a never-regress floor**, not a dominance target. Rejected
  "must dominate both": GD writes JPEG q80, which at large sizes is *smaller* than WebP q90,
  so requiring GD dominance would force lowering quality to undercut GD's bytes — exactly
  the brittleness this gate removes. As a floor, GD flags a breach only when it genuinely
  dominates the candidate — smaller at no-worse quality (within the tolerance). Because GD is
  generally the lower-quality scaler this is uncommon, but it is a real signal, not noise: it
  means the candidate failed to beat even the literal default — e.g. a profile whose WebP runs
  larger than GD's JPEG at small, high-quality sizes — which is exactly what a floor should
  catch.

### 3. Corpus — realistically-large, content-diverse, freely-licensed

- **Widths:** representative tier measures at **180 / 250 / 400** (MediaWiki 1.46 content
  sizes). Drop 120/640. Stress tier widths unchanged.
- **Content types (the axis that actually changes encoder behaviour):** photographic,
  application-UI/text, flat graphic, logo+transparency. Reducing to a single image was
  rejected — it would blind the gate to content-type-specific failures (e.g. a flat graphic
  that compresses worse as WebP than the baseline does in its native format).
- **Source size follows realistic distribution per type:** photographic content
  (photos, concept art, AAA-game screenshots — all photographic-grade) at **4K-class**, to
  match real large-downscale workloads and to exercise the perf/RSS caps; logos / flat
  graphics / UI at native sizes (a 4K logo is unrealistic and would distort that case). A
  *spread* of source dimensions is unnecessary — downscale ratio is a second-order effect on
  optimal quality; one realistic source per content type suffices.
- **Licensing:** fixtures are freely-licensed stand-ins (CC0 / public-domain / CC-BY) that
  share the *characteristics* of the workload — never copyrighted game assets.

### 4. Documentation and framing

Correct AGENTS / `tests/bench/README.md`: GD is the literal MediaWiki default, ImageMagick
the typical configured one; both are baselines for "improvement over MediaWiki." Document
the tolerance, the baseline roles, and the width rationale.

### 5. Record practice

Adopt MADR-lite ADRs (`docs/adr/`). `docs/encoding/` (per-MIME living docs) keeps the narrower role
(per-MIME benchmark option-set results); ADRs capture system/methodology rationale and
cross-link to it. This ADR is the first.

## Consequences

- Gate verdicts become faithful: GD reflects MediaWiki's real GD path, with the default
  framing corrected. The improvement story holds across the install spectrum: a proven win
  vs ImageMagick, a guaranteed no-regression-and-usually-large win vs GD.
- The quality reference is vips-generated and Thumbro is vips — a theoretical home-field
  edge. Checked and accepted: ImageMagick (a different scaler) is not penalised against the
  vips reference, so non-vips contenders are scored fairly. Noted, not redesigned around.

### Follow-ups (explicitly NOT in this change's scope)

- **Re-validate every per-MIME option** against the new gate + corpus + widths. *Done:* JPEG
  re-tuned Q90 → Q80; PNG re-validated and kept. See `docs/encoding/`.
- **Regenerate GD numbers** and revisit prior GD claims (now migrated to `docs/encoding/`),
  since earlier GD results came from a contender that did not match MediaWiki's GD path. *Done.*
- **Pre-existing issues:** `flat-graphic.png` losing to ImageMagick — *investigated and
  accepted* as a fundamental format limit (PNG palette beats WebP on 2-colour content; sub-KB,
  visually equivalent); see `docs/encoding/image-png.md`. `anim-transparent.gif` breaching the
  quality floor — *investigated and resolved*: a measurement artifact (84px + transparent +
  48-frame animation; ImageMagick scores it ~50 too, and the thumbnail is visually fine —
  smoother than the blocky scoring reference). Quality is now advisory on the stress tier;
  the fixture's real concern (no size/RSS blow-up) passes with room to spare.
