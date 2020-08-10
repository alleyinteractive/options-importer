<?php
/**
 * The main class file.
 *
 * @package Options_Importer
 */

/**
 * The main class.
 */
class WP_Options_Importer {

	/**
	 * Stores the singleton instance.
	 *
	 * @access private
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * The attachment ID.
	 *
	 * @access private
	 *
	 * @var int
	 */
	private $file_id;

	/**
	 * The transient key template used to store the options after upload.
	 *
	 * @access private
	 *
	 * @var string
	 */
	private $transient_key = 'options-import-%d';

	/**
	 * The plugin version.
	 */
	const VERSION = 7;

	/**
	 * The minimum file version the importer will allow.
	 *
	 * @access private
	 *
	 * @var int
	 */
	private $min_version = 2;

	/**
	 * Stores the import data from the uploaded file.
	 *
	 * @access public
	 *
	 * @var array
	 */
	public $import_data;

	/**
	 * Don't do anything, needs to be initialized via instance() method.
	 */
	private function __construct() {}

	/**
	 * Die if attempting to clone this class.
	 */
	public function __clone() {
		wp_die( "Please don't __clone WP_Options_Importer" );
	}

	/**
	 * Die if attempting to wakup this class.
	 */
	public function __wakeup() {
		wp_die( "Please don't __wakeup WP_Options_Importer" );
	}

	/**
	 * Creates and returns the singleton instance.
	 *
	 * @return \WP_Options_Importer The singleton instance.
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new WP_Options_Importer();
		}

		return self::$instance;
	}

	/**
	 * Initialize the singleton.
	 */
	public function setup() {
		add_action( 'export_filters', array( $this, 'export_filters' ) );
		add_filter( 'export_args', array( $this, 'export_args' ) );
		add_action( 'export_wp', array( $this, 'export_wp' ) );
		add_action( 'admin_init', array( $this, 'register_importer' ) );
	}

	/**
	 * Register our importer.
	 */
	public function register_importer() {
		if ( function_exists( 'register_importer' ) ) {
			register_importer(
				'wp-options-import',
				esc_html__( 'Options', 'wp-options-importer' ),
				esc_html__( 'Import wp_options from a JSON file', 'wp-options-importer' ),
				array( $this, 'dispatch' )
			);
		}
	}

	/**
	 * Add a radio option to export options.
	 */
	public function export_filters() {
		?>
		<p>
			<label>
				<input type="radio" name="content" value="options" /> <?php esc_html_e( 'Options', 'wp-options-importer' ); ?>
			</label>
		</p>
		<?php

		// Nonce.
		wp_nonce_field( 'options-importer-filter', 'options-importer-filter' );
	}

	/**
	 * If the user selected that they want to export options, indicate that in the args and
	 * discard anything else. This will get picked up by WP_Options_Importer::export_wp().
	 *
	 * @param  array $args The export args being filtered.
	 * @return array The (possibly modified) export args.
	 */
	public function export_args( $args ) {
		// Verify nonce.
		check_admin_referer( 'options-importer-filter', 'options-importer-filter' );

		if ( ! empty( $_GET['content'] ) && 'options' === $_GET['content'] ) {
			return array( 'options' => true );
		}

		return $args;
	}

	/**
	 * Export options as a JSON file if that's what the user wants to do.
	 *
	 * @param array $args The export arguments.
	 */
	public function export_wp( $args ) {
		if ( ! empty( $args['options'] ) ) {

			$sitename = sanitize_key( get_bloginfo( 'name' ) );
			if ( ! empty( $sitename ) ) {
				$sitename .= '.';
			}

			if ( function_exists( 'wp_date' ) ) {
				$date = wp_date( 'Y-m-d' );
			} else {
				$date = gmdate( 'Y-m-d' );
			}

			$filename = $sitename . 'wp_options.' . $date . '.json';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );

			$export_options = $this->get_export_options();

			if ( ! empty( $export_options ) ) {
				$json_pretty_print = defined( 'JSON_PRETTY_PRINT' ) ? JSON_PRETTY_PRINT : null;

				echo wp_json_encode(
					array(
						'version'     => self::VERSION,
						'options'     => $export_options,
						'no_autoload' => $this->get_export_options_no_autoload(),
					),
					$json_pretty_print
				);
			}

			// Exit.
			exit;
		}
	}

	/**
	 * Gets all of the export options and their values for the export file.
	 *
	 * @return array Any array of options to export.
	 */
	public function get_export_options() {
		global $wpdb;

		$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT DISTINCT `option_name`
			FROM $wpdb->options
			WHERE `option_name` NOT LIKE '_transient_%'
			AND `option_name` NOT LIKE '_site_transient_%'"
		);

		if ( ! empty( $option_names ) ) {

			/**
			 * Filters options that are in the denylist to be exported.
			 *
			 * @param array The deny list options.
			 */
			$denylist = apply_filters( 'options_export_denylist', array() );

			// Backwards compat for legacy filter name.
			$denylist = apply_filters( 'options_export_blacklist', $denylist );

			$export_options = array();

			// We're going to use a random hash as our default, to know if something is set or not.
			$hash = '048f8580e913efe41ca7d402cc51e848';
			foreach ( $option_names as $option_name ) {

				// Skip if in the deny list.
				if ( in_array( $option_name, $denylist, true ) ) {
					continue;
				}

				// Allow an installation to define a regular expression export denylist for security purposes. It's entirely possible
				// that sensitive data might be installed in an option, or you may not want anyone to even know that a key exists.
				// For instance, if you run a multsite installation, you could add in an mu-plugin:
				// define( 'WP_OPTION_EXPORT_DENYLIST_REGEX', '/^(mailserver_(login|pass|port|url))$/' );
				// to ensure that none of your sites could export your mailserver settings.
				if ( defined( 'WP_OPTION_EXPORT_DENYLIST_REGEX' ) && preg_match( WP_OPTION_EXPORT_DENYLIST_REGEX, $option_name ) ) {
					continue;
				}

				// Backwards compat for legacy constant name.
				if ( defined( 'WP_OPTION_EXPORT_BLACKLIST_REGEX' ) && preg_match( WP_OPTION_EXPORT_BLACKLIST_REGEX, $option_name ) ) {
					continue;
				}

				$option_value = get_option( $option_name, $hash );

				// Only export the setting if it's present.
				if ( $option_value !== $hash ) {
					$export_options[ $option_name ] = maybe_serialize( $option_value );
				}
			}

			return $export_options;
		}

		return array();
	}

	/**
	 * Gets all of the export option names with autoload disabled.
	 *
	 * @return array Array of option names that have autoload disabled.
	 */
	public function get_export_options_no_autoload() {
		global $wpdb;

		$no_autoload = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT DISTINCT `option_name`
			FROM $wpdb->options
			WHERE `option_name` NOT LIKE '_transient_%'
			AND `option_name` NOT LIKE '_site__transient_%'
			AND `autoload`='no'"
		);

		if ( empty( $no_autoload ) ) {
			$no_autoload = array();
		}

		return (array) $no_autoload;
	}

	/**
	 * Registered callback function for the Options Importer
	 *
	 * Manages the three separate stages of the import process.
	 */
	public function dispatch() {
		$this->header();

		if ( empty( $_GET['step'] ) ) {
			$_GET['step'] = 0;
		}

		switch ( intval( $_GET['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-upload' );

				if ( $this->handle_upload() ) {
					$this->pre_import();
				} else {
					echo '<p><a href="' . esc_url( admin_url( 'admin.php?import=wp-options-import' ) ) . '">' . esc_html__( 'Return to File Upload', 'wp-options-importer' ) . '</a></p>';
				}

				break;
			case 2:
				check_admin_referer( 'import-wordpress-options' );

				$this->file_id     = ! empty( $_POST['import_id'] ) ? intval( $_POST['import_id'] ) : 0;
				$this->import_data = get_transient( $this->transient_key() );

				if ( false !== $this->import_data ) {
					$this->import();
				}

				break;
		}

		$this->footer();
	}

	/**
	 * Start the options import page HTML.
	 */
	private function header() {
		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Import WordPress Options', 'wp-options-importer' ) . '</h2>';
	}

	/**
	 * End the options import page HTML.
	 */
	private function footer() {
		echo '</div>';
	}

	/**
	 * Display introductory text and file upload form.
	 */
	private function greet() {
		echo '<div class="narrow">';
		echo '<p>' . esc_html__( 'Howdy! Upload your WordPress options JSON file and we&#8217;ll import the desired data. You&#8217;ll have a chance to review the data prior to import.', 'wp-options-importer' ) . '</p>';
		echo '<p>' . esc_html__( 'Choose a JSON (.json) file to upload, then click Upload file and import.', 'wp-options-importer' ) . '</p>';
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
				esc_html__( 'Sorry, there has been an error.', 'wp-options-importer' ),
				esc_html( $file['error'] )
			);
		}

		if ( ! isset( $file['file'], $file['id'] ) ) {
			return $this->error_message(
				esc_html__( 'Sorry, there has been an error.', 'wp-options-importer' ),
				esc_html__( 'The file did not upload properly. Please try again.', 'wp-options-importer' )
			);
		}

		$this->file_id = intval( $file['id'] );

		if ( ! file_exists( $file['file'] ) ) {
			wp_import_cleanup( $this->file_id );
			return $this->error_message(
				esc_html__( 'Sorry, there has been an error.', 'wp-options-importer' ),
				/* translators: 1. filename */
				sprintf( esc_html__( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wp-options-importer' ), esc_html( $file['file'] ) )
			);
		}

		if ( ! is_file( $file['file'] ) ) {
			wp_import_cleanup( $this->file_id );
			return $this->error_message(
				esc_html__( 'Sorry, there has been an error.', 'wordpress-importer' ),
				esc_html__( 'The path is not a file, please try again.', 'wordpress-importer' )
			);
		}

		// Get the file source URL.
		$file_url = wp_get_attachment_url( $this->file_id );

		// Unable to get the file URL.
		if ( empty( $file_url ) ) {
			wp_import_cleanup( $this->file_id );
			return $this->error_message(
				esc_html__( 'Sorry, there has been an error.', 'wordpress-importer' ),
				esc_html__( 'Unable to fetch the file URL.', 'wordpress-importer' )
			);
		}

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$file_contents = vip_safe_wp_remote_get( $file_url );
		} else {
			$file_contents = wp_remote_get( $file_url ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		}

		// Invalid file or file contents.
		if ( is_wp_error( $file_contents ) || empty( $file_contents['body'] ) ) {
			wp_import_cleanup( $this->file_id );
			return $this->error_message(
				esc_html__( 'Sorry, there has been an error.', 'wordpress-importer' ),
				esc_html__( 'Unable to fetch the file contents.', 'wordpress-importer' )
			);
		}

		$this->import_data = json_decode( $file_contents['body'], true );

		set_transient( $this->transient_key(), $this->import_data, DAY_IN_SECONDS );
		wp_import_cleanup( $this->file_id );

		return $this->run_data_check();
	}


	/**
	 * Get an array of known options which we would want checked by default when importing.
	 *
	 * @return array
	 */
	public function get_allowlist_options() {
		/**
		 * Filters the allowed options to be imported.
		 *
		 * @param array The allowlist of options to be imported.
		 */
		$allowlist = apply_filters( 'options_import_allowlist', $this->get_default_import_options() );

		// Backwards compat for legacy filter name.
		$allowlist = apply_filters( 'options_import_whitelist', $allowlist );

		return $allowlist;
	}

	/**
	 * Gets an array of default options to import.
	 *
	 * @return array An array of option names.
	 */
	public function get_default_import_options() {
		return array(
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
			'comment_previously_approved',
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
			'disallowed_keys',
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
		);
	}

	/**
	 * Get an array of denylist options which we never want to import.
	 *
	 * @return array The import denylist.
	 */
	public function get_denylist_options() {
		/**
		 * Filters the denylist of options to import.
		 *
		 * @param array The options denylist.
		 */
		$denylist = apply_filters( 'options_import_denylist', array() );

		// Backwards compat for legacy filter name.
		$denylist = apply_filters( 'options_import_blacklist', $denylist );

		return $denylist;
	}


	/**
	 * Provide the user with a choice of which options to import from the JSON
	 * file, pre-selecting known options.
	 */
	private function pre_import() {
		$allowlist = $this->get_allowlist_options();

		// Allow others to prevent their options from importing.
		$denylist = $this->get_denylist_options();

		?>
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
		div.error#import_all_warning {
			margin: 25px 0 5px;
		}
		</style>
		<script type="text/javascript">
		jQuery( function( $ ) {
			$('#option_importer_details,#import_all_warning').hide();
			options_override_all_warning = function() {
				$('#import_all_warning').toggle( $('input.which-options[value="all"]').is( ':checked' ) && $('#override_current').is( ':checked' ) );
			};
			$('.which-options').change( function() {
				options_override_all_warning();
				switch ( $(this).val() ) {
					case 'specific' : $('#option_importer_details').fadeIn(); break;
					default : $('#option_importer_details').fadeOut(); break;
				}
			} );
			$('#override_current').click( options_override_all_warning );
			$('#importing_options input:checkbox').each( function() {
				$(this).data( 'default', $(this).is(':checked') );
			} );
			$('.options-bulk-select').click( function( event ) {
				event.preventDefault();
				switch ( $(this).data('select') ) {
					case 'all' : $('#importing_options input:checkbox').prop( 'checked', true ); break;
					case 'none' : $('#importing_options input:checkbox').prop( 'checked', false ); break;
					case 'defaults' : $('#importing_options input:checkbox').each( function() { $(this).prop( 'checked', $(this).data( 'default' ) ); } ); break;
				}
			} );
		} );
		</script>
		<form action="<?php echo esc_url( admin_url( 'admin.php?import=wp-options-import&amp;step=2' ) ); ?>" method="post">
			<?php wp_nonce_field( 'import-wordpress-options' ); ?>
			<input type="hidden" name="import_id" value="<?php echo absint( $this->file_id ); ?>" />

			<h3><?php esc_html_e( 'What would you like to import?', 'wp-options-importer' ); ?></h3>
			<p>
				<label><input type="radio" class="which-options" name="settings[which_options]" value="default" checked="checked" /> <?php esc_html_e( 'Default Options' ); ?></label>
				<br /><label><input type="radio" class="which-options" name="settings[which_options]" value="all" /> <?php esc_html_e( 'All Options' ); ?></label>
				<br /><label><input type="radio" class="which-options" name="settings[which_options]" value="specific" /> <?php esc_html_e( 'Specific Options' ); ?></label>
			</p>

			<div id="option_importer_details">
				<h3><?php esc_html_e( 'Select the options to import', 'wp-options-importer' ); ?></h3>
				<p>
					<a href="#" class="options-bulk-select" data-select="all"><?php esc_html_e( 'Select All', 'wp-options-importer' ); ?></a>
					| <a href="#" class="options-bulk-select" data-select="none"><?php esc_html_e( 'Select None', 'wp-options-importer' ); ?></a>
					| <a href="#" class="options-bulk-select" data-select="defaults"><?php esc_html_e( 'Select Defaults', 'wp-options-importer' ); ?></a>
				</p>
				<table id="importing_options">
					<thead>
						<tr>
							<th>&nbsp;</th>
							<th><?php esc_html_e( 'Option Name', 'wp-options-importer' ); ?></th>
							<th><?php esc_html_e( 'New Value', 'wp-options-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->import_data['options'] as $option_name => $option_value ) : ?>
							<?php
							// See WP_Options_Importer::import() for an explanation of this.
							if ( defined( 'WP_OPTION_IMPORT_DENYLIST_REGEX' ) && preg_match( WP_OPTION_IMPORT_DENYLIST_REGEX, $option_name ) ) {
								continue;
							}

							// Backwards compat for legacy constant name.
							if ( defined( 'WP_OPTION_IMPORT_BLACKLIST_REGEX' ) && preg_match( WP_OPTION_IMPORT_BLACKLIST_REGEX, $option_name ) ) {
								continue;
							}

							// Skip if this option is in the deny list.
							if ( in_array( $option_name, $denylist, true ) ) {
								continue;
							}
							?>
							<tr>
								<td><input type="checkbox" name="options[]" value="<?php echo esc_attr( $option_name ); ?>" <?php checked( in_array( $option_name, $allowlist, true ) ); ?> /></td>
								<td><?php echo esc_html( $option_name ); ?></td>
								<?php if ( null === $option_value ) : ?>
									<td><em><?php esc_html_e( 'null', 'wp-options-importer' ); ?></em></td>
								<?php elseif ( '' === $option_value ) : ?>
									<td><em><?php esc_html_e( 'empty string', 'wp-options-importer' ); ?></em></td>
								<?php elseif ( false === $option_value ) : ?>
									<td><em><?php esc_html_e( 'false', 'wp-options-importer' ); ?></em></td>
								<?php else : ?>
									<td><pre><?php echo esc_html( $option_value ); ?></pre></td>
								<?php endif ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<h3><?php esc_html_e( 'Additional Settings', 'wp-options-importer' ); ?></h3>
			<p>
				<input type="checkbox" value="1" name="settings[override]" id="override_current" checked="checked" />
				<label for="override_current"><?php esc_html_e( 'Override existing options', 'wp-options-importer' ); ?></label>
			</p>
			<p class="description"><?php esc_html_e( 'If you uncheck this box, options will be skipped if they currently exist.', 'wp-options-importer' ); ?></p>

			<div class="error inline" id="import_all_warning">
				<p class="description"><?php esc_html_e( 'Caution! Importing all options with the override option set could break this site. For instance, it may change the site URL, the active theme, and active plugins. Only proceed if you know exactly what you&#8217;re doing.', 'wp-options-importer' ); ?></p>
			</div>

			<?php submit_button( esc_html__( 'Import Selected Options', 'wp-options-importer' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The main controller for the actual import stage.
	 */
	private function import() {
		// Nonce check.
		check_admin_referer( 'import-wordpress-options' );

		if ( $this->run_data_check() ) {
			if ( empty( $_POST['settings']['which_options'] ) ) {
				$this->error_message( esc_html__( 'The posted data does not appear intact. Please try again.', 'wp-options-importer' ) );
				$this->pre_import();
				return;
			}

			// Determine which options to import.
			$which_options = sanitize_text_field( wp_unslash( $_POST['settings']['which_options'] ) );

			// Specific options to import.
			$specific_options = array();

			if ( 'specific' === $which_options ) {
				if ( empty( $_POST['options'] ) ) {
					$this->error_message( esc_html__( 'There do not appear to be any options to import. Did you select any?', 'wp-options-importer' ) );
					$this->pre_import();
					return;
				}

				$specific_options = array_map( 'sanitize_text_field', wp_unslash( $_POST['options'] ) );
			}

			// Get the options to import.
			$options_to_import = $this->get_options_to_import( $which_options, $specific_options );

			$override = ( ! empty( $_POST['settings']['override'] ) && '1' === $_POST['settings']['override'] );

			foreach ( (array) $options_to_import as $option_name ) {
				if ( isset( $this->import_data['options'][ $option_name ] ) ) {

					// Import the option.
					$this->import_option( $option_name, $override );

				} elseif ( 'specific' === $which_options ) {
					/* translators: 1. option name */
					echo "\n<p>" . sprintf( esc_html__( 'Failed to import option `%s`; it does not appear to be in the import file.', 'wp-options-importer' ), esc_html( $option_name ) ) . '</p>';
				}
			}

			$this->clean_up();
			echo '<p>' . esc_html__( 'All done. That was easy.', 'wp-options-importer' ) . ' <a href="' . esc_url( admin_url() ) . '">' . esc_html__( 'Have fun!', 'wp-options-importer' ) . '</a></p>';
		}
	}

	/**
	 * Gets the options to import.
	 *
	 * @param  string $which_options     Which options should be imported.
	 * @param  array  $specific_options  An array of specific option names to import.
	 * @return array  $options_to_import An array of option names to import.
	 */
	public function get_options_to_import( $which_options, $specific_options ) {
		$options_to_import = array();

		if ( 'all' === $which_options ) {
			$options_to_import = array_keys( $this->import_data['options'] );
		} elseif ( 'default' === $which_options ) {
			$options_to_import = $this->get_allowlist_options();
		} elseif ( 'specific' === $which_options ) {
			$options_to_import = $specific_options;
		}

		return $options_to_import;
	}

	/**
	 * Imports an option after perform checks.
	 *
	 * @param  string $name     The option name.
	 * @param  bool   $override Whether or not to override the current option.
	 * @return bool|\WP_Error   True on success, otherwise a \WP_Error object on failure.
	 */
	public function import_option( $name, $override ) {
		$hash = '048f8580e913efe41ca7d402cc51e848';

		// Allow others to prevent their options from importing.
		$denylist = $this->get_denylist_options();

		if ( in_array( $name, $denylist, true ) ) {
			/* translators: 1. option name */
			return new \WP_Error( 'skipped', sprintf( __( 'Skipped option `%s` because this WordPress installation does not allow it.', 'wp-options-importer' ), $name ) );
		}

		// As an absolute last resort for security purposes, allow an installation to define a regular expression
		// denylist. For instance, if you run a multsite installation, you could add in an mu-plugin:
		// define( 'WP_OPTION_IMPORT_BLACKLIST_REGEX', '/^(home|siteurl)$/' );
		// to ensure that none of your sites could change their own url using this tool.
		if (
			( defined( 'WP_OPTION_IMPORT_DENYLIST_REGEX' ) && preg_match( WP_OPTION_IMPORT_DENYLIST_REGEX, $name ) )
			|| ( defined( 'WP_OPTION_IMPORT_BLACKLIST_REGEX' ) && preg_match( WP_OPTION_IMPORT_BLACKLIST_REGEX, $name ) )
		) {
			/* translators: 1. option name */
			return new \WP_Error( 'skipped', sprintf( __( 'Skipped option `%s` because this WordPress installation does not allow it.', 'wp-options-importer' ), $name ) );
		}

		if ( ! $override ) {
			// We're going to use a random hash as our default, to know if something is set or not.
			$old_value = get_option( $name, $hash );

			// Only import the setting if it's not present.
			if ( $old_value !== $hash ) {
				/* translators: 1. option name */
				return new \WP_Error( 'skipped', sprintf( __( 'Skipped option `%s` because it currently exists.', 'wp-options-importer' ), $name ) );
			}
		}

		$option_value = maybe_unserialize( $this->import_data['options'][ $name ] );

		if ( in_array( $name, $this->import_data['no_autoload'], true ) ) {

			if ( false === delete_option( $name ) ) {
				/* translators: 1. option name */
				return new \WP_Error( 'error', sprintf( __( 'Failed deleting option `%s`.', 'wp-options-importer' ), $name ) );
			}

			if ( false === add_option( $name, $option_value, '', 'no' ) ) {
				/* translators: 1. option name */
				return new \WP_Error( 'error', sprintf( __( 'Failed adding option `%s`.', 'wp-options-importer' ), $name ) );
			}
		} else {

			if ( false === update_option( $name, $option_value ) ) {
				/* translators: 1. option name */
				return new \WP_Error( 'error', sprintf( __( 'Failed updating option `%s`.', 'wp-options-importer' ), $name ) );
			}
		}

		return true;
	}

	/**
	 * Run a series of checks to ensure we're working with a valid JSON export.
	 *
	 * @return bool true if the file and data appear valid, false otherwise.
	 */
	private function run_data_check() {
		if ( empty( $this->import_data['version'] ) ) {
			$this->clean_up();
			return $this->error_message( esc_html__( 'Sorry, there has been an error. This file may not contain data or is corrupt.', 'wp-options-importer' ) );
		}

		if ( $this->import_data['version'] < $this->min_version ) {
			$this->clean_up();
			/* translators: 1. file version */
			return $this->error_message( sprintf( esc_html__( 'This JSON file (version %s) is not supported by this version of the importer. Please update the plugin on the source, or download an older version of the plugin to this installation.', 'wp-options-importer' ), intval( $this->import_data['version'] ) ) );
		}

		if ( $this->import_data['version'] > self::VERSION ) {
			$this->clean_up();
			/* translators: 1. file version */
			return $this->error_message( sprintf( esc_html__( 'This JSON file (version %s) is from a newer version of this plugin and may not be compatible. Please update this plugin.', 'wp-options-importer' ), intval( $this->import_data['version'] ) ) );
		}

		if ( empty( $this->import_data['options'] ) ) {
			$this->clean_up();
			return $this->error_message( esc_html__( 'Sorry, there has been an error. This file appears valid, but does not seem to have any options.', 'wp-options-importer' ) );
		}

		return true;
	}

	/**
	 * Gets the transient key name.
	 *
	 * @return string The transient key name.
	 */
	private function transient_key() {
		return sprintf( $this->transient_key, $this->file_id );
	}

	/**
	 * Deletes the transient.
	 */
	private function clean_up() {
		delete_transient( $this->transient_key() );
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
		echo '<div class="error"><p><strong>' . esc_html( $message ) . '</strong>';

		if ( ! empty( $details ) ) {
			echo '<br />' . wp_kses_post( $details );
		}

		echo '</p></div>';

		return false;
	}
}
