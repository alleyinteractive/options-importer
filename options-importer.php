<?php
/**
 * Plugin Name: WP Options Importer
 * Plugin URI: https://github.com/alleyinteractive/options-importer
 * Description: Export and import WordPress Options
 * Version: 7
 * Author: Matthew Boynes
 * Author URI: https://www.alley.com/
 *
 * @package Options_Importer
 */

if ( ! class_exists( 'WP_Options_Importer' ) ) {
	require_once dirname( __FILE__ ) . '/class-wp-options-importer.php';
}

/**
 * Creates and setups up the main singleton class instance.
 */
function options_import_setup_main_class() {
	// Create and the singleton instance.
	WP_Options_Importer::instance()->setup();

	return false;
}
add_filter( 'plugins_loaded', 'options_import_setup_main_class' );
