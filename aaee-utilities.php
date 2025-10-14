<?php
/**
 * Plugin Name: Mabble Utilities By Aaron Elizondo
 * Description: A collection of custom utility modules for various client needs.
 * Version: 1.1
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