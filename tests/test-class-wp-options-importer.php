<?php
/**
 * Tests for the main import class file.
 */

class WP_Options_Importer_Test extends WP_UnitTestCase {

	/**
	 * Tests getting the options to export.
	 */
	function test_get_export_options() {
		$export_options = WP_Options_Importer::instance()->get_export_options();

		$this->assertNotEmpty( $export_options );

		// Perform check of an options value.
		$this->assertEquals( serialize( array( 'options-importer/options-importer.php' ) ), $export_options['active_plugins'] );

		// Set a custom option.
		$option_value = rand_str();
		update_option( 'custom_option', $option_value );

		// Check custom value is in export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertEquals( $option_value, $export_options['custom_option'] );
	}

	/**
	 * Tests getting the options to export with deny list filter.
	 */
	function test_get_export_options_denylist() {
		// Set a custom option.
		$option_value = rand_str();
		update_option( 'custom_option', $option_value );

		// Check custom value is in export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertEquals( $option_value, $export_options['custom_option'] );

		// Add the value to the deny list.
		add_filter( 'options_export_denylist', function() { return array( 'custom_option' ); } );

		// Ensure the value does not exist in the export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertFalse( isset( $export_options['custom_option'] ) );

		// Test legacy filer name.
		add_filter( 'options_export_denylist', '__return_empty_array' );
		add_filter( 'options_export_blacklist', function() { return array( 'custom_option' ); } );

		// Ensure the value does not exist in the export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertFalse( isset( $export_options['custom_option'] ) );
	}

	/**
	 * Tests getting the options to export with deny list constant.
	 */
	function test_get_export_options_denylist_constant() {
		// Set a custom option.
		$option_value = rand_str();
		update_option( 'custom_option_denylist_regex', $option_value );

		// Check custom value is in export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertEquals( $option_value, $export_options['custom_option_denylist_regex'] );

		// Add the value to the deny list.
		define( 'WP_OPTION_EXPORT_DENYLIST_REGEX', '/^custom_option_denylist_regex$/' );

		// Ensure the value does not exist in the export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertFalse( isset( $export_options['custom_option_denylist_regex'] ) );

		// Test legacy filer name.
		define( 'WP_OPTION_EXPORT_BLACKLIST_REGEX', '/^custom_option_blacklist_regex$/' );

		// Ensure the value does not exist in the export.
		$export_options = WP_Options_Importer::instance()->get_export_options();
		$this->assertFalse( isset( $export_options['custom_option_blacklist_regex'] ) );
	}
}
