<?php
/**
 * Uninstall routine.
 *
 * Deliberately keeps all galleries, images and orders: photographers'
 * content and sales records must survive removing the plugin. An explicit,
 * opt-in "delete all data" setting will be added together with the settings
 * screen.
 *
 * @package SlashPhoto
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
