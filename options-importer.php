<?php

/*
	Plugin Name: WP Options Importer
	Plugin URI: https://github.com/alleyinteractive/options-importer
	Description: Export and import WordPress Options
	Version: 1
	Author: Matthew Boynes
	Author URI: http://www.alleyinteractive.com/
*/
/*  This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


if ( !class_exists( 'WP_Options_Importer' ) ) :

class WP_Options_Importer {

	private static $instance;

	private $file_id;

	const VERSION = 1;

	private $max_version = 1;

	public $import_data;

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public function __clone() { wp_die( "Please don't __clone WP_Options_Importer" ); }

	public function __wakeup() { wp_die( "Please don't __wakeup WP_Options_Importer" ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new WP_Options_Importer;
			self::$instance->setup();
		}
		return self::$instance;
	}


	/**
	 * Initialize the singleton.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'export_filters', array( $this, 'export_filters' ) );
		add_filter( 'export_args', array( $this, 'export_args' ) );
		add_action( 'export_wp', array( $this, 'export_wp' ) );
		if ( function_exists( 'register_importer' ) ) {
			register_importer( 'wp-options-import', __( 'Settings', 'wp-options-importer' ), __( 'Import wp_options from a JSON file', 'wp-options-importer' ), array( $this, 'dispatch' ) );
		}
	}


	/**
	 * Add a radio option to export settings.
	 *
	 * @return void
	 */
	public function export_filters() {
		?>
		<p><label><input type="radio" name="content" value="options" /> <?php _e( 'Settings', 'wp-options-importer' ); ?></label></p>
		<?php
	}


	/**
	 * If the user selected that they want to export settings, indicate that in the args and
	 * discard anything else. This will get picked up by WP_Options_Importer::export_wp().
	 *
	 * @param  array $args The export args being filtered.
	 * @return array The (possibly modified) export args.
	 */
	public function export_args( $args ) {
		if ( ! empty( $_GET['content'] ) && 'options' == $_GET['content'] ) {
			return array( 'options' => true );
		}
		return $args;
	}


	/**
	 * Export options as a JSON file if that's what the user wants to do.
	 *
	 * @param  array $args The export arguments.
	 * @return void
	 */
	public function export_wp( $args ) {
		if ( ! empty( $args['options'] ) ) {
			global $wpdb;

			$sitename = sanitize_key( get_bloginfo( 'name' ) );
			if ( ! empty( $sitename ) ) {
				$sitename .= '.';
			}
			$filename = $sitename . 'wordpress.' . date( 'Y-m-d' ) . '.json';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );

			// Ignore multisite-specific keys
			$multisite_exclude = '';
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				$multisite_exclude = $wpdb->prepare( "AND `option_name` NOT LIKE 'wp_%d_%%'", get_current_blog_id() );
			}

			$option_names = $wpdb->get_col( "SELECT DISTINCT `option_name` FROM $wpdb->options WHERE `option_name` NOT LIKE '_transient_%' {$multisite_exclude}" );
			if ( ! empty( $option_names ) ) {


				// Allow others to be able to exclude their options from exporting
				$blacklist = apply_filters( 'options_export_exclude', array() );

				$export_options = array();
				// we're going to use a random hash as our default, to know if something is set or not
				$hash = '048f8580e913efe41ca7d402cc51e848';
				foreach ( $option_names as $option_name ) {
					if ( in_array( $option_name, $blacklist ) ) {
						continue;
					}

					$option_value = get_option( $option_name, $hash );
					// only export the setting if it's present
					if ( $option_value !== $hash ) {
						$export_options[ $option_name ] = maybe_serialize( $option_value );
					}
				}

				$no_autoload = $wpdb->get_col( "SELECT DISTINCT `option_name` FROM $wpdb->options WHERE `option_name` NOT LIKE '_transient_%' {$multisite_exclude} AND `autoload`='no'" );
				if ( empty( $no_autoload ) ) {
					$no_autoload = array();
				}

				echo json_encode( array( 'version' => self::VERSION, 'options' => $export_options, 'no_autoload' => $no_autoload ) );

			}

			exit;
		}
	}


	/**
	 * Registered callback function for the Settings Importer
	 *
	 * Manages the three separate stages of the import process.
	 *
	 * @return void
	 */
	public function dispatch() {
		$this->header();

		if ( empty( $_GET['step'] ) ) {
			$_GET['step'] = 0;
		}

		switch ( intval( $_GET['step'] ) ) {
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				if ( $this->handle_upload() ) {
					$this->pre_import();
				}
				break;
			case 2:
				check_admin_referer( 'import-wordpress-settings' );
				$this->file_id = intval( $_POST['import_id'] );
				$file = get_attached_file( $this->file_id );
				$this->import( $file );
				break;
		}

		$this->footer();
	}


	/**
	 * Start the settings page HTML.
	 *
	 * @return void
	 */
	private function header() {
		echo '<div class="wrap">';
		echo '<h2>' . __( 'Import WordPress Settings', 'wp-options-importer' ) . '</h2>';
	}


	/**
	 * End the settings page HTML.
	 *
	 * @return void
	 */
	private function footer() {
		echo '</div>';
	}


	/**
	 * Display introductory text and file upload form.
	 *
	 * @return void
	 */
	private function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__( 'Howdy! Upload your WordPress settings JSON file and we&#8217;ll import the desired data. You&#8217;ll have a chance to review the data prior to import.', 'wp-options-importer' ).'</p>';
		echo '<p>'.__( 'Choose a JSON (.json) file to upload, then click Upload file and import.', 'wp-options-importer' ).'</p>';
		wp_import_upload_form( 'admin.php?import=wp-options-import&amp;step=1' );
		echo '</div>';
	}


	/**
	 * Handles the JSON upload and initial parsing of the file to prepare for
	 * displaying author import options
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	private function handle_upload() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			return $this->error_message(
				__( 'Sorry, there has been an error.', 'wp-options-importer' ),
				esc_html( $file['error'] )
			);
		}

		if ( ! isset( $file['file'] ) ) {
			return $this->error_message(
				__( 'Sorry, there has been an error.', 'wp-options-importer' ),
				__( 'The file did not upload properly. Please try again.', 'wp-options-importer' )
			);
		}

		$this->file_id = intval( $file['id'] );
		return $this->run_import_check( $file['file'] );
	}


	/**
	 * Get an array of known options which we would want checked by default when importing.
	 *
	 * @return array
	 */
	private function get_whitelist_options() {
		return apply_filters( 'options_import_whitelist', array(
			// 'active_plugins',
			'admin_email',
			'advanced_edit',
			'avatar_default',
			'avatar_rating',
			'blacklist_keys',
			'blogdescription',
			'blogname',
			'blog_charset',
			'blog_public',
			'blog_upload_space',
			'category_base',
			'category_children',
			'close_comments_days_old',
			'close_comments_for_old_posts',
			'comments_notify',
			'comments_per_page',
			'comment_max_links',
			'comment_moderation',
			'comment_order',
			'comment_registration',
			'comment_whitelist',
			'cron',
			// 'current_theme',
			'date_format',
			'default_category',
			'default_comments_page',
			'default_comment_status',
			'default_email_category',
			'default_link_category',
			'default_pingback_flag',
			'default_ping_status',
			'default_post_format',
			'default_role',
			'gmt_offset',
			'gzipcompression',
			'hack_file',
			'html_type',
			'image_default_align',
			'image_default_link_type',
			'image_default_size',
			'large_size_h',
			'large_size_w',
			'links_recently_updated_append',
			'links_recently_updated_prepend',
			'links_recently_updated_time',
			'links_updated_date_format',
			'link_manager_enabled',
			'mailserver_login',
			'mailserver_pass',
			'mailserver_port',
			'mailserver_url',
			'medium_size_h',
			'medium_size_w',
			'moderation_keys',
			'moderation_notify',
			'ms_robotstxt',
			'ms_robotstxt_sitemap',
			'nav_menu_options',
			'page_comments',
			'page_for_posts',
			'page_on_front',
			'permalink_structure',
			'ping_sites',
			'posts_per_page',
			'posts_per_rss',
			'recently_activated',
			'recently_edited',
			'require_name_email',
			'rss_use_excerpt',
			'show_avatars',
			'show_on_front',
			'sidebars_widgets',
			'start_of_week',
			'sticky_posts',
			// 'stylesheet',
			'subscription_options',
			'tag_base',
			// 'template',
			'theme_switched',
			'thread_comments',
			'thread_comments_depth',
			'thumbnail_crop',
			'thumbnail_size_h',
			'thumbnail_size_w',
			'timezone_string',
			'time_format',
			'uninstall_plugins',
			'uploads_use_yearmonth_folders',
			'upload_path',
			'upload_url_path',
			'users_can_register',
			'use_balanceTags',
			'use_smilies',
			'use_trackback',
			'widget_archives',
			'widget_categories',
			'widget_image',
			'widget_meta',
			'widget_nav_menu',
			'widget_recent-comments',
			'widget_recent-posts',
			'widget_rss',
			'widget_rss_links',
			'widget_search',
			'widget_text',
			'widget_top-posts',
			'WPLANG',
		) );
	}


	/**
	 * Provide the user with options of which settings to import from the JSON file, pre-selecting
	 * known options.
	 *
	 * @return void
	 */
	private function pre_import() {
		$whitelist = $this->get_whitelist_options();
		?>
		<form action="<?php echo admin_url( 'admin.php?import=wp-options-import&amp;step=2' ); ?>" method="post">
			<?php wp_nonce_field( 'import-wordpress-settings' ); ?>
			<input type="hidden" name="import_id" value="<?php echo $this->file_id; ?>" />

			<?php if ( ! empty( $this->import_data['options'] ) ) : ?>
				<style type="text/css">
				#importing_options {
					border-collapse: collapse;
				}
				#importing_options th {
					text-align: left;
				}
				#importing_options td, #importing_options th {
					padding: 5px 10px;
					border-bottom: 1px solid #dfdfdf;
				}
				#importing_options pre {
					white-space: pre-wrap;
					max-height: 100px;
					overflow-y: auto;
					background: #fff;
					padding: 5px;
				}
				</style>

				<h3><?php _e( 'Select options to import', 'wp-options-importer' ); ?></h3>
				<table id="importing_options">
					<thead>
						<tr>
							<th>&nbsp;</th>
							<th><?php _e( 'Option Name', 'wp-options-importer' ); ?></th>
							<th><?php _e( 'New Value', 'wp-options-importer' ) ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->import_data['options'] as $option_name => $option_value ) : ?>
							<tr>
								<td><input type="checkbox" name="options[<?php echo esc_attr( $option_name ) ?>]" value="1" <?php checked( in_array( $option_name, $whitelist ) ) ?> /></td>
								<td><?php echo esc_html( $option_name ) ?></td>
								<?php if ( null === $option_value ) : ?>
									<td><em>null</em></td>
								<?php elseif ( '' === $option_value ) : ?>
									<td><em>empty string</em></td>
								<?php elseif ( false === $option_value ) : ?>
									<td><em>false</em></td>
								<?php else : ?>
									<td><pre><?php echo esc_html( $option_value ) ?></pre></td>
								<?php endif ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>

			<?php submit_button( __( 'Import Selected Options', 'wp-options-importer' ) ); ?>
		</form>
		<?php
	}


	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the JSON file for importing
	 * @return void
	 */
	private function import( $file ) {
		if ( $this->run_import_check( $file ) ) {
			if ( empty( $_POST['options'] ) ) {
				return $this->error_message( __( 'There do not appear to be any options to import. Did you select any?', 'wp-options-importer' ) );
			}

			foreach ( (array) $_POST['options'] as $option_name => $one ) {
				if ( isset( $this->import_data['options'][ $option_name ] ) ) {
					$option_value = maybe_unserialize( $this->import_data['options'][ $option_name ] );
					if ( in_array( $option_name, $this->import_data['no_autoload'] ) ) {
						delete_option( '$option_name' );
						add_option( $option_name, $option_value, '', 'no' );
					} else {
						update_option( $option_name, $option_value );
					}
				} else {
					echo "\n<p>" . sprintf( __( 'Failed to import option `%s`; it does not appear to be in the import file.', 'wp-options-importer' ), esc_html( $option_name ) ) . '</p>';
				}
			}

			echo '<p>' . __( 'That was easy.', 'wp-options-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'wp-options-importer' ) . '</a>' . '</p>';
		}
	}


	/**
	 * Run a series of checks to ensure we're working with a valid JSON export.
	 *
	 * @param  string $file The path to the JSON file.
	 * @return bool true if the file and data appear valid, false otherwise.
	 */
	private function run_import_check( $file ) {
		if ( ! file_exists( $file ) ) {
			return $this->error_message(
				__( 'Sorry, there has been an error.', 'wp-options-importer' ),
				sprintf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wp-options-importer' ), esc_html( $file['file'] ) )
			);
		}

		if ( ! is_file( $file ) ) {
			return $this->error_message(
				__( 'Sorry, there has been an error.', 'wordpress-importer' ),
				__( 'The path is not a file, please try again.', 'wordpress-importer' )
			);
		}

		$file_contents = file_get_contents( $file );
		$this->import_data = json_decode( $file_contents, true );
		if ( empty( $this->import_data['version'] ) ) {
			return $this->error_message( __( 'Sorry, there has been an error. This file may not contain data or is corrupt.', 'wp-options-importer' ) );
		}

		if ( $this->import_data['version'] > $this->max_version ) {
			return $this->error_message( sprintf( __( 'This JSON file (version %s) may not be supported by this version of the importer. Please consider updating.', 'wp-options-importer' ), intval( $this->import_data['version'] ) ) );
		}

		if ( empty( $this->import_data['options'] ) ) {
			return $this->error_message( __( 'Sorry, there has been an error. This file appears valid, but does not seem to have any options.', 'wp-options-importer' ) );
		}

		return true;
	}


	/**
	 * A helper method to keep DRY with our error messages. Note that the error messages
	 * must be escaped prior to being passed to this method (this allows us to send HTML).
	 *
	 * @param  string $message The main message to output.
	 * @param  string $details Optional. Additional details.
	 * @return bool false
	 */
	private function error_message( $message, $details = '' ) {
		echo '<div class="error"><p><strong>' . $message . '</strong>';
		if ( ! empty( $details ) ) {
			echo '<br />' . $details;
		}
		echo '</p></div>';
		return false;
	}
}

WP_Options_Importer::instance();

endif;