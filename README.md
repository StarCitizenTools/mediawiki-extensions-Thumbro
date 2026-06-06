<div align="center">
👍🖼️😎
<h1>Thumbro</h1>
<blockquote><p><i>Can we get Thumbor for the wiki?<br>
We have Thumbor at home.<br>
Thumbor at home:
</i></p></blockquote>
</div>

**Thumbro** improves and expands thumbnail generation in MediaWiki. Instead of relying on a single tool, it automatically picks the most suitable image library for each file format — so every thumbnail comes out at the best quality and smallest size, with no manual tuning and room to add better libraries over time. Forked from [Extension:VipsScaler](https://www.mediawiki.org/wiki/Extension:VipsScaler).

## Features
- Routes each format to the best-suited image library automatically (currently [libvips](https://www.libvips.org) and [libwebp](https://developers.google.com/speed/webp/docs/gif2webp)), instead of MediaWiki's default ImageMagick and GD
- Produces higher-quality, smaller thumbnails — including for animated images
- Renders WebP thumbnails by default for GIF, JPEG, PNG, and WebP (including animated)
- Lets you fine-tune encoding per format with custom load and save options
- Extends thumbnail support to more formats, such as animated WebP
- Adds a `<source>` element to images via the `ThumbroBeforeProduceHtml` hook
- Adds a hidden anchor so web crawlers can reach the original-resolution image ([T54647](https://phabricator.wikimedia.org/T54647))

## Installation
1. Install the image libraries Thumbro uses. [libvips](https://www.libvips.org/install.html) is required; [libwebp](https://developers.google.com/speed/webp/docs/gif2webp) is recommended for crisp, compact animated-GIF thumbnails. On Debian-based systems:
```console
apt-get install libvips-tools webp
```
2. [Download](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/archive/main.zip) the extension and place the files in a directory called `Thumbro` in your `extensions/` folder.
3. Add the following to the bottom of your `LocalSettings.php`, **after all other extensions**:
```php
wfLoadExtension( 'Thumbro' );
```
4. **✔️ Done** — visit Special:Version on your wiki to confirm the extension is installed.

## Configuration
> ℹ️ **Thumbro works out of the box — no configuration required.**

### `$wgThumbroLibraries`
The image libraries Thumbro can use, and how to run each.

Key | Description
:--- | :---
`command` | Path to the library's executable
`flags` | Optional encoder flags for the library, passed straight through (libwebp → [`gif2webp`](https://developers.google.com/speed/webp/docs/gif2webp), e.g. `mixed`, `q`, `m`)

Default:
```php
$wgThumbroLibraries = [
	'libvips' => [ 'command' => '/usr/bin/vipsthumbnail' ],
	'libwebp' => [
		'command' => '/usr/bin/gif2webp',
		'flags' => [ 'mixed' => '', 'q' => '80', 'm' => '4' ]
	],
];
```

### `$wgThumbroOptions`
Controls how each file type is thumbnailed: which library handles it, and the options passed to that library. The defaults are tuned per format, so most wikis never need to change this.

Key | Description
:--- | :---
`enabled` | Turn Thumbro on or off for this file type
`library` | Which library handles this type (a key from `$wgThumbroLibraries`)
`inputOptions` | Options for loading and resizing the source image
`outputOptions` | WebP save options ([`VipsForeignSave`](https://www.libvips.org/API/current/VipsForeignSave.html)), e.g. `Q`, `strip`, `smart_subsample`. Set per file type; if omitted, the `image/webp` block's options apply. (gif2webp's encoder flags live on the `libwebp` library, not here.)

Default:
```php
$wgThumbroOptions = [
	'image/gif' => [
		'enabled' => true,
		'library' => 'libwebp',
		'inputOptions' => [
			'n' => '-1'
		]
	],
	'image/jpeg' => [
		'enabled' => true,
		'library' => 'libvips',
		'inputOptions' => []
	],
	'image/png' => [
		'enabled' => true,
		'library' => 'libvips',
		'inputOptions' => []
	],
	'image/webp' => [
		'enabled' => true,
		'library' => 'libvips',
		'inputOptions' => [],
		'outputOptions' => [
			'strip' => 'true',
			'Q' => '90',
			'smart_subsample' => 'true'
		]
	]
];
```

### Other options
Name | Description | Values | Default
:--- | :--- | :--- | :---
`$wgThumbroMaxAnimatedArea` | Largest animation Thumbro will fully re-encode, measured as width × height × frames. Bigger animations are rendered as a single static frame to keep thumbnailing fast. | integer | `25000000`
`$wgThumbroEnabled` | Disable Thumbro across the wiki (the Special:ThumbroTest page still works) | `true` / `false` | `true`
`$wgThumbroExposeTestPage` | Enable the Special:ThumbroTest comparison page | `true` / `false` | `false`
`$wgThumbroTestExpiry` | Cache lifetime, in seconds, for images streamed to Special:ThumbroTest | integer | `3600`

## Comparing thumbnails
Thumbro ships a special page for comparing thumbnails before and after Thumbro. Enable it with:
```php
// Enable the Special:ThumbroTest page
$wgThumbroExposeTestPage = true;
```

To keep the "before" thumbnail untouched by Thumbro, either disable Thumbro site-wide:
```php
// Disable Thumbro site-wide
$wgThumbroEnabled = false;
```

…or disable the specific file format you want to test under `$wgThumbroOptions`.

## Requirements
* [MediaWiki](https://www.mediawiki.org) 1.43.0 or later
* **[libvips](https://www.libvips.org)** (8.14 or later; older versions may work but are untested) — required. Drives core thumbnail generation via the `vipsthumbnail` command.
* **[libwebp](https://developers.google.com/speed/webp/docs/gif2webp)** (the `gif2webp` tool; Debian/Ubuntu `webp` package) — recommended. Encodes animated GIFs to compact animated WebP, far smaller than libvips for transparent animations. Without it, animated GIFs fall back to libvips automatically.
* [Imagick](https://github.com/Imagick/imagick) — optional. Powers the detailed comparison statistics on Special:ThumbroTest.
