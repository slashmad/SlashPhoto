<?php
/**
 * Editor script dependencies (hand-written; the block has no build step).
 *
 * @package SlashPhoto
 */

defined( 'ABSPATH' ) || exit;

return [
	'dependencies' => [ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-core-data', 'wp-data', 'wp-element', 'wp-i18n', 'wp-server-side-render' ],
	'version'      => (string) filemtime( __DIR__ . '/index.js' ),
];
