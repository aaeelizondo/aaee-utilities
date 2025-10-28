<?php
// Module: Custom 404 Page (ID: custom_404_page)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'MABBLE_404_OPTION_KEY', 'mabble_custom_404_page_id' );
// Update: 301 URLs will now store an array: [source_url => ['type' => 'internal|external', 'target' => 'ID|URL']]
define( 'MABBLE_301_URLS_KEY', 'mabble_301_redirect_urls' ); 
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
            'Mabble 404 Logs',// Menu Title (Cleaned line)
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
     * Sanitizes the 301 redirect list. It now handles array values for internal/external.
     */
    function mabble_sanitize_301_urls( $input ) {
        $sanitized = array();
        if ( is_array( $input ) ) {
            foreach ( $input as $url => $rule_data ) {
                // Key must be a sanitized URL string
                $sanitized_url = esc_url_raw( $url );
                
                if ( ! is_array( $rule_data ) || ! isset( $rule_data['type'], $rule_data['target'] ) ) {
                    continue; // Skip malformed data
                }

                $type = sanitize_text_field( $rule_data['type'] );
                $target = $rule_data['target']; // Target can be a string (URL) or int (ID)

                if ( $sanitized_url ) {
                    if ( $type === 'internal' ) {
                        $sanitized_target = absint( $target );
                        if ( $sanitized_target > 0 ) {
                            $sanitized[ $sanitized_url ] = array('type' => 'internal', 'target' => $sanitized_target);
                        }
                    } elseif ( $type === 'external' ) {
                        // Target must be a fully qualified URL for external redirects
                        $sanitized_target = esc_url_raw( $target ); 
                        if ( $sanitized_target ) {
                            $sanitized[ $sanitized_url ] = array('type' => 'external', 'target' => $sanitized_target);
                        }
                    }
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
            'selected' => $current_page_id,
            'name' => MABBLE_404_OPTION_KEY,
            'show_option_none' => '— Do not Redirect 404s —',
            'option_none_value' => '0',
            'echo' => 1,
            'post_status' => array('publish', 'private'),
            'hierarchical' => 0,
            'sort_column' => 'post_title',
        ) );
        
        echo '<p class="description">**IMPORTANT:** This sets the default target. The general redirect is a **302 Temporary Redirect** unless manually set as Permanent below.</p>';
    }
    
    /**
     * Renders the main settings page HTML and includes the logs table.
     */
    function mabble_render_404_settings_page() {
        // Handle Edit Form Display
        $source_url_to_edit = '';
        $current_rule = [];
        if ( isset( $_GET['mabble-404-action'], $_GET['url_key'] ) && $_GET['mabble-404-action'] === 'edit-301' ) {
            $source_url_to_edit = sanitize_text_field( $_GET['url_key'] );
            $permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );
            if ( isset( $permanent_urls[ $source_url_to_edit ] ) ) {
                $current_rule = $permanent_urls[ $source_url_to_edit ];
            }
        }
        
        ?>
        <div class="wrap">
            <h1>Mabble Custom 404 Settings & Logs</h1>
            
            <?php if ( !empty( $source_url_to_edit ) ) : 
                // Determine if we're editing
                $is_editing = !empty($source_url_to_edit);
                $form_title = 'Edit Permanent Redirect Rule';
                $submit_label = 'Save Rule';
                $current_source_url = esc_url( $source_url_to_edit );
                $current_target_type = isset($current_rule['type']) ? $current_rule['type'] : 'internal';
                $current_target_value = isset($current_rule['target']) ? $current_rule['target'] : '';
                $back_url = remove_query_arg( array('mabble-404-action', 'url_key'), $_SERVER['REQUEST_URI'] );
            ?>
                <a href="<?php echo esc_url($back_url); ?>" class="button" style="margin-bottom: 20px;">&larr; Back to Logs</a>
                <h2><?php echo $form_title; ?></h2>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="mabble_save_301_rule">
                    <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce( 'mabble-save-301-rule' ); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( $back_url ); ?>">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="source_url">Source URL (404 Page)</label></th>
                            <td>
                                <input type="text" name="source_url" id="source_url" value="<?php echo $current_source_url; ?>" class="regular-text" readonly placeholder="/old-page-slug" />
                                <p class="description">The URL that is currently 404ing. Use a relative URL like `/broken-link` (without your domain). This field cannot be changed.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Target Type</label></th>
                            <td>
                                <label><input type="radio" name="target_type" value="internal" <?php checked( $current_target_type, 'internal' ); ?> onclick="document.getElementById('internal_target_wrapper').style.display='block'; document.getElementById('external_target_wrapper').style.display='none';"> Internal Page (Page ID)</label><br>
                                <label><input type="radio" name="target_type" value="external" <?php checked( $current_target_type, 'external' ); ?> onclick="document.getElementById('internal_target_wrapper').style.display='none'; document.getElementById('external_target_wrapper').style.display='block';"> External URL</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="target_page_id">Target Destination</label></th>
                            <td>
                                <div id="internal_target_wrapper" style="display: <?php echo ($current_target_type === 'internal') ? 'block' : 'none'; ?>;">
                                    <?php 
                                        wp_dropdown_pages( array(
                                            'selected' => ($current_target_type === 'internal' ? absint($current_target_value) : 0),
                                            'name' => 'target_page_id', 
                                            'id' => 'target_page_id',
                                            'show_option_none' => '— Select Internal Target Page —',
                                            'option_none_value' => '0',
                                            'echo' => 1,
                                            'post_status' => array('publish', 'private'),
                                            'class' => 'regular-text'
                                        ) );
                                    ?>
                                    <p class="description">Select a page on this site to redirect to.</p>
                                </div>
                                <div id="external_target_wrapper" style="display: <?php echo ($current_target_type === 'external') ? 'block' : 'none'; ?>;">
                                    <input type="url" name="target_external_url" id="target_external_url" value="<?php echo ($current_target_type === 'external' ? esc_url($current_target_value) : ''); ?>" class="regular-text" placeholder="https://example.com/new-url" />
                                    <p class="description">Enter a full, absolute URL for an external site.</p>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( $submit_label ); ?>
                </form>
            <?php else : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( MABBLE_404_SETTINGS_SLUG ); 
                    do_settings_sections( MABBLE_404_SETTINGS_SLUG );
                    submit_button( 'Save Default Redirection Settings' );
                    ?>
                </form>

                <h2>404 Error Log Tracker</h2>
                <?php mabble_render_404_log_table(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Handles the saving of an edited 301 rule from the dedicated form.
     */
    function mabble_save_301_rule_handler() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'mabble-save-301-rule' ) ) {
            wp_die( 'Security check failed.' );
        }

        $source_url = isset( $_POST['source_url'] ) ? sanitize_text_field( $_POST['source_url'] ) : '';
        $target_type = isset( $_POST['target_type'] ) ? sanitize_text_field( $_POST['target_type'] ) : 'internal';
        $redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( $_POST['redirect_to'] ) : admin_url( 'options-general.php?page=' . MABBLE_404_SETTINGS_SLUG );
        
        $target_value = null;

        if ( $target_type === 'internal' ) {
            $target_id = isset( $_POST['target_page_id'] ) ? absint( $_POST['target_page_id'] ) : 0;
            if ( $target_id > 0 ) {
                $target_value = $target_id;
            }
        } elseif ( $target_type === 'external' ) {
            $target_url = isset( $_POST['target_external_url'] ) ? esc_url_raw( $_POST['target_external_url'] ) : '';
            if ( $target_url ) {
                $target_value = $target_url;
            }
        }

        if ( empty( $source_url ) || empty( $target_value ) ) {
            $redirect_to = add_query_arg( 'mabble-301-error', urlencode( 'Error: Source URL or Target Destination is missing/invalid. Rule not saved.' ), $redirect_to );
            wp_redirect( $redirect_to );
            exit;
        }
        
        // Ensure source URL starts with / (relative path) for consistency
        $source_url = ( strpos( $source_url, '/' ) !== 0 ) ? '/' . $source_url : $source_url;

        $permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );
        $permanent_urls[ $source_url ] = array(
            'type' => $target_type, 
            'target' => $target_value
        );
        update_option( MABBLE_301_URLS_KEY, $permanent_urls );
        
        $success_message = 'The 301 redirect rule for `' . esc_html($source_url) . '` has been successfully saved.';

        $redirect_to = add_query_arg( 'mabble-301-success', urlencode( $success_message ), $redirect_to );
        
        wp_redirect( $redirect_to );
        exit;
    }
    add_action( 'admin_post_mabble_save_301_rule', 'mabble_save_301_rule_handler' );


    /**
     * Processes the action to convert a 404 URL to a permanent 301 redirect OR delete a 301 rule OR delete a log.
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

        // Base URL excludes only the action and nonce parameters for the final redirect.
        // This ensures sorting, pagination (paged, per_page, orderby, order) parameters persist.
        $redirect_url = remove_query_arg( 
            array( 'mabble-404-action', 'log_id', 'url_key', 'target_page_id', 'target_external_url', 'ignore_target_page_id', 'ignore_target_external_url', 'target_type', '_wpnonce' ), 
            $_SERVER['REQUEST_URI'] 
        );
        
        $success_message = '';
        global $wpdb;
        $table_name = $wpdb->prefix . MABBLE_404_LOG_TABLE;
        
        // --- Process CONVERT to 301 ---
        if ( $action === 'convert-301' ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mabble-301-convert' ) ) {
                wp_die( 'Security check failed.' );
            }

            $url_id = absint( $_GET['log_id'] );
            $target_type = sanitize_text_field( $_GET['target_type'] ); 
            
            $target_value = null;

            if ( $target_type === 'internal' ) {
                // target_page_id is only present if internal is selected
                $target_id = isset( $_GET['target_page_id'] ) ? absint( $_GET['target_page_id'] ) : 0; 
                if ( $target_id > 0 ) {
                    $target_value = $target_id;
                }
            } elseif ( $target_type === 'external' ) {
                // target_external_url is only present if external is selected
                // BUG FIX: Ensure we use the correct GET variable for external URL
                $target_url = isset( $_GET['target_external_url'] ) ? esc_url_raw( $_GET['target_external_url'] ) : ''; 
                if ( $target_url ) {
                    $target_value = $target_url;
                }
            }
            
            if ( $url_id === 0 || empty( $target_value ) ) {
                $error_msg = ($url_id === 0) ? 'Error: Invalid log ID.' : 'Error: You must select a valid target page or enter an external URL for the 301 redirect.';
                $redirect_url = add_query_arg( 'mabble-301-error', urlencode( $error_msg ), $redirect_url );
                wp_redirect( $redirect_url );
                exit;
            }

            $log = $wpdb->get_row( $wpdb->prepare( "SELECT requested_url FROM $table_name WHERE id = %d", $url_id ) );

            if ( $log ) {
                $permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );
                
                if ( ! isset( $permanent_urls[ $log->requested_url ] ) ) { 
                    // Store the URL => [type, target] mapping
                    $permanent_urls[ $log->requested_url ] = array('type' => $target_type, 'target' => $target_value); 
                    update_option( MABBLE_301_URLS_KEY, $permanent_urls );
                    
                    // Delete the entry from the 404 log
                    $wpdb->delete( $table_name, array( 'id' => $url_id ), array( '%d' ) );
                    
                    // Generate success message
                    $target_info = $target_type === 'internal' ? 'the page ID: ' . $target_value : 'the external URL: ' . $target_value;
                    $success_message = 'The URL has been successfully set as a **301 Permanent Redirect** to ' . esc_html($target_info) . '. It was removed from the 404 logs.';
                }
            }
        } 
        // --- Process DELETE 301 Rule ---
        else if ( $action === 'delete-301' ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mabble-301-delete' ) ) {
                wp_die( 'Security check failed.' );
            }

            $url_key = sanitize_text_field( $_GET['url_key'] ); 
            $permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );

            if ( isset( $permanent_urls[$url_key] ) ) {
                $deleted_url = $url_key; 
                unset( $permanent_urls[$url_key] );
                update_option( MABBLE_301_URLS_KEY, $permanent_urls );
                $success_message = "The 301 redirect rule for `{$deleted_url}` has been deleted.";
            }
        }
        // --- Process DELETE 404 Log Entry ---
        else if ( $action === 'delete-404-log' ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mabble-404-log-delete' ) ) {
                wp_die( 'Security check failed.' );
            }

            $url_id = absint( $_GET['log_id'] );
            $log = $wpdb->get_row( $wpdb->prepare( "SELECT requested_url FROM $table_name WHERE id = %d", $url_id ) );

            if ( $log ) {
                // Delete the entry from the 404 log
                $wpdb->delete( $table_name, array( 'id' => $url_id ), array( '%d' ) );
                $success_message = "The 404 log entry for `{$log->requested_url}` has been successfully deleted/ignored.";
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
        $rule = $permanent_urls[ $requested_url ];
        
        if ( $rule['type'] === 'internal' && absint( $rule['target'] ) > 0 ) {
            // Internal redirect
            $redirect_url = get_permalink( absint( $rule['target'] ) );
            $redirect_status = 301;
        } elseif ( $rule['type'] === 'external' && esc_url( $rule['target'] ) ) {
            // External redirect
            $redirect_url = esc_url( $rule['target'] );
            $redirect_status = 301;
        }
        
        // No logging for 301 rules
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
    // Available entries per page options
    $per_page_options = array( 10, 20, 50, 100 );
    // Get current per_page setting, default to 20
    $per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;

    // Ensure $per_page is a valid option (optional but good practice)
    if ( ! in_array( $per_page, $per_page_options ) ) {
        $per_page = 20;
    }

    // Removed all action/message/sort/page args for clean base URL. 'per_page' is deliberately kept for now.
    $base_url = remove_query_arg( array('paged', 'orderby', 'order', 'mabble-301-success', 'mabble-301-error', 'target_page_id', 'target_external_url', 'ignore_target_page_id', 'ignore_target_external_url', 'target_type', 'mabble-404-action', 'log_id', 'url_key', '_wpnonce'), $_SERVER['REQUEST_URI'] ); 
    
    // Add current sorting and per_page settings back to the base for clean links
    if ( isset( $_GET['orderby'] ) ) {
        $base_url = add_query_arg( 'orderby', sanitize_text_field($_GET['orderby']), $base_url );
    }
    if ( isset( $_GET['order'] ) ) {
        $base_url = add_query_arg( 'order', sanitize_text_field($_GET['order']), $base_url );
    }
    $base_url = add_query_arg( 'per_page', $per_page, $base_url );


    $current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
    $offset = ( $current_page - 1 ) * $per_page;
    $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );
    $total_pages = ceil( $total_items / $per_page );

    // --- Sorting Setup ---
    $orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'requested_url', 'request_count', 'last_hit' ) ) ? $_GET['orderby'] : 'request_count';
    $order = isset( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ) ) ? $_GET['order'] : 'DESC';
    
    // Define the opposite order for sort links
    $opposite_order = ($order === 'DESC') ? 'ASC' : 'DESC';

    // Function to create a sort link
    $get_sort_link = function( $column, $label ) use ( $orderby, $order, $opposite_order, $base_url ) {
        $new_order = ($orderby === $column) ? $opposite_order : 'DESC';
        $current_indicator = ($orderby === $column) ? ($order === 'DESC' ? ' <span class="dashicons dashicons-arrow-down-alt"></span>' : ' <span class="dashicons dashicons-arrow-up-alt"></span>') : '';
        $sort_url = add_query_arg( array( 'orderby' => $column, 'order' => $new_order ), $base_url );
        return '<a class="sortable" href="' . esc_url( $sort_url ) . '">' . esc_html( $label ) . $current_indicator . '</a>';
    };


    // --- Fetch Data ---
    // Safely insert $orderby and $order since they were already sanitized above,
    // and use %d for the LIMIT/OFFSET integers only.
    $logs = $wpdb->get_results( 
        $wpdb->prepare( 
            "SELECT * FROM $table_name ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", 
            $per_page, $offset 
        )
    );
    
    // Fetch permanent redirect list for comparison
    $permanent_urls = get_option( MABBLE_301_URLS_KEY, array() );
    
    // Helper JS for the inline form to switch between internal/external
    ?>
    <script>
    function mabbleSwitchTarget(logId) {
        var type = document.getElementById('target_type_' + logId).value;
        
        // Use document.getElementById to target the correct input fields for submission
        // Change the 'name' attribute based on the selected type. The one *without* 'ignore_' will be submitted.
        
        // Set internal input name:
        document.getElementById('target_page_id_' + logId).name = (type === 'internal' ? 'target_page_id' : 'ignore_target_page_id');
        
        // Set external input name:
        document.getElementById('target_external_url_' + logId).name = (type === 'external' ? 'target_external_url' : 'ignore_target_external_url');

        // Toggle visibility
        document.getElementById('internal_target_wrapper_' + logId).style.display = (type === 'internal' ? 'block' : 'none');
        document.getElementById('external_target_wrapper_' + logId).style.display = (type === 'external' ? 'block' : 'none');
    }
    
    // Run on page load to set initial state/names
    document.addEventListener('DOMContentLoaded', function() {
        var selects = document.querySelectorAll('select[id^="target_type_"]');
        selects.forEach(function(select) {
            var logId = select.id.replace('target_type_', '');
            mabbleSwitchTarget(logId);
        });
    });
    </script>
    <style>
        .mabble-404-log-table { width: 100%; border-collapse: collapse; }
        .mabble-404-log-table th, .mabble-404-log-table td { padding: 8px 10px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        .mabble-404-log-table th { 
            background-color: #f3f3f3; 
            font-weight: bold; /* Added: Bold table headers */
        }
        .mabble-404-log-table a.sortable { text-decoration: none; display: block; }
        /* Wrapper for all elements in the actions cell to keep them inline */
        .mabble-log-row-actions {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            flex-wrap: wrap;
        }
        .mabble-log-action-bar { 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            flex-wrap: wrap; 
        } 
        .mabble-redirect-select { min-width: 150px; } 

        /* --------------------------------- */
        /* 404 LOG PAGINATION STYLES (UPDATED) */
        /* --------------------------------- */
        .mabble-404-pagination-wrapper { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .mabble-pagination-controls .button {
            border: 1px solid #ccc;
            padding: 5px 10px;
            text-decoration: none;
            color: #555;
            background: #f9f9f9;
            line-height: 1;
            display: inline-block;
            margin-left: 5px;
            box-shadow: 0 1px 0 rgba(0,0,0,.08);
            border-radius: 3px;
        }
        .mabble-pagination-controls .button:hover {
            background: #fff;
            border-color: #999;
        }
        .mabble-pagination-controls .disabled {
            opacity: 0.5;
            cursor: default;
        }
        /* Style for current page button */
        .mabble-pagination-controls .current {
            background: #e3e3e3;
            border-color: #999;
            font-weight: bold;
        }
        .mabble-pagination-controls .paging-input {
            padding: 0 5px;
            margin-left: 5px;
        }
    </style>
    
    <h3>Permanent Redirects (301)</h3>
    <p>The following URLs are currently redirecting permanently (301) to specific destinations:</p>
    
    <table class="mabble-404-log-table widefat fixed">
        <thead>
            <tr>
                <th width="50%">Source URL</th>
                <th width="30%">Target Destination</th>
                <th width="20%">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $permanent_urls ) ) : ?>
                <?php foreach ( $permanent_urls as $url => $rule ) : 
                    $target_display = '';
                    $target_value = $rule['target'];
                    if ( $rule['type'] === 'internal' ) {
                        $target_title = get_the_title( absint( $target_value ) );
                        $target_link = get_edit_post_link( absint( $target_value ) );
                        if ( $target_link && $target_title) {
                            $target_display = 'Internal: <a href="' . esc_url( $target_link ) . '" target="_blank">' . esc_html( $target_title ) . '</a>';
                        } else {
                            $target_display = 'Internal: Page ID: ' . absint( $target_value ) . ' (Not Found)';
                        }
                    } elseif ( $rule['type'] === 'external' ) {
                        $target_display = 'External: <a href="' . esc_url( $target_value ) . '" target="_blank">' . esc_html( $target_value ) . '</a>';
                    }
                ?>
                    <tr>
                        <td><code><?php echo esc_html( $url ); ?></code></td>
                        <td><?php echo wp_kses_post( $target_display ); ?></td>
                        <td>
                            <div class="mabble-log-action-bar">
                            <?php 
                                // EDIT URL
                                $edit_url = add_query_arg( array(
                                    'mabble-404-action' => 'edit-301',
                                    'url_key' => urlencode( $url ), 
                                ), $base_url );
                                
                                // DELETE URL
                                $delete_url = add_query_arg( array(
                                    'mabble-404-action' => 'delete-301',
                                    'url_key' => urlencode( $url ), 
                                    '_wpnonce' => wp_create_nonce( 'mabble-301-delete' )
                                ), $base_url );
                            ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-primary">Edit</a>
                            <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-secondary" onclick="return confirm('Are you sure you want to permanently delete this 301 redirect rule?');">Delete</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="3">No permanent 301 redirect rules have been set yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <hr>
    
    <h3>404 Error Log Tracker (Last <?php echo esc_html($per_page); ?> Entries)</h3>
    <p>These URLs are currently resulting in 404 errors. Use the inline form to convert them to permanent 301 redirects.</p>
    
    <div class="mabble-404-pagination-wrapper">

        <div class="mabble-items-per-page">
            <label for="mabble_per_page">Show entries per screen:</label>
            <select id="mabble_per_page" onchange="window.location.href = this.value;">
                <?php 
                foreach ( $per_page_options as $option ) {
                    // Create a clean URL for the selected per_page option, resetting to page 1
                    $option_url = add_query_arg( 'per_page', $option, remove_query_arg( 'paged', $base_url ) );
                    $selected = selected( $per_page, $option, false );
                    echo '<option value="' . esc_url( $option_url ) . '" ' . $selected . '>' . esc_html( $option ) . '</option>';
                }
                ?>
            </select>
        </div>
        
        <div class="mabble-pagination-controls">
            <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
            <?php 
            
            // --- Pagination Links Generation ---
            $range = 2; // Show 2 pages before and after current
            $start_page = max( 1, $current_page - $range );
            $end_page = min( $total_pages, $current_page + $range );

            // Previous Page Button
            $prev_page = max( 1, $current_page - 1 );
            $prev_url = add_query_arg( 'paged', $prev_page, $base_url );
            $prev_class = ($current_page <= 1) ? 'disabled' : '';
            echo '<a href="' . esc_url( $prev_url ) . '" class="button ' . esc_attr($prev_class) . '">&laquo;</a>';

            // Show first page link
            if ( $start_page > 1 ) {
                $first_url = add_query_arg( 'paged', 1, $base_url );
                echo '<a href="' . esc_url( $first_url ) . '" class="button">1</a>';
                if ( $start_page > 2 ) {
                    echo '<span class="paging-input">...</span>';
                }
            }

            // Show page numbers within the range
            for ( $i = $start_page; $i <= $end_page; $i++ ) {
                $page_url = add_query_arg( 'paged', $i, $base_url );
                $class = ( $i === $current_page ) ? 'current' : '';
                echo '<a href="' . esc_url( $page_url ) . '" class="button ' . esc_attr($class) . '">' . esc_html( $i ) . '</a>';
            }

            // Show last page link
            if ( $end_page < $total_pages ) {
                if ( $end_page < $total_pages - 1 ) {
                    echo '<span class="paging-input">...</span>';
                }
                $last_url = add_query_arg( 'paged', $total_pages, $base_url );
                echo '<a href="' . esc_url( $last_url ) . '" class="button">' . esc_html( $total_pages ) . '</a>';
            }
            
            // Next Page Button
            $next_page = min( $total_pages, $current_page + 1 );
            $next_url = add_query_arg( 'paged', $next_page, $base_url );
            $next_class = ($current_page >= $total_pages) ? 'disabled' : '';
            echo '<a href="' . esc_url( $next_url ) . '" class="button ' . esc_attr($next_class) . '">&raquo;</a>';
            ?>
        </div>
    </div>

    <table class="mabble-404-log-table widefat fixed">
        <thead>
            <tr>
                <th width="40%"><?php echo $get_sort_link( 'requested_url', 'Requested URL' ); ?></th>
                <th width="10%"><?php echo $get_sort_link( 'request_count', 'Hits' ); ?></th>
                <th width="15%"><?php echo $get_sort_link( 'last_hit', 'Last Hit' ); ?></th>
                <th width="35%">Convert to 301 Redirect or Delete</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $logs ) ) : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td>
                            <code><?php echo esc_html( $log->requested_url ); ?></code>
                            <br><small><a href="<?php echo esc_url( home_url( $log->requested_url ) ); ?>" target="_blank">Test Link &rarr;</a></small>
                        </td>
                        <td><?php echo absint( $log->request_count ); ?></td>
                        <td><?php echo esc_html( get_date_from_gmt( $log->last_hit, 'Y/m/d H:i' ) ); ?></td>
                        <td>
                            <?php 
                            // Prepare base URLs for actions
                            $convert_url = add_query_arg( 
                                array(
                                    'mabble-404-action' => 'convert-301',
                                    'log_id' => absint( $log->id ),
                                    '_wpnonce' => wp_create_nonce( 'mabble-301-convert' )
                                ), 
                                $base_url 
                            );
                            
                            $delete_log_url = add_query_arg( 
                                array(
                                    'mabble-404-action' => 'delete-404-log',
                                    'log_id' => absint( $log->id ),
                                    '_wpnonce' => wp_create_nonce( 'mabble-404-log-delete' )
                                ), 
                                $base_url 
                            );

                            ?>
                            <div class="mabble-log-row-actions">
                                <form action="<?php echo esc_url( $convert_url ); ?>" method="get" class="mabble-log-action-bar">
                                    <input type="hidden" name="page" value="<?php echo esc_attr( MABBLE_404_SETTINGS_SLUG ); ?>">
                                    <input type="hidden" name="mabble-404-action" value="convert-301">
                                    <input type="hidden" name="log_id" value="<?php echo absint( $log->id ); ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce( 'mabble-301-convert' ); ?>">

                                    <select id="target_type_<?php echo absint( $log->id ); ?>" name="target_type" onchange="mabbleSwitchTarget(<?php echo absint( $log->id ); ?>)" class="mabble-redirect-select">
                                        <option value="internal">Redirect to Internal Page</option>
                                        <option value="external">Redirect to External URL</option>
                                    </select>
                                    
                                    <div id="internal_target_wrapper_<?php echo absint( $log->id ); ?>" style="display: block;">
                                        <?php 
                                            // This uses the 'name' attribute which will be overwritten by JS
                                            wp_dropdown_pages( array(
                                                'selected' => 0,
                                                'name' => 'target_page_id', 
                                                'id' => 'target_page_id_' . absint( $log->id ),
                                                'show_option_none' => 'Select Page...',
                                                'option_none_value' => '0',
                                                'echo' => 1,
                                                'post_status' => array('publish', 'private'),
                                                'class' => 'mabble-redirect-select'
                                            ) );
                                        ?>
                                    </div>
                                    <div id="external_target_wrapper_<?php echo absint( $log->id ); ?>" style="display: none;">
                                        <input type="url" name="ignore_target_external_url" id="target_external_url_<?php echo absint( $log->id ); ?>" value="" placeholder="https://external.com" class="regular-text" style="width: 180px;" />
                                    </div>
                                    
                                    <input type="submit" value="Convert to 301" class="button button-primary" onclick="return confirm('Are you sure you want to convert this 404 log entry to a permanent 301 redirect and delete the log?');">
                                </form>
                                <a href="<?php echo esc_url( $delete_log_url ); ?>" class="button button-secondary" onclick="return confirm('Are you sure you want to delete this 404 log entry (ignore)?');">Delete Log Entry</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="4">No 404 errors have been logged yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="mabble-404-pagination-wrapper">

        <div class="mabble-items-per-page">
            <label for="mabble_per_page_bottom">Show entries per screen:</label>
            <select id="mabble_per_page_bottom" onchange="window.location.href = this.value;">
                <?php 
                foreach ( $per_page_options as $option ) {
                    $option_url = add_query_arg( 'per_page', $option, remove_query_arg( 'paged', $base_url ) );
                    $selected = selected( $per_page, $option, false );
                    echo '<option value="' . esc_url( $option_url ) . '" ' . $selected . '>' . esc_html( $option ) . '</option>';
                }
                ?>
            </select>
        </div>
        
        <div class="mabble-pagination-controls">
            <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
            <?php 
            
            // --- Pagination Links Generation ---
            $range = 2; // Show 2 pages before and after current
            $start_page = max( 1, $current_page - $range );
            $end_page = min( $total_pages, $current_page + $range );

            // Previous Page Button
            $prev_page = max( 1, $current_page - 1 );
            $prev_url = add_query_arg( 'paged', $prev_page, $base_url );
            $prev_class = ($current_page <= 1) ? 'disabled' : '';
            echo '<a href="' . esc_url( $prev_url ) . '" class="button ' . esc_attr($prev_class) . '">&laquo;</a>';

            // Show first page link
            if ( $start_page > 1 ) {
                $first_url = add_query_arg( 'paged', 1, $base_url );
                echo '<a href="' . esc_url( $first_url ) . '" class="button">1</a>';
                if ( $start_page > 2 ) {
                    echo '<span class="paging-input">...</span>';
                }
            }

            // Show page numbers within the range
            for ( $i = $start_page; $i <= $end_page; $i++ ) {
                $page_url = add_query_arg( 'paged', $i, $base_url );
                $class = ( $i === $current_page ) ? 'current' : '';
                echo '<a href="' . esc_url( $page_url ) . '" class="button ' . esc_attr($class) . '">' . esc_html( $i ) . '</a>';
            }

            // Show last page link
            if ( $end_page < $total_pages ) {
                if ( $end_page < $total_pages - 1 ) {
                    echo '<span class="paging-input">...</span>';
                }
                $last_url = add_query_arg( 'paged', $total_pages, $base_url );
                echo '<a href="' . esc_url( $last_url ) . '" class="button">' . esc_html( $total_pages ) . '</a>';
            }
            
            // Next Page Button
            $next_page = min( $total_pages, $current_page + 1 );
            $next_url = add_query_arg( 'paged', $next_page, $base_url );
            $next_class = ($current_page >= $total_pages) ? 'disabled' : '';
            echo '<a href="' . esc_url( $next_url ) . '" class="button ' . esc_attr($next_class) . '">&raquo;</a>';
            ?>
        </div>
    </div>
    
    <?php
}