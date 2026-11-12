<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Imagick is an optional PECL extension; provide stubs so Phan can type-check
// Imagick code paths that are gated on extension_loaded( 'imagick' ).
$cfg['autoload_internal_extension_signatures']['imagick'] = __DIR__ . '/stubs/imagick.phan_php';

// Thumbro supports several MediaWiki versions, so some code calls a core method that only
// exists on some of them, guarded by method_exists() and a PhanUndeclaredMethod suppression.
// Phan only sees one core version, so on the others that suppression looks unused.
// Don't report it.
$cfg['plugin_config']['unused_suppression_ignore_list'] = [ 'PhanUndeclaredMethod' ];

return $cfg;
