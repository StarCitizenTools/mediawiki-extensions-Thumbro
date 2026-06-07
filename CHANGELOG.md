# Changelog

## [2.0.0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/compare/v1.2.1...v2.0.0) (2026-06-07)


### ⚠ BREAKING CHANGES

* **config:** $wgThumbroLibraries entries are now keyed by library with binaries mapped by name (e.g. libwebp.gif2webp, libwebp.cwebp), replacing the flat per-binary `command` shape. Wikis that set $wgThumbroLibraries explicitly must migrate; the default needs no action.
* the wgThumbroOptions schema changed. Per-MIME blocks now use `resize: { options }` + `encode: [ { encoder, when?, options } ]` instead of `library` / `inputOptions` / `outputOptions`. Wikis that override wgThumbroOptions in LocalSettings.php must migrate to the new shape; an old-shape block has no `encode` key, so Thumbro will skip that MIME (falling back to core) until updated.

### Features

* add libwebp backend for transparent animated GIFs ([69963c3](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/69963c3ce6958d1009d25db53f90a774c0491e65))
* generalise ShellCommand for the gif2webp argument style ([177d34b](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/177d34b994e091c62ef9dbc4b11447534a7cddbe))
* preserve animation when thumbnailing animated WebP ([be93555](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/be93555c963f0cf119ad2f1a39fc4a9c6e8216ab))
* select thumbnail backends per-MIME via the config library field ([ef59c0a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/ef59c0a6fb2296b17d8f2aac24e50fc7bb52dcc3))
* tune PNG thumbnails to near-lossless WebP ([98e19eb](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/98e19eb222bc5a8991a4e6065e35de018cad6de6))


### Bug Fixes

* **bench:** extract animated WebP frames with libvips, not ImageMagick ([8ef8444](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/8ef8444292c627631ebae849186c11ff17ea81b8))
* keep external-link wrapping of link= thumbnails intact ([a697b98](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/a697b9870d9f02b5757b96142a81f95ee49b6f21))
* locate vipsheader next to the configured vips binary ([7c8e8fc](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/7c8e8fc60ba3bbe55ccddbe451548113ad227292))
* route Special:ThumbroTest through the backend dispatcher ([34e1917](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/34e1917b4686a408697cf1edab360fe578219192))


### Performance Improvements

* encode static WebP thumbnails with cwebp ([8c66752](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/8c66752f22f22fa6287b15307a5a0da8b210ba8a))
* re-tune JPEG thumbnail quality to Q80 on the redesigned gate ([c6ad345](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/c6ad3456c91fc1bdf2fb68c6b0d5a85bc9e4b53a))
* tune JPEG thumbnail WebP output for photographic content ([18f8939](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/18f8939aabcd88d9236bf1ebbf7f593dc5d589a0))


### Code Refactoring

* **config:** key ThumbroLibraries by library, nest binaries ([4f618ee](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/4f618eec77bf2f6eef99e65824f6f7e679745520))
* resize-then-encode pipeline replaces backend dispatch ([e98de58](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/e98de58246e79b736d090623ef5b31d4daa77810))

## [1.2.1](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/compare/v1.2.0...v1.2.1) (2026-06-05)


### Bug Fixes

* correct the thumbnails container selector from ID to class ([bdd59c7](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/bdd59c7c57c77a4f8198e851100a6886475640a0))
* serve original file for non-upscalable deferred transforms ([470522a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/470522a29d89b83c8a27419e8e663e2234d46967)), closes [#51](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/51)

## [1.2.0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/compare/v1.1.1...v1.2.0) (2026-05-07)


### Features

* add chinese i18n files ([bb2bd1c](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/bb2bd1ccbeda933b1929caf9a2c64694d78360b9))


### Bug Fixes

* add error handling for failed image requests in SpecialThumbroTest ([7773f65](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/7773f659582b1fec424409ac2e4fa3f4ceed7131))
* avoid removed ParserEnableLegacyMediaDOM constant on MW 1.45+ ([cf29624](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/cf29624806325b8868de4b55dacf08bd6d26d61e)), closes [#38](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/issues/38)
* resolve phpcs and phan failures ([09a4677](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/09a46770919430e89793e602f35db7a867e4733b))
* use constant() lookup for version-gated Imagick metric ([a0b57a0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/a0b57a08fac3f7be15564ac5b64c3104b4cce51e))

## [1.1.1](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/compare/v1.1.0...v1.1.1) (2025-10-16)


### Bug Fixes

* make sure that Thumbro replaces ThumbnailImage ([617e5d9](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/617e5d9d7c1ae1e599a7515269d4745e7b130c4d))

## [1.1.0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/compare/v1.0.0...v1.1.0) (2025-09-22)


### Features

* add thumbnail support for animated WebP ([5f97f3d](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/5f97f3d43aaccba447494741b36343032c4d033d))

## [1.0.0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/compare/v0.1.0...v1.0.0) (2025-04-07)


### ⚠ BREAKING CHANGES

* update MediaWiki version requirement to 1.43.0

### Features

* add basic setup for PNGs ([2cc4d62](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/2cc4d624a38df314b4a09d94430cdbbf71096e36))
* add basic stat comparison to Special:ThumbroTest ([85f2010](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/85f2010b5d30b381505d42690c40f755dcb8bb54))
* add comparison image slider with original ([f1996c4](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/f1996c40469f7d9da8ecbc0746764925588d8fe2))
* add config wgThumbroEnabled to disable Thumbro ([9c0f4dd](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/9c0f4ddc9e90fa502dfc954c53af7d195219da58))
* add custom ThumbnailImage class and integrate Extension:PictureHtmlSupport ([210cff0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/210cff0bd8bd9ac57afc1ce94da589c46a9f5066))
* add ImageMagick and GD version to Special:Version if loaded ([b0c04c3](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/b0c04c3ee5677b6e2a27f449b606d8369485a07a))
* convert animated GIFs into WebP ([4fd3a88](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/4fd3a88c20874c8859a1a37abe576a4d0d59c3f3))
* do not do anything if shell is disabled ([4098370](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/40983707dc697d76e2c3561404b89af7f7c28f34))
* drop unused remote host config ([f354da8](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/f354da8e7176f8caecf84bb37cedb1ee8fe5c93d))
* enable VIPS for jpg by default ([e69ad50](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/e69ad50f64ab82b2df9fecef46b2c515422348a3))
* expose output options for VIPS in config ([67dd869](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/67dd86980f208f7adfa37a58bd9e0fcff4575705))
* minor updates to the UI in Special:ThumbroTest ([4f1ced4](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/4f1ced4869f4f71de21f8b8138e4b4bf3cdec3b0))
* output message when VIPS did not scale the file ([123983f](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/123983f4a8b879c8926026e4c03ca40c774708f7))
* rename and restructure extension into Thumbro ([82c905d](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/82c905d6c46235f188212801490c1386bbc83aa9))
* render WebP thumbnails by default for JPG, PNG, WebP ([aeabb38](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/aeabb38c93833c5fa351500522fdd00a7c3bb0a7))
* restrict Special:ThumbroTest to sysop by default ([eb2414b](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/eb2414b0dedebbafb170cbf7cd52ffaba004eb25))
* simplify thumbnail generation ([fc8f745](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/fc8f7453f6414748795cc654ed12b1a065212b01))
* use Imagick compare to generate comparison metrics ([ab0e0a0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/ab0e0a089b23a924498064f0d3734af08258d14b))
* use vipsthumbnail directly ([1cb366a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/1cb366a6f55940bafc1ee2ae8f663bb10345a259))


### Bug Fixes

* comparison image in Thumbro should be the format defined in the handler ([c492c2a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/c492c2a78709aef2f4cd4c540bee54842d866ef7))
* fix incorret Thumbro handler on test page ([15a1547](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/15a1547126bce0579f2e39077b53f24efb12d51c))
* fix typo in extension description ([627a96c](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/627a96c2c5922dc05dc193b57d2f0f7d3483e633))
* input in validateFileInput can be null ([5963ab7](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/5963ab70a44a9fddb4c33e51894d72e47f345633))
* makeOutputOptions should be a static method ([d38774a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/d38774af425f976ab4243328e7049415f125f002))
* set original image to fixed dimensions ([d87413c](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/d87413ca269c93431188535f4f7744a1f3f01fd2))
* Special:ThumbroTest should respect inputOptions and outputOptions ([c64bf88](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/c64bf88a6faa53acf385afd45cd4c4256954b2a6))
* Special:VipsTest should honor wgVipsConfig ([7aba826](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/7aba826f38eb7152f06f5693a8e6df295375455e))
* undefined index for unspecified image format in options ([1d553a7](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/1d553a7a03cf435af781097f0578723ecacaf507))
* use init function to add handler so comparison works on test page ([4ed6ca7](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/4ed6ca7793a04f03df723fc2710da3d15a8c6536))
* use output format for MIME match ([dd92b00](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/dd92b00e73224782defbc16e24ebfc3153e05ddc))


### Miscellaneous Chores

* add dependabot configuration ([2e09fe4](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/2e09fe42bc8ebc67044f71c00f9b4be957ea4abf))
* add GitHub Actions workflow for linting code ([d7552c0](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/d7552c001c1251e8837d1e7a901c6095b35159a8))
* add release-please configurations ([9e20537](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/9e20537dcbbfb42543d5602688ae6dbd49c0a864))
* add release-please to CI ([086e32a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/086e32a6598565ad78edb30b59724f97c488a450))
* bump to 0.0.3 ([bb88e78](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/bb88e784eab897b0a95e80285f117be77d2890e9))
* bump to 0.0.4 ([d7cc55b](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/d7cc55b0952d6a01a5ada1a6bc4b58330c7ef02c))
* bump to 0.0.5 ([7f4fa0f](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/7f4fa0f5e273a34d730f799809ba52003cc0574b))
* bump to 0.1.0 ([facba23](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/facba23e4e5e873c5a1a1b3b6e44bd4efff7e055))
* make compose.json ready for Packagist ([2d35b61](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/2d35b6142ccf6faf4799d011c5786439411f18e2))
* update MediaWiki version requirement to 1.43.0 ([17ac08a](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/commit/17ac08af046abd7864cd0c55b6ccfb6c3d3c18ee))
