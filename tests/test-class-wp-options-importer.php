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
	 * Tests the different ways to import options.
	 */
	function test_get_options_to_import() {
		// Stub test data.
		$test_options = array(
			'option_1' => rand_str(),
			'option_2' => rand_str(),
			'option_3' => rand_str(),
		);
		WP_Options_Importer::instance()->import_data['options'] = $test_options;

		// All options.
		$this->assertEquals(
			array_keys( $test_options ),
			array_values( WP_Options_Importer::instance()->get_options_to_import( 'all', array() ) )
		);

		// Default options.
		$this->assertEquals(
			array_values( WP_Options_Importer::instance()->get_allowlist_options() ),
			array_values( WP_Options_Importer::instance()->get_options_to_import( 'default', array() ) )
		);

		// Specific options.
		$this->assertEquals(
			array_keys( array( 'option_1' => true, 'option_2' => true ) ),
			array_values( WP_Options_Importer::instance()->get_options_to_import( 'specific', array( 'option_1', 'option_2' ) ) )
		);
	}

	/**
	 * Tests importing a single option.
	 */
	function test_import_option() {
		$option_name = 'import_option';
		$option_value = rand_str();
		WP_Options_Importer::instance()->import_data['options'][ $option_name ] = $option_value;
		WP_Options_Importer::instance()->import_data['no_autoload'] = array();

		// Import the option.
		$this->assertTrue( WP_Options_Importer::instance()->import_option( $option_name, true ) );
		$this->assertEquals( get_option( $option_name ), $option_value );

		add_filter( 'options_import_denylist', function ( $denylist ) { return array_merge( $denylist, array( 'import_option' ) ); } );
		$this->assertInstanceOf( '\WP_Error', WP_Options_Importer::instance()->import_option( $option_name, true ) );

		// Backwards support for old filter name.
		add_filter( 'options_import_denylist', '__return_empty_array' );
		add_filter( 'options_import_blacklist', function ( $denylist ) { return array_merge( $denylist, array( 'import_option' ) ); } );
		$this->assertInstanceOf( '\WP_Error', WP_Options_Importer::instance()->import_option( $option_name, true ) );
	}

	/**
	 * Tests getting the allowlist option names filter.
	 */
	function test_get_allowlist_options_filter() {
		add_filter( 'options_import_allowlist', function ( $allowlist ) { return array_merge( $allowlist, array( 'custom_option_allowlist' ) ); } );
		$this->assertTrue( in_array( 'custom_option_allowlist', WP_Options_Importer::instance()->get_allowlist_options(), true ) );

		// Backwards support for old filter name.
		add_filter( 'options_import_whitelist', function ( $allowlist ) { return array_merge( $allowlist, array( 'custom_option_whitelist' ) ); } );
		$this->assertTrue( in_array( 'custom_option_whitelist', WP_Options_Importer::instance()->get_allowlist_options(), true ) );
	}

	/**
	 * Tests getting the denylist option names filter.
	 */
	function test_get_denylist_options_filter() {
		add_filter( 'options_import_denylist', function ( $denylist ) { return array_merge( $denylist, array( 'custom_option_denylist' ) ); } );
		$this->assertTrue( in_array( 'custom_option_denylist', WP_Options_Importer::instance()->get_denylist_options(), true ) );

		// Backwards support for old filter name.
		add_filter( 'options_import_blacklist', function ( $denylist ) { return array_merge( $denylist, array( 'custom_option_blacklist' ) ); } );
		$this->assertTrue( in_array( 'custom_option_blacklist', WP_Options_Importer::instance()->get_denylist_options(), true ) );
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
