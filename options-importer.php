<?php
/**
 * Plugin Name: WP Options Importer
 * Plugin URI: https://github.com/alleyinteractive/options-importer
 * Description: Export and import WordPress Options
 * Version: 7
 * Author: Matthew Boynes
 * Author URI: http://www.alleyinteractive.com/
 *
 * @package Options_Importer
 */

if ( ! class_exists( 'WP_Options_Importer' ) ) {
	require_once './class-wp-options-importer.php';

	// Create the singleton instance.
	WP_Options_Importer::instance();
}
