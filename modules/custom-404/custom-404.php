<?php
// Module: Custom 404 Page (ID: custom_404_page)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants
define( 'MABBLE_404_OPTION_KEY', 'mabble_custom_404_page_id' );
define( 'MABBLE_301_URLS_KEY', 'mabble_301_redirect_urls' ); // Key for 301 list (URL => Page ID)
define( 'MABBLE_404_LOG_TABLE', 'mabble_404_logs' );
define( 'MABBLE_404_SETTINGS_SLUG', 'mabble-404-settings' );

// Check if the module is active
$modules = get_option( 'aaee_modules' );
$is_module_active = ! empty( $modules['custom_404_page'] );

// -----------------------------------------------------------
// A. DATABASE SETUP
// -----------------------------------------------------------

/**
 * Creates the database table for 404 logging upon module activation.
 */
function mabble_404_db_setup() {
	global $wpdb;
	$table_name = $wpdb->prefix . MABBLE_404_LOG_TABLE;
	
	// Check if the table already exists
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			requested_url varchar(2000) NOT NULL,
			request_count mediumint(9) NOT NULL DEFAULT 1,
			last_hit datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY requested_url (requested_url(255))
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
}
// Run DB setup only once in the admin area if module is active
if ( $is_module_active && is_admin() ) {
	add_action( 'admin_init', 'mabble_404_db_setup' );
}

// -----------------------------------------------------------
// B. ADMIN MENU AND SETTINGS PAGE (Standalone under Settings)
// -----------------------------------------------------------

if ( $is_module_active && is_admin() ) {
	/**
	 * Registers the dedicated 404 Settings page under the main Settings menu.
	 */
	function mabble_add_404_settings_page() {
		add_options_page(
			'Mabble Custom 404 Settings & Logs', // Page Title
			'Mabble 404 Logs', 			        // Menu Title (Cleaned line)
			'manage_options',
			MABBLE_404_SETTINGS_SLUG, // mabble-404-settings
			'mabble_render_404_settings_page'
		);
	}
	add_action( 'admin_menu', 'mabble_add_404_settings_page' );

	/**
	 * Registers the settings field.
	 */
	function mabble_register_404_settings() {
		register_setting( MABBLE_404_SETTINGS_SLUG, MABBLE_404_OPTION_KEY, 'absint' );
		// New setting for storing permanent redirects
		register_setting( MABBLE_404_SETTINGS_SLUG, MABBLE_301_URLS_KEY, 'mabble_sanitize_301_urls' ); 

		add_settings_section(
			'mabble_404_setup_section',
			'Custom 404 Redirection Setup',
			'mabble_404_setup_section_callback',
			MABBLE_404_SETTINGS_SLUG
		);

		add_settings_field(
			'mabble_404_page_id_field',
			'Redirect 404 to Page:',
			'mabble_render_404_page_dropdown',
			MABBLE_404_SETTINGS_SLUG,
			'mabble_404_setup_section'
		);
	}
	add_action( 'admin_init', 'mabble_register_404_settings' );
	
	// Hook action processor to run on admin_init before rendering
	add_action( 'admin_init', 'mabble_process_301_action' );

	/**
	 * Sanitizes the 301 redirect list (should contain URL => Page ID).
	 */
	function mabble_sanitize_301_urls( $input ) {
		$sanitized = array();
		if ( is_array( $input ) ) {
			foreach ( $input as $url => $id ) {
				// Key must be a sanitized URL string
				$sanitized_url = esc_url_raw( $url );
				// Value must be an integer page ID
				$sanitized_id = absint( $id );
				
				if ( $sanitized_url && $sanitized_id > 0 ) {
					$sanitized[ $sanitized_url ] = $sanitized_id;
				}
			}
		}
		return $sanitized;
	}

	/**
	 * Renders the section header text.
	 */
	function mabble_404_setup_section_callback() {
		echo '<p>Select the default page for 404 errors. Individual 301 permanent redirects can be set below.</p>';
	}

	/**
	 * Renders the page dropdown selection field.
	 */
	function mabble_render_404_page_dropdown() {
		$current_page_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
		
		wp_dropdown_pages( array(
			'selected' 			=> $current_page_id,
			'name' 				=> MABBLE_404_OPTION_KEY,
			'show_option_none' 	=> '— Do not Redirect 404s —',
			'option_none_value' => '0',
			'echo' 				=> 1,
			'post_status' 		=> array('publish', 'private'),
			'hierarchical' 		=> 0,
			'sort_column' 		=> 'post_title',
		) );
		
		echo '<p class="description">**IMPORTANT:** This sets the default target. The general redirect is a **302 Temporary Redirect** unless manually set as Permanent below.</p>';
	}
	
	/**
	 * Renders the main settings page HTML and includes the logs table.
	 */
	function mabble_render_404_settings_page() {
		?>
		<div class="wrap">
			<h1>Mabble Custom 404 Settings & Logs</h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( MABBLE_404_SETTINGS_SLUG ); 
				do_settings_sections( MABBLE_404_SETTINGS_SLUG );
				submit_button( 'Save Default Redirection Settings' );
				?>
			</form>

			<h2>404 Error Log Tracker</h2>
			<?php mabble_render_404_log_table(); ?>
			
		</div>
		<?php
	}

	/**
	 * Processes the action to convert a 404 URL to a permanent 301 redirect OR delete a 301 rule.
	 */
	function mabble_process_301_action() {
		// Only run if we are on the correct page.
		if ( ! is_admin() || ! isset( $_GET['page'] ) || $_GET['page'] !== MABBLE_404_SETTINGS_SLUG ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_GET['mabble-404-action'] ) ? sanitize_text_field( $_GET['mabble-404-action'] ) : '';
		
		// Return if no action is being processed
		if ( empty( $action ) ) {
			return;
		}

		// Base URL excludes the action and nonce parameters for the final redirect
		$redirect_url = remove_query_arg( array( 'mabble-404-action', 'log_id', 'url_key', 'target_page_id', '_wpnonce' ), $_SERVER['REQUEST_URI'] );
		$success_message = '';
		
		// --- Process CONVERT to 301 ---
		if ( $action === 'convert-301' ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mabble-301-convert' ) ) {
				wp_die( 'Security check failed.' );
			}

			$url_id = absint( $_GET['log_id'] );
			// Get the target page ID
			$target_page_id = absint( $_GET['target_page_id'] ); 
			
			if ( $url_id === 0 || $target_page_id === 0 ) {
				// Return an error if the page wasn't selected
				$redirect_url = add_query_arg( 'mabble-301-error', urlencode( 'Error: You must select a valid target page for the 301 redirect.' ), $redirect_url );
				wp_redirect( $redirect_url );
				exit;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . MABBLE_404_LOG_TABLE;
			$log = $wpdb->get_row( $wpdb->prepare( "SELECT requested_url FROM $table_name WHERE id = %d", $url_id ) );

			if ( $log ) {
				// Redirects are stored as an associative array: [source_url => target_id]
				$permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );
				
				// Check if this source URL is already mapped
				if ( ! isset( $permanent_urls[ $log->requested_url ] ) ) { 
					$permanent_urls[ $log->requested_url ] = $target_page_id; // Store the URL => ID mapping
					update_option( MABBLE_301_URLS_KEY, $permanent_urls );
					
					// Delete the entry from the 404 log now that it's a permanent redirect rule
					$wpdb->delete( $table_name, array( 'id' => $url_id ), array( '%d' ) );
					
					// Get the page title for a better success message
					$page_title = get_the_title( $target_page_id );
					$success_message = 'The URL has been successfully set as a **301 Permanent Redirect** to the page: **' . esc_html($page_title) . '**.';
				}
			}
		} 
		// --- Process DELETE 301 Rule ---
		else if ( $action === 'delete-301' ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mabble-301-delete' ) ) {
				wp_die( 'Security check failed.' );
			}

			// We are deleting by the URL string key, not a numeric index.
			$url_key = sanitize_text_field( $_GET['url_key'] ); 

			$permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );

			if ( isset( $permanent_urls[$url_key] ) ) {
				$deleted_url = $url_key; // The key is the URL itself
				unset( $permanent_urls[$url_key] );
				update_option( MABBLE_301_URLS_KEY, $permanent_urls );
				$success_message = "The 301 redirect rule for `{$deleted_url}` has been deleted.";
			}
		}

		// Redirect and show notice if a successful action was performed
		if ( $success_message ) {
			// Encode the success message and append it to the redirect URL
			$redirect_url = add_query_arg( 'mabble-301-success', urlencode( $success_message ), $redirect_url );
			// Perform redirect and exit
			wp_redirect( $redirect_url );
			exit; // CRUCIAL: Stops script execution after successful redirect
		}

		// Show success/error notice from previous redirect
		if ( isset( $_GET['mabble-301-success'] ) ) {
			add_action( 'admin_notices', function() {
				// Ensure the message is safely decoded and sanitized before output
				$msg = urldecode( sanitize_text_field( $_GET['mabble-301-success'] ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
			});
		}
		if ( isset( $_GET['mabble-301-error'] ) ) {
			add_action( 'admin_notices', function() {
				// Ensure the message is safely decoded and sanitized before output
				$msg = urldecode( sanitize_text_field( $_GET['mabble-301-error'] ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
			});
		}
	}
}

// -----------------------------------------------------------
// C. CORE REDIRECTION AND LOGGING LOGIC
// -----------------------------------------------------------

/**
 * Tracks the 404 error and performs a redirect if a page is selected.
 * * NOTE: Logging logic is fixed to ensure 404s are only logged once.
 */
function mabble_custom_404_redirection_and_log() {
	
	// 1. Check if the module is active AND if it's a 404 error
	$modules = get_option( 'aaee_modules' );
	$is_module_active = ! empty( $modules['custom_404_page'] );
	
	if ( ! $is_module_active || ! is_404() ) {
		return;
	}

	$default_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
	$requested_url = esc_url_raw( $_SERVER['REQUEST_URI'] );
	$permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );

	$redirect_url = false;
	$redirect_status = 302; // Default status is 302 (temporary)
	
	// 2. CHECK FOR SPECIFIC PERMANENT (301) REDIRECT RULE FIRST
	if ( isset( $permanent_urls[ $requested_url ] ) ) {
		$target_id = absint( $permanent_urls[ $requested_url ] );
		
		if ( $target_id > 0 ) {
			$redirect_url = get_permalink( $target_id );
			$redirect_status = 301;
			// No logging for 301 rules
		}
	} 
	
	// 3. LOGGING: If it wasn't a 301 redirect, log it as a 404 error.
	if ( $redirect_status !== 301 ) {
		 mabble_log_404_error( $requested_url );
	}

	// 4. IF NO SPECIFIC 301 RULE, CHECK FOR DEFAULT 404 REDIRECT (302)
	if ( ! $redirect_url && $default_404_id > 0 ) {
		$redirect_url = get_permalink( $default_404_id );
		// Status remains 302 (temporary) since this is the general 404 handler
	}

	// 5. PERFORM REDIRECT
	if ( $redirect_url ) {
		wp_redirect( $redirect_url, $redirect_status ); 
		exit;
	}
}
add_action( 'template_redirect', 'mabble_custom_404_redirection_and_log', 1 );

/**
 * Central function to handle 404 logging.
 */
function mabble_log_404_error( $requested_url ) {
	global $wpdb;
	$table_name = $wpdb->prefix . MABBLE_404_LOG_TABLE;

	if ( $requested_url ) {
		$existing_log = $wpdb->get_row( 
			$wpdb->prepare( "SELECT id, request_count FROM $table_name WHERE requested_url = %s", $requested_url )
		);

		if ( $existing_log ) {
			// Update existing entry
			$wpdb->update(
				$table_name,
				array( 
					'request_count' => $existing_log->request_count + 1,
					'last_hit' => current_time( 'mysql' ),
				),
				array( 'id' => $existing_log->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert new entry
			$wpdb->insert(
				$table_name,
				array(
					'requested_url' => $requested_url,
					'request_count' => 1,
					'last_hit' => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%s' )
			);
		}
	}
}


// -----------------------------------------------------------
// D. LOGS TABLE DISPLAY
// -----------------------------------------------------------

/**
 * Renders the table displaying the 404 logs.
 */
function mabble_render_404_log_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . MABBLE_404_LOG_TABLE;

	// --- Pagination Setup ---
	$per_page = 20;
	// Removed all action/message/sort/page args for clean base URL
	$base_url = remove_query_arg( array('paged', 'orderby', 'order', 'mabble-301-success', 'mabble-301-error', 'target_page_id', 'mabble-404-action', 'log_id', 'url_key', '_wpnonce'), $_SERVER['REQUEST_URI'] ); 
	
	$current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
	$offset = ( $current_page - 1 ) * $per_page;
	$total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );
	$total_pages = ceil( $total_items / $per_page );

	// --- Sorting Setup ---
	$orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'requested_url', 'request_count', 'last_hit' ) ) ? $_GET['orderby'] : 'request_count';
	$order = isset( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ) ) ? $_GET['order'] : 'DESC';

	// --- Fetch Data ---
	$logs = $wpdb->get_results( 
		"SELECT * FROM $table_name ORDER BY $orderby $order LIMIT $per_page OFFSET $offset" 
	);
	
	// Fetch permanent redirect list for comparison
	$permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );
	
	?>
	<style>
		.mabble-404-log-table { width: 100%; border-collapse: collapse; }
		.mabble-404-log-table th, .mabble-404-log-table td { padding: 8px 10px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
		.mabble-404-log-table th { background-color: #f3f3f3; }
		.mabble-404-log-table a.sortable { text-decoration: none; display: block; }
		.mabble-redirect-select { min-width: 150px; } 
	</style>
	
	<h3>Permanent Redirects (301)</h3>
	<p>The following URLs are currently redirecting permanently (301) to specific pages:</p>
	
	<table class="mabble-404-log-table widefat fixed">
		<thead>
			<tr>
				<th width="60%">Source URL</th>
				<th width="20%">Target Page</th>
				<th width="20%">Action</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $permanent_urls ) ) : ?>
				<?php foreach ( $permanent_urls as $url => $target_id ) : ?>
					<tr>
						<td><code><?php echo esc_html( $url ); ?></code></td>
						<td>
							<?php 
								$target_title = get_the_title( $target_id );
								$target_link = get_edit_post_link( $target_id );
								if ( $target_link && $target_title) {
									echo '<a href="' . esc_url( $target_link ) . '" target="_blank">' . esc_html( $target_title ) . '</a>';
								} else {
									echo 'Page ID: ' . absint( $target_id ) . ' (Not Found)';
								}
							?>
						</td>
						<td>
							<?php 
								// The key for deletion is the URL string itself, encoded
								$delete_url = add_query_arg( array(
									'mabble-404-action' => 'delete-301',
									'url_key' => urlencode( $url ), // Encode the URL key for the GET parameter
									'_wpnonce' => wp_create_nonce( 'mabble-301-delete' )
								), $base_url );
							?>
							<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-secondary button-small" 
								onclick="return confirm('Are you sure you want to delete this permanent (301) redirect rule?')">
								Delete Rule
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else: ?>
				<tr>
					<td colspan="3">No permanent (301) redirect rules are currently active.</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	
	<hr>
	
	<h3>Temporary Redirects (302) & Logged 404s</h3>

	<table class="mabble-404-log-table widefat fixed">
		<thead>
			<tr>
				<th width="30%">
					<?php 
						$url_order = ( $orderby === 'requested_url' && $order === 'ASC' ) ? 'DESC' : 'ASC';
						$url = add_query_arg( array( 'orderby' => 'requested_url', 'order' => $url_order, 'paged' => $current_page ), $base_url );
					?>
					<a class="sortable" href="<?php echo esc_url( $url ); ?>">
						Requested URL
						<?php if ( $orderby === 'requested_url' ) echo ( $order === 'ASC' ? '▲' : '▼' ); ?>
					</a>
				</th>
				<th width="10%">
					<?php 
						$count_order = ( $orderby === 'request_count' && $order === 'DESC' ) ? 'ASC' : 'DESC';
						$url = add_query_arg( array( 'orderby' => 'request_count', 'order' => $count_order, 'paged' => $current_page ), $base_url );
					?>
					<a class="sortable" href="<?php echo esc_url( $url ); ?>">
						404 Count
						<?php if ( $orderby === 'request_count' ) echo ( $order === 'ASC' ? '▲' : '▼' ); ?>
					</a>
				</th>
				<th width="15%">
					<?php 
						$last_order = ( $orderby === 'last_hit' && $order === 'DESC' ) ? 'ASC' : 'DESC';
						$url = add_query_arg( array( 'orderby' => 'last_hit', 'order' => $last_order, 'paged' => $current_page ), $base_url );
					?>
					<a class="sortable" href="<?php echo esc_url( $url ); ?>">
						Last Seen
						<?php if ( $orderby === 'last_hit' ) echo ( $order === 'ASC' ? '▲' : '▼' ); ?>
					</a>
				</th>
				<th width="45%" colspan="2">Redirect Action</th> 
			</tr>
		</thead>
		<tbody>
			<?php if ( $logs ) : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( site_url( $log->requested_url ) ); ?>" target="_blank">
								<?php echo esc_html( $log->requested_url ); ?>
							</a>
						</td>
						<td><?php echo absint( $log->request_count ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->last_hit ) ) ); ?></td>
						
						<td class="actions" colspan="2">
							<form method="get" action="<?php echo esc_url( $base_url ); ?>" style="display: flex; gap: 5px; align-items: center;">
								<input type="hidden" name="page" value="<?php echo MABBLE_404_SETTINGS_SLUG; ?>">
								<input type="hidden" name="mabble-404-action" value="convert-301">
								<input type="hidden" name="log_id" value="<?php echo absint( $log->id ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce( 'mabble-301-convert' ); ?>">

								<label for="target_page_<?php echo absint( $log->id ); ?>">Redirect to:</label>
								<?php 
									wp_dropdown_pages( array(
										'selected' 			=> 0,
										'name' 				=> 'target_page_id', 
										'id' 				=> 'target_page_' . absint( $log->id ),
										'show_option_none' 	=> '— Select Target Page —',
										'option_none_value' => '0',
										'echo' 				=> 1,
										'post_status' 		=> array('publish', 'private'),
										'hierarchical' 		=> 0,
										'sort_column' 		=> 'post_title',
										'class' 			=> 'mabble-redirect-select'
									) );
								?>
								<button type="submit" class="button button-primary button-small" 
									onclick="return (document.getElementById('target_page_<?php echo absint( $log->id ); ?>').value != '0') && confirm('WARNING: This will set a PERMANENT (301) redirect for this URL. Continue?')">
									Set 301 Permanent
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="5">No 404 errors have been logged yet.</td> 
				</tr>
			<?php endif; ?>
		</tbody>
		<tfoot>
			<tr>
				<th>Requested URL</th>
				<th>404 Count</th>
				<th>Last Seen</th>
				<th colspan="2">Redirect Action</th> 
			</tr>
		</tfoot>
	</table>
	
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php
			$page_links = paginate_links( array(
				'base' => add_query_arg( 'paged', '%#%', $base_url ),
				'format' => '&paged=%#%',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total' => $total_pages,
				'current' => $current_page,
			) );
			echo $page_links;
			?>
		</div>
	</div>
	<?php
}