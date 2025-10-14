<?php
/**
 * Plugin Name: Mabble Utilities By Aaron Elizondo
 * Description: A collection of custom utility modules for various client needs.
 * Version: 1.3
 * Author: Aaron Elizondo
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the plugin directory path
if ( ! defined( 'AAEE_PLUGIN_DIR' ) ) {
	define( 'AAEE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Adds the main Mabble Utilities admin menu page under the Settings menu.
 */
function aaee_add_admin_menu() {
	add_options_page(
		'Mabble Utilities',      // Page Title
		'Mabble Utilities',      // Menu Title
		'manage_options',      // REQUIRED CAPABILITY for settings pages
		'mabble-utilities',    // Updated Menu Slug
		'aaee_utilities_options_page' // Callback function (defined in admin/options-page.php)
	);
}
add_action( 'admin_menu', 'aaee_add_admin_menu' );

/**
 * Adds the Code Injection page under the Settings menu (parallel to main settings)
 * if the module is active.
 */
function aaee_add_code_injection_page() {
    $options = get_option( 'aaee_modules' );

    // Check if the Code Injection module is explicitly enabled
    if ( isset( $options['code_injection'] ) ) {
        add_options_page(
            'Mabble Code Injection',            // Updated Page Title
            'Mabble Code Injection',            // Updated Menu Title
            'manage_options',                 // Capability
            'mabble-code-injection',            // Updated Menu Slug
            'aaee_code_injection_settings_page' // Callback function (defined in the new module file)
        );
    }
}
add_action( 'admin_menu', 'aaee_add_code_injection_page' );


/**
 * Adds a "Settings" link to the plugin action row on the Plugins page.
 */
function aaee_plugin_settings_link( $links ) {
	// Updated link to use the new slug 'mabble-utilities'
	$settings_link = '<a href="options-general.php?page=mabble-utilities">' . __( 'Settings', 'mabble-utilities' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'aaee_plugin_settings_link' );


/**
 * Includes the module management options page logic.
 */
require_once AAEE_PLUGIN_DIR . 'admin/options-page.php';


/**
 * Include active modules.
 */
function aaee_include_modules() {
	$options = get_option( 'aaee_modules' );

	// Module 1: Visibility Toggle
	if ( isset( $options['visibility_toggle'] ) ) {
		require_once AAEE_PLUGIN_DIR . 'modules/visibility-toggle/visibility-toggle.php';
	}

	// Module 2: Custom Menu Attributes
	if ( isset( $options['custom_menu_attributes'] ) ) {
		require_once AAEE_PLUGIN_DIR . 'modules/custom-menu-attributes/custom-menu-attributes.php';
	}

	// Module 3: Code Injection
	if ( isset( $options['code_injection'] ) ) {
		require_once AAEE_PLUGIN_DIR . 'modules/code-injection/code-injection.php';
	}
	  // Module 4: Custom 404 Page Selection
    if ( isset( $options['custom_404_page'] ) ) {
        require_once AAEE_PLUGIN_DIR . 'modules/custom-404/custom-404.php';
    }
	
	// Add other module includes here
}
add_action( 'plugins_loaded', 'aaee_include_modules' );

/*
 * =================================================================
 * CUSTOM GITHUB UPDATER LOGIC
 * =================================================================
 */

// -----------------------------------------------------------------
// NOTE: Using the repository name 'aaee-utilities' to match the plugin slug.
// -----------------------------------------------------------------
define( 'MABBLE_UTILITIES_GITHUB_REPO', 'aaeelizondo/aaee-utilities' );
define( 'MABBLE_UTILITIES_GITHUB_URL', 'https://github.com/' . MABBLE_UTILITIES_GITHUB_REPO );
define( 'MABBLE_UTILITIES_PLUGIN_FILE', plugin_basename( __FILE__ ) );


/**
 * Intercepts the plugin information popup data.
 * This is what populates the 'View Details' link on the Plugins page.
 * @param bool|object $false
 * @param string $action
 * @param object $arg
 * @return bool|object
 */
function mabble_github_plugin_info( $false, $action, $arg ) {
    if ( $action !== 'plugin_information' ) {
        return $false;
    }
    
    // Check if the request is for our plugin slug (which is 'aaee-utilities')
    if ( !isset( $arg->slug ) || $arg->slug !== dirname( MABBLE_UTILITIES_PLUGIN_FILE ) ) {
        return $false;
    }

    // Fetch information from GitHub API
    $response = wp_remote_get( 
        'https://api.github.com/repos/' . MABBLE_UTILITIES_GITHUB_REPO . '/releases/latest', 
        array( 'timeout' => 15 )
    );
    
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return $false;
    }
    
    $release = json_decode( wp_remote_retrieve_body( $response ) );
    
    // --- Construct the data object expected by WordPress ---
    $plugin_info = new stdClass();
    $plugin_info->name = 'Mabble Utilities'; // Display Name
    $plugin_info->slug = dirname( MABBLE_UTILITIES_PLUGIN_FILE ); // Slug: aaee-utilities
    $plugin_info->version = $release->tag_name;
    $plugin_info->author = 'Aaron Elizondo';
    $plugin_info->homepage = MABBLE_UTILITIES_GITHUB_URL;
    $plugin_info->last_updated = $release->published_at;
    $plugin_info->sections = array(
        'description' => 'A collection of custom utility modules for various client needs.',
        'changelog'   => isset($release->body) ? $release->body : 'View repository for changelog details.',
    );
    $plugin_info->download_link = $release->zipball_url;

    return $plugin_info;
}
add_filter( 'plugins_api', 'mabble_github_plugin_info', 20, 3 );


/**
 * Intercepts the update check and tells WordPress a new version exists.
 * @param object $transient The update transient object.
 * @return object The modified transient object.
 */
function mabble_github_update_check( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }
    
    // Get latest release from GitHub
    $response = wp_remote_get( 
        'https://api.github.com/repos/' . MABBLE_UTILITIES_GITHUB_REPO . '/releases/latest',
        array( 'timeout' => 15 )
    );
    
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return $transient;
    }
    
    $release = json_decode( wp_remote_retrieve_body( $response ) );
    $latest_version = $release->tag_name; // GitHub tag name (e.g., 1.2.0)

    // Get current version from plugin file header
    $plugin_data = get_plugin_data( __FILE__ );
    $current_version = $plugin_data['Version'];

    // Compare versions (GitHub's tag must be greater than current version)
    if ( version_compare( $latest_version, $current_version, '>' ) ) {
        $update_package = new stdClass();
        $update_package->slug = dirname( MABBLE_UTILITIES_PLUGIN_FILE );
        $update_package->new_version = $latest_version;
        $update_package->url = MABBLE_UTILITIES_GITHUB_URL;
        // IMPORTANT: The package link must be the zipball URL from the GitHub API
        $update_package->package = $release->zipball_url;
        
        // Add the update information to the WordPress transient
        $transient->response[ MABBLE_UTILITIES_PLUGIN_FILE ] = $update_package;
    }

    return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'mabble_github_update_check' );