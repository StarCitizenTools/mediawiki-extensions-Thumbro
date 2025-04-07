<div align="center">
👍🖼️😎
<h1>Thumbro</h1>
<blockquote><p><i>Can we get Thumbor for the wiki?<br>
We have Thumbor at home.<br>
Thumbor at home:
</i></p></blockquote>
</div>

**Thumbro** is an in-development MediaWiki extension used to improve and expand thumbnailing in MediaWiki. It is unstable for production use. It is forked from [Extension:VipsScaler](https://www.mediawiki.org/wiki/Extension:VipsScaler). Currently, it only supports [libvips](https://www.libvips.org).

## Features
- Use libvips to render thumbnails instead of ImageMagick and GD
- Allow custom output options for libvips
- Render WebP thumbnails by default for gif (animated too!), jpeg, png, webp
- Allow adding `<source>` element to the image using the `ThumbroBeforeProduceHtml` hook
- Add a hidden anchor element to allow web crawler to crawl the original resolution image ([T54647](https://phabricator.wikimedia.org/T54647))

## Installation
1. Install [libvips](https://www.libvips.org/install.html). For Debian-based systems:
```console
apt-get install libvips-tools
```
2. [Download](https://github.com/StarCitizenTools/mediawiki-extensions-Thumbro/archive/main.zip) and place the file(s) in a directory called `Thumbro` in your `extensions/` folder.
3. Add the following code at the bottom of your LocalSettings.php and **after all other extensions**:
```php
wfLoadExtension( 'Thumbro' );
```
3. **✔️Done** - Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

## Configurations
> ℹ️ **Thumbro works out of the box without any configurations.**

`$wgThumbroLibraries` is used to define the libraries used in Thumbro.

Key | Description 
:--- | :--- 
`command` | Executable used by Thumbro to do image transformation

Default:
```php
$wgThumbroLibraries => [
	"value" => [
		"libvips" => [
			"command": "/usr/bin/vipsthumbnail"
		]
	]
];
```
`$wgThumbroOptions` is used to define the parameters of the thumbnail generation.

Key | Description 
:--- | :--- 
`enabled` | Enable or disable Thumbro for the selected file type
`library` | Corresponds to `$wgThumbroLibraries`, currently only `libvips` is supported
`inputOptions` | Corresponds to the input/load options in [`VipsForeignSave`](https://www.libvips.org/API/current/VipsForeignSave.html)
`outputOptions` | Corresponds to the output/save options in [`VipsForeignSave`](https://www.libvips.org/API/current/VipsForeignSave.html)

Default:
```php
$wgThumbroOptions = [
	'value' => [
		'image/gif' => [
			'enabled' => true,
			'library' => 'libvips',
			'inputOptions' => [
				'n' => '-1'
			]
		],
		'image/jpeg' => [
			'enabled' => true,
			'library' => 'libvips',
			'inputOptions' => [],
			'outputOptions' => [
				'strip': 'true',
				'Q': '80'
			]
		],
		'image/png': => [
			'enabled' => true,
			'library' => 'libvips',
			'inputOptions' => [],
			'outputOptions' => [
				'strip' => 'true',
				'filter' => 'VIPS_FOREIGN_PNG_FILTER_ALL'
			]
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
	]
];
```
### Testing options
Name | Description | Values | Default
:--- | :--- | :--- | :---
`$wgThumbroEnabled` | Set to `false` to disable Thumbro throughout the wiki excluding the Special:ThumbroTest page | `true` - enable; `false` - disable | `true`
`$wgThumbroExposeTestPage` | Enable Special:ThumbroTest on the wiki | `true` - enable; `false` - disable | `false`
`$wgThumbroTestExpiry` | Control the cache age for the test image streamed to Special:ThumbroTest | integer | `3600`

## Testing Thumbro thumbnails
Thumbro comes with a special page that can be used to compare thumbnails before and after Thumbro.
First you have to enable the page with this config:
```php
// Enable the Special:ThumbroTest page
$wgThumbroExposeTestPage = true;
```

To make sure the before thumbnail is untouched by Thumbro, you can either disable Thumbro site-wide:
```php
// Disable Thumbro site-wide
$wgThumbroEnabled = false;
```

Or disable the output file format you wanted to test under `$wgThumbroOptions`.

## Requirements
* [MediaWiki](https://www.mediawiki.org) 1.43.0 or later
* [libvips](https://www.libvips.org) 8.14 or later (older versions might work but they are untested)
* [Imagick](https://github.com/Imagick/imagick) - Optional, used to generate detailed comparison statistics on Special:ThumbroTest
