'use strict';

const { defineConfig, globalIgnores } = require( 'eslint/config' );
const wikimedia = require( 'eslint-config-wikimedia/flat' );

module.exports = defineConfig( [
	globalIgnores( [
		'docs/',
		'modules/jquery.ucompare/',
		'vendor/'
	] ),

	...wikimedia( 'client/es6', 'mediawiki', 'jquery' ),
	{
		rules: {
			'no-jquery/variable-pattern': 'warn',
			'no-jquery/no-global-selector': 'warn'
		}
	}
] );
