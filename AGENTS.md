# AGENTS.md

## Overview

Thumbro is a MediaWiki extension (requires MW 1.43+) that improves and expands thumbnail generation. It registers media handlers (currently libvips via `vipsthumbnail`) that hook into MediaWiki's `BitmapHandlerTransform` pipeline to produce higher-quality thumbnails per MIME type. PHP drives transform shell-out and hook integration; a `Special:ThumbroTest` page (with a small jQuery + CSS comparison module) lets sysops compare default vs Thumbro output.

## Verification

Run only what's relevant to the files you changed.

| Files changed | Command |
| --- | --- |
| `*.php` | `composer preflight` (lint, style, Phan, and PHPUnit) |
| `*.js` | `npm run lint:js` |
| `*.less`, `*.css` | `npm run lint:styles` |
| `i18n/` | `npm run lint:i18n` |

Auto-fix commands: `composer fix` (PHP), `npm run lint:fix:js` (JS), `npm run lint:fix:styles` (styles).

**Preflight**: Run `npm run preflight` to execute all Node-based lints in one command. Run `composer preflight` from within a MediaWiki installation to execute all PHP lints, style checks, Phan static analysis, and PHPUnit tests.

**Always run the relevant checks before committing.** Read the full output — PHPCS warnings must be fixed, not just errors. The command exits 0 even with warnings, so do not treat exit code alone as a pass.

### Dev environment

This project's standard dev environment is the MediaWiki Docker setup defined in the parent `mediawiki/` directory. The user may be using a different environment. Ask the user for their dev environment URL and how to run commands if not already known.

To run composer commands in the standard Docker environment:

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/Thumbro && composer preflight"
```

Note: the `vipsthumbnail` binary (libvips) must be available in the environment for runtime thumbnail generation, but is not required for lint/test/Phan.

### PHPUnit

PHPUnit tests live under `tests/phpunit/` (auto-discovered via `TestAutoloadNamespaces` in `extension.json`) and require a full MediaWiki installation to run. From the MediaWiki core root:

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w && composer phpunit -- extensions/Thumbro/tests/phpunit"
```

Integration tests (those needing MediaWiki services, e.g. media-handler tests that exercise `normaliseParams`) go under `tests/phpunit/integration/`; pure unit tests go under `tests/phpunit/unit/`.

### Phan

Phan requires a full MediaWiki installation at `../../` for type resolution.

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/Thumbro && composer phan"
```

## Coding conventions

### PHP

- All files start with `declare( strict_types=1 );`
- Use native PHP types (properties, parameters, return values); use PHPDoc only for collection types like `string[]`
- Always use MediaWiki-namespaced imports (`use MediaWiki\Title\Title;`), never legacy shims (`use Title;`)

### JavaScript

- Vanilla JS / jQuery via MediaWiki's ResourceLoader
- The `jquery.ucompare` module is a jQuery plugin — keep it self-contained and namespaced under `$.fn.ucompare`

### LESS/CSS

- Styles live alongside their module under `modules/<module>/`

### extension.json

`extension.json` is the source of truth for how the extension is wired — ResourceLoader modules, hooks, config variables, and dependencies are all declared here.

- When adding or removing files under `modules/`, update the corresponding `scripts` or `styles` list in `extension.json`
- Config variables are declared under `config` in `extension.json` (prefixed `wgThumbro`); the `thumbro` config registry resolves them
- Hook handlers are wired through `HookHandlers` → `Hooks`; new hook subscriptions go through the `main` handler unless there's a reason to split

### Media handlers

- New thumbnail backends go under `includes/Libraries/` (e.g. `Libvips`, `Libwebp`).
- The backend for each MIME type is chosen by the `library` field in its
  `wgThumbroOptions["<mime>"]` block and dispatched in
  `MediaWikiHooks::onBitmapHandlerTransform` (`library` → backend class).
- Backend binaries are configured under `wgThumbroLibraries` (`libvips` →
  `vipsthumbnail`, `libwebp` → `gif2webp`).
- **GIF strategy:** `image/gif` selects `libwebp`. The `Libwebp` backend owns the
  runtime policy. Transparent animated GIFs under `$wgThumbroMaxAnimatedArea` (with
  gif2webp present) are encoded with gif2webp. Opaque animated GIFs — and the
  gif2webp-unavailable fallback — delegate to `Libvips` as animated WebP (all frames
  preserved). Static GIFs and animations over the threshold delegate to `Libvips` as a
  static first frame.

### Benchmarking handlers (required)

Every handler must demonstrably improve on default MediaWiki on the axes Thumbro
optimises: **file size** and **perceptual quality** (SSIMULACRA2), within hard
**performance** caps (wall time + peak RSS). MediaWiki's default scaler is **GD**
(`$wgUseImageMagick = false`); **ImageMagick** is the typical configured path. The gate
treats **ImageMagick as the binding pass/fail baseline** and **GD as a never-regress
floor** (see `docs/adr/0001-image-benchmark-gate.md`).

- Harness: `php tests/bench/benchmark.php` (see `tests/bench/README.md` for dev deps + setup).
- Quality metric: **SSIMULACRA2** (bands: ≥90 visually lossless, ≥70 high, ≥50 medium).
- Acceptance rule (full detail in `tests/bench/README.md`):
  **dominance vs ImageMagick** — smaller at no-worse quality, or higher quality at
  no-larger size — where a SSIMULACRA2 gap within the noise-tolerance counts as a tie.
  GD must not regress (floor). Performance is a safety cap, not a dominance axis: hard
  caps (quality ≥ 50, wall-time ≤ 3 s static / 10 s animated, peak RSS ≤ 512 MB) FAIL a
  candidate outright, and soft budgets flag regressions. A genuine trade-off is
  **INCOMPARABLE** and needs a recorded decision.
- **PR requirement:** any new or changed handler/option set must include harness
  results and pass the gate; INCOMPARABLE results need an explicit recorded decision.
- **Trade-off principle:** generation cost is paid once and cached; the size/quality
  win is paid on every view, forever. A memory or time regression that stays within the
  hard caps is therefore acceptable when it buys a real size/quality gain — prefer the
  smaller, higher-quality thumbnail even when it costs more to produce.
- **Recorded decisions:** option-set choices and their trade-offs are logged in
  `tests/bench/DECISIONS.md` — one entry per change. Every INCOMPARABLE verdict and every
  accepted memory/time regression must be recorded there, with the reasoning.

### Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/) (e.g. `fix:`, `feat:`, `refactor:`)
- Use `ci:` or `chore:` for non-user-facing changes (tooling, config, dependencies)
- The benchmark harness (`tests/bench/`) is non-user-facing dev tooling: scope its commits
  with `test:` or `chore:` (e.g. `test(bench):`), never `feat:`/`fix:`. release-please groups
  the changelog by commit **type** (not scope), so the type — not the `bench` scope — is what
  keeps these out of the release notes. The hidden types are set explicitly in
  `release-please-config.json` (`changelog-sections`): `test`, `chore`, `docs`, `refactor`,
  `style`, `build`, `ci` are hidden; `feat`, `fix`, `perf`, `revert` show.

### i18n

- Any user-facing string needs a message key in `i18n/en.json`
- Every key in `en.json` must also have a documentation entry in `i18n/qqq.json`
