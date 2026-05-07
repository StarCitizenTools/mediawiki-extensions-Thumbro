<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Imagick is an optional PECL extension; provide stubs so Phan can type-check
// Imagick code paths that are gated on extension_loaded( 'imagick' ).
$cfg['autoload_internal_extension_signatures']['imagick'] = __DIR__ . '/stubs/imagick.phan_php';

return $cfg;
