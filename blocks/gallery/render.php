<?php
/**
 * Server-side rendering of the slashphoto/gallery block.
 *
 * @package SlashPhoto
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

// Markup is escaped in the templates.
echo \SlashPhoto\Plugin::instance()->gallery_block()->render( $attributes, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
