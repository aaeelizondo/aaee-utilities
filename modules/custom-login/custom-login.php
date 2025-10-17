<?php
// Module: Custom Login URL (ID: custom_login)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants
define( 'MABBLE_CUSTOM_LOGIN_OPTION_KEY', 'mabble_custom_login_slug' );
define( 'MABBLE_CUSTOM_LOGIN_SETTINGS_SLUG', 'mabble-custom-login-settings' ); // Menu slug

// Check if the module is active
$modules = get_option( 'aaee_modules' );
$is_module_active = ! empty( $modules['custom_login'] );

// Get the custom slug from the database
function mabble_get_custom_login_slug() {
    // Return the stored slug, sanitized and trimmed, defaults to an empty string.
	$slug = get_option( MABBLE_CUSTOM_LOGIN_OPTION_KEY, '' );
    return sanitize_title( $slug );
}


// -----------------------------------------------------------
// A. ADMIN SETTINGS AND PAGE
// -----------------------------------------------------------

if ( $is_module_active && is_admin() ) {
    
    /**
     * Registers the custom login slug setting and section.
     */
    function mabble_custom_login_register_settings() {
        // We only register one single option that holds our slug
        register_setting( 'mabble_custom_login_group', MABBLE_CUSTOM_LOGIN_OPTION_KEY, 'mabble_custom_login_sanitize' );

        add_settings_section(
            'mabble_custom_login_section',
            'Custom Login URL Setup',
            'mabble_custom_login_section_callback',
            MABBLE_CUSTOM_LOGIN_SETTINGS_SLUG
        );

        add_settings_field(
            'custom_login_slug_field',
            'New Login Slug',
            'mabble_render_custom_login_slug_field',
            MABBLE_CUSTOM_LOGIN_SETTINGS_SLUG,
            'mabble_custom_login_section'
        );
        
        // Use a flag to ensure rewrite rules are flushed safely after saving
        add_action( 'update_option_' . MABBLE_CUSTOM_LOGIN_OPTION_KEY, 'mabble_set_login_rewrite_flush_flag' );
    }
    add_action( 'admin_init', 'mabble_custom_login_register_settings' );

    /**
     * Sets a flag in the options table indicating rewrite rules need to be flushed.
     */
    function mabble_set_login_rewrite_flush_flag() {
        update_option( 'mabble_login_rewrite_flush_needed', true );
    }

    /**
     * Checks for the flag and flushes rewrite rules on admin init if necessary.
     */
    function mabble_check_and_flush_login_rewrites() {
        if ( get_option( 'mabble_login_rewrite_flush_needed' ) ) {
            // Check if we are on the current page to display a notice if necessary
            if ( isset( $_GET['page'] ) && $_GET['page'] === MABBLE_CUSTOM_LOGIN_SETTINGS_SLUG ) {
                // Add a temporary notice to confirm the flush
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Rewrite rules flushed! Your new login URL is now active.</p></div>';
                });
            }
            flush_rewrite_rules();
            delete_option( 'mabble_login_rewrite_flush_needed' );
        }
    }
    // Hook this function to run late on admin_init
    add_action( 'admin_init', 'mabble_check_and_flush_login_rewrites', 99 );


    /**
     * Sanitizes the new login slug.
     * @param string $slug The submitted slug.
     * @return string The sanitized slug.
     */
    function mabble_custom_login_sanitize( $slug ) {
        $clean_slug = sanitize_title( $slug ); // Uses WordPress's standard slug cleaning
        
        // Prevent slugs that conflict with core WordPress files
        if ( in_array( $clean_slug, array( 'wp-admin', 'wp-login', 'admin', 'login' ) ) ) {
            add_settings_error( 
                MABBLE_CUSTOM_LOGIN_OPTION_KEY, 
                'invalid_slug', 
                'The slug "' . esc_html($slug) . '" is reserved. Please choose another.', 
                'error' 
            );
            // If invalid, revert to the current saved value
            return get_option( MABBLE_CUSTOM_LOGIN_OPTION_KEY );
        }
        
        return $clean_slug;
    }

    /**
     * Renders the section header.
     */
    function mabble_custom_login_section_callback() {
        echo '<p>Enter a unique slug to use as your new login URL. This is a basic security enhancement.</p>';
    }

    /**
     * Renders the custom login slug input field.
     */
    function mabble_render_custom_login_slug_field() {
        $current_slug = mabble_get_custom_login_slug();
        $admin_url_path = parse_url( admin_url(), PHP_URL_PATH );
        ?>
        <div style="display: flex; align-items: center; gap: 5px;">
            <p style="margin: 0; padding: 0;"><?php echo esc_url( home_url() ); ?>/</p>
            <input type="text" 
                   name="<?php echo esc_attr( MABBLE_CUSTOM_LOGIN_OPTION_KEY ); ?>" 
                   value="<?php echo esc_attr( $current_slug ); ?>" 
                   placeholder="my-secret-login"
                   class="regular-text" />
            <p style="margin: 0; padding: 0;">/</p>
        </div>
        
        <?php if ( ! empty( $current_slug ) ) : ?>
            <p class="description" style="margin-top: 10px;">
                Your current login URL is: <strong><a href="<?php echo esc_url( home_url( $current_slug ) ); ?>"><?php echo esc_url( home_url( $current_slug ) ); ?></a></strong>
            </p>
            <p class="notice notice-warning" style="margin-top: 10px; padding: 10px;">
                Note: After saving, remember this URL! The default <code>/wp-admin</code> and <code>/wp-login.php</code> paths will no longer work.
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Renders the actual options page HTML.
     */
    function mabble_render_custom_login_page() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Mabble Custom Login URL</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'mabble_custom_login_group' );
                do_settings_sections( MABBLE_CUSTOM_LOGIN_SETTINGS_SLUG );
                submit_button( 'Save Custom Login Slug' );
                ?>
            </form>
        </div>
        <?php
    }
}

// -----------------------------------------------------------
// B. CORE FRONT-END LOGIC (REWRITE AND REDIRECTION)
// -----------------------------------------------------------

if ( $is_module_active ) {

    /**
     * Filters the login URL to use our custom slug instead of wp-login.php.
     * This ensures all internal WordPress links (e.g., login, logout) use the new path.
     */
    function mabble_filter_login_url( $login_url, $redirect = '', $force_reauth = false ) {
        $custom_slug = mabble_get_custom_login_slug();
        
        if ( empty( $custom_slug ) ) {
            return $login_url; // Return default if no custom slug is set
        }

        $login_url = home_url( $custom_slug, 'login' ); // Use 'login' protocol for HTTPS
        
        if ( ! empty( $redirect ) ) {
            $login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );
        }

        if ( $force_reauth ) {
            $login_url = add_query_arg( 'reauth', '1', $login_url );
        }
        
        return $login_url;
    }
    add_filter( 'login_url', 'mabble_filter_login_url', 10, 3 );
    
    /**
     * Hooks the custom login URL to the 'wp-login.php' file. (GET Requests)
     */
    function mabble_add_custom_login_rewrite() {
        $custom_slug = mabble_get_custom_login_slug();
        if ( empty( $custom_slug ) ) {
            return;
        }

        add_rewrite_rule(
            '^' . preg_quote( $custom_slug ) . '(?:\?.*)?$', // Matches the slug with or without query params
            'wp-login.php', 
            'top' 
        );
    }
    add_action( 'init', 'mabble_add_custom_login_rewrite' );

    /**
     * Executes the login script when POSTing to the custom slug. (POST Requests)
     */
    function mabble_handle_custom_login_post() {
        $custom_slug = mabble_get_custom_login_slug();
        
        if ( empty( $custom_slug ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return;
        }
        
        $request_uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        $request_path = '/' . ltrim( $request_uri, '/' ); 
        $custom_path = '/' . ltrim( $custom_slug, '/' );
        
        if ( $request_path === $custom_path || rtrim( $request_path, '/' ) === $custom_path ) {
            
            if ( ! defined( 'WP_ADMIN' ) ) {
                define( 'WP_ADMIN', true );
            }

            $login_file = ABSPATH . 'wp-login.php';
            if ( file_exists( $login_file ) ) {
                // Set the current script name to simulate wp-login.php running
                $_SERVER['PHP_SELF'] = $login_file;
                require_once( $login_file );
                exit;
            }
        }
    }
    add_action( 'template_redirect', 'mabble_handle_custom_login_post', 1 ); 
    
    
    /**
     * Intercepts access to wp-login.php and /wp-admin and redirects to a safe page 
     * (the homepage), preventing exposure of the custom slug.
     */
    function mabble_intercept_default_login() {
        // Prevent double redirection
        if ( defined( 'LOGIN_PAGE_REDIRECTED' ) ) {
            return;
        }
        
        $custom_slug = mabble_get_custom_login_slug();
        
        if ( empty( $custom_slug ) || is_user_logged_in() ) {
            return;
        }
        
        $request_uri = $_SERVER['REQUEST_URI'];
        $redirect_needed = false;
        
        // 1. Check for /wp-admin
        if ( strpos( $request_uri, '/wp-admin' ) !== false ) {
            $redirect_needed = true;
        }

        // 2. Check for /wp-login.php
        if ( strpos( $request_uri, 'wp-login.php' ) !== false ) {
            $redirect_needed = true;
        }

        if ( $redirect_needed ) {
            define( 'LOGIN_PAGE_REDIRECTED', true );
            
            // SECURITY FIX: Redirect to the homepage, NOT to the custom login URL.
            wp_redirect( home_url(), 302 ); 
            exit;
        }
        
        return;
    }
    // Hook early before the default WordPress redirects fire
    add_action( 'init', 'mabble_intercept_default_login', 1 );

    /**
     * Ensures all admin/core links that point to wp-login.php use the new slug.
     */
    function mabble_filter_site_url( $url, $path, $scheme ) {
        $custom_slug = mabble_get_custom_login_slug();
        
        if ( empty( $custom_slug ) ) {
            return $url;
        }

        // Only target the core wp-login.php file path
        if ( $path === 'wp-login.php' ) {
            $url = home_url( $custom_slug, $scheme );
        }
        
        return $url;
    }
    // Hooks that replace the site/home URL references to wp-login.php
    add_filter( 'site_url', 'mabble_filter_site_url', 10, 3 );
    add_filter( 'home_url', 'mabble_filter_site_url', 10, 3 );
}