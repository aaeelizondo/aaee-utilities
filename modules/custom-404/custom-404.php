<?php
// Module: Custom 404 Page (ID: custom_404_page)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define option key for the 404 page ID
define( 'MABBLE_404_OPTION_KEY', 'mabble_custom_404_page_id' );

// -----------------------------------------------------------
// A. REGISTER SETTINGS AND FIELDS
// -----------------------------------------------------------

/**
 * Registers the settings field for the custom 404 page, but only if the module is active.
 */
function mabble_register_custom_404_settings() {
    
    // Check if the 'custom_404_page' module is active
    $modules = get_option( 'aaee_modules' );
    $is_module_active = ! empty( $modules['custom_404_page'] );
    
    if ( ! $is_module_active ) {
        return;
    }

    // Register the setting
    register_setting( 'aaee_options_group', MABBLE_404_OPTION_KEY, 'absint' );

    // Add a new section to the main settings page
    // Page Slug: mabble-utilities (CORRECT)
    add_settings_section(
        'mabble_404_settings_section',
        'Custom 404 Page Setup',
        'mabble_404_settings_section_callback',
        'mabble-utilities' 
    );

    // Add the dropdown field
    add_settings_field(
        'mabble_404_page_id_field',
        'Select 404 Page',
        'mabble_render_404_page_dropdown',
        'mabble-utilities',
        'mabble_404_settings_section'
    );
}
add_action( 'admin_init', 'mabble_register_custom_404_settings' );

/**
 * Renders the section header text.
 */
function mabble_404_settings_section_callback() {
    echo '<p>Choose any published page to serve as the content when a "404 Not Found" error occurs. **It is highly recommended to select a Published page.**</p>';
}

/**
 * Renders the page dropdown selection field.
 */
function mabble_render_404_page_dropdown() {
    $current_page_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
    
    // Use the built-in WordPress function to display a page dropdown
    wp_dropdown_pages( array(
        'selected'          => $current_page_id,
        'name'              => MABBLE_404_OPTION_KEY, // Option name
        'show_option_none'  => '— Do not override 404 page —',
        'option_none_value' => '0', // Store 0 if none is selected
        'echo'              => 1,
        'post_status'       => array('publish', 'private'), // Show published and private pages for selection
        'hierarchical'      => 0,         // Ensures all pages are listed
        'sort_column'       => 'post_title',
    ) );
    
    // NOTE: The description echo should be done outside of the dropdown function's core output.
}

// -----------------------------------------------------------
// B. CORE 404 OVERRIDE LOGIC (Runs on frontend template_redirect, checked inside the function)
// -----------------------------------------------------------

/**
 * Loads the content of the selected page when a 404 error is detected.
 */
function mabble_custom_404_template_redirect() {
    
    // Check if the module is active AND if we are on a 404 page
    $modules = get_option( 'aaee_modules' );
    $is_module_active = ! empty( $modules['custom_404_page'] );
    
    if ( ! $is_module_active || ! is_404() ) {
        return;
    }

    global $wp_query;
    $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
    
    // Check if a custom page is selected
    if ( $custom_404_id > 0 ) {
        
        $page = get_post( $custom_404_id );

        // Ensure the selected page exists and is visible (published or private)
        if ( $page && in_array( $page->post_status, array('publish', 'private') ) ) {
            
            // 1. Clear the 404 flag on the query but maintain the 404 header status
            unset( $wp_query->query['error'] );
            $wp_query->query_vars = array();
            
            // 2. Load the post data for the selected page
            $wp_query->posts = array( $page );
            $wp_query->post_count = 1;
            $wp_query->current_post = -1;
            $wp_query->post = $page;
            
            // 3. Force WordPress to treat the request as a single page view
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            $wp_query->is_singular = true;
            $wp_query->is_archive = false;
            
            // 4. Set the HTTP status code to 404 (critical for SEO)
            status_header( 404 );
            
            // 5. Prevent caching plugins from serving the page as a 200 OK
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
        }
    }
}
add_action( 'template_redirect', 'mabble_custom_404_template_redirect' );

/**
 * Filters the body classes to help theme developers style the 404 page correctly.
 */
function mabble_404_body_class( $classes ) {
    // Only run this check if we are in a single/singular view
    if ( is_singular() ) {
        $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
        
        // If the current page is our custom 404 page, add the relevant classes
        if ( get_the_ID() === $custom_404_id ) {
             $classes[] = 'error404'; // Add the standard 404 class
             $classes[] = 'custom-404-active';
        }
    }
    return $classes;
}
add_filter( 'body_class', 'mabble_404_body_class' );