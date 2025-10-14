<?php
// Module: Custom 404 Page (ID: custom_404_page)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'MABBLE_404_OPTION_KEY', 'mabble_custom_404_page_id' );
define( 'MABBLE_404_LOG_TABLE', 'mabble_404_logs' );
// Use a unique slug for the standalone page under Settings
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
            'Mabble 404 Logs',                   // Menu Title
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

    /**
     * Renders the section header text.
     */
    function mabble_404_setup_section_callback() {
        echo '<p>Select the page that 404 errors should redirect to. The original URL will be logged for tracking purposes.</p>';
    }

    /**
     * Renders the page dropdown selection field.
     */
    function mabble_render_404_page_dropdown() {
        $current_page_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
        
        wp_dropdown_pages( array(
            'selected'          => $current_page_id,
            'name'              => MABBLE_404_OPTION_KEY,
            'show_option_none'  => '— Do not Redirect 404s —',
            'option_none_value' => '0',
            'echo'              => 1,
            'post_status'       => array('publish', 'private'),
            'hierarchical'      => 0,
            'sort_column'       => 'post_title',
        ) );
        
        echo '<p class="description">**IMPORTANT:** Selecting a page here will result in a **302 Temporary Redirect** to this page when a 404 occurs. For a standard 404 status (no redirect), select "Do not Redirect 404s".</p>';
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
                // Use the correct settings group slug for the settings fields
                settings_fields( MABBLE_404_SETTINGS_SLUG ); 
                do_settings_sections( MABBLE_404_SETTINGS_SLUG );
                submit_button( 'Save Redirection Settings' );
                ?>
            </form>

            <h2>404 Error Log Tracker</h2>
            <?php mabble_render_404_log_table(); ?>
            
        </div>
        <?php
    }
}

// -----------------------------------------------------------
// C. CORE REDIRECTION AND LOGGING LOGIC
// -----------------------------------------------------------

/**
 * Tracks the 404 error and performs a redirect if a page is selected.
 */
function mabble_custom_404_redirection_and_log() {
    global $wpdb;
    
    // 1. Check if the module is active AND if it's a 404 error
    $modules = get_option( 'aaee_modules' );
    $is_module_active = ! empty( $modules['custom_404_page'] );
    
    if ( ! $is_module_active || ! is_404() ) {
        return;
    }

    $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
    $requested_url = esc_url_raw( $_SERVER['REQUEST_URI'] );
    $table_name = $wpdb->prefix . MABBLE_404_LOG_TABLE;
    
    // --- LOGGING ---
    if ( $requested_url ) {
        // Find existing log entry
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

    // --- REDIRECTION ---
    if ( $custom_404_id > 0 ) {
        $redirect_url = get_permalink( $custom_404_id );
        
        if ( $redirect_url ) {
            // Use 302 Temporary Redirect to signal the browser/search engine that the original page is still missing
            wp_redirect( $redirect_url, 302 ); 
            exit;
        }
    }
}
add_action( 'template_redirect', 'mabble_custom_404_redirection_and_log', 1 );


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
    // Base URL is the current admin page URL
    $base_url = remove_query_arg( array('paged', 'orderby', 'order'), $_SERVER['REQUEST_URI'] ); 
    
    $current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
    $offset = ( $current_page - 1 ) * $per_page;
    $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );
    $total_pages = ceil( $total_items / $per_page );

    // --- Sorting Setup (Order by count DESC by default) ---
    $orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'requested_url', 'request_count', 'last_hit' ) ) ? $_GET['orderby'] : 'request_count';
    $order = isset( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ) ) ? $_GET['order'] : 'DESC';

    // --- Fetch Data ---
    $logs = $wpdb->get_results( 
        "SELECT * FROM $table_name ORDER BY $orderby $order LIMIT $per_page OFFSET $offset" 
    );
    
    ?>
    <style>
        .mabble-404-log-table { width: 100%; border-collapse: collapse; }
        .mabble-404-log-table th, .mabble-404-log-table td { padding: 8px 10px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        .mabble-404-log-table th { background-color: #f3f3f3; }
        .mabble-404-log-table a.sortable { text-decoration: none; display: block; }
    </style>

    <table class="mabble-404-log-table widefat fixed">
        <thead>
            <tr>
                <th width="70%">
                    <?php 
                        $url_order = ( $orderby === 'requested_url' && $order === 'ASC' ) ? 'DESC' : 'ASC';
                        $url = add_query_arg( array( 'orderby' => 'requested_url', 'order' => $url_order, 'paged' => $current_page ), $base_url );
                    ?>
                    <a class="sortable" href="<?php echo esc_url( $url ); ?>">
                        Requested URL
                        <?php if ( $orderby === 'requested_url' ) echo ( $order === 'ASC' ? '▲' : '▼' ); ?>
                    </a>
                </th>
                <th width="15%">
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
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="3">No 404 errors have been logged yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th>Requested URL</th>
                <th>404 Count</th>
                <th>Last Seen</th>
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