<?php
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
 * Registers the settings field for the custom 404 page.
 */
function mabble_register_custom_404_settings() {
    // We can register this setting right on the main Mabble Utilities page since it's simple.
    // NOTE: This assumes your main settings page slug is 'mabble-utilities'
    
    // Register the setting
    register_setting( 'aaee_options_group', MABBLE_404_OPTION_KEY, 'absint' );

    // Add a new section to the main settings page
    add_settings_section(
        'mabble_404_settings_section',
        'Custom 404 Page Setup',
        'mabble_404_settings_section_callback',
        'mabble-utilities' // Page slug of your main settings page
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
    echo '<p>Choose any published page to serve as the content when a "404 Not Found" error occurs.</p>';
}

/**
 * Renders the page dropdown selection field.
 */
function mabble_render_404_page_dropdown() {
    $current_page_id = get_option( MABBLE_404_OPTION_KEY );
    
    // Use the built-in WordPress function to display a page dropdown
    wp_dropdown_pages( array(
        'selected'          => $current_page_id,
        'name'              => MABBLE_404_OPTION_KEY, // Option name
        'show_option_none'  => '— Do not override 404 page —',
        'option_none_value' => '0', // Store 0 if none is selected
        'echo'              => 1,
        'post_status'       => 'publish', // Only show published pages
    ) );
    
    echo '<p class="description">The content of the selected page will be displayed, but the HTTP status code will correctly remain 404.</p>';
}


// -----------------------------------------------------------
// B. CORE 404 OVERRIDE LOGIC
// -----------------------------------------------------------

/**
 * Loads the content of the selected page when a 404 error is detected.
 * We use the 'template_redirect' hook, which is early enough to change the query
 * but late enough that WordPress knows it's a 404.
 */
function mabble_custom_404_template_redirect() {
    global $wp_query;

    $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
    
    // Check if it's a 404 error AND a custom page is selected
    if ( is_404() && $custom_404_id > 0 ) {
        
        // Ensure the selected page is actually published
        $page = get_post( $custom_404_id );

        if ( $page && $page->post_status == 'publish' ) {
            
            // 1. Manually set the query to load the custom page
            // This tells WordPress to use the standard page template logic.
            $wp_query->set_404(); // Keep the 404 header status
            $wp_query->is_404 = true;
            $wp_query->is_page = true;
            $wp_query->is_single = false;
            $wp_query->is_archive = false;
            $wp_query->is_category = false;
            $wp_query->is_tax = false;
            $wp_query->is_home = false;
            $wp_query->is_singular = true;

            // Load the post data
            $wp_query->posts = array( $page );
            $wp_query->post_count = 1;
            $wp_query->current_post = -1;
            $wp_query->post = $page;
            
            // Optional: Prevent caching plugins from serving the page as a 200 OK
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
        }
    }
}
add_action( 'template_redirect', 'mabble_custom_404_template_redirect' );


/**
 * Ensures the correct post is displayed in the loop.
 * Necessary because the query has been manipulated.
 */
function mabble_custom_404_the_post( $posts ) {
    $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );

    // Only modify the loop if it's a 404 and we have a custom ID
    if ( is_404() && $custom_404_id > 0 && empty( $posts ) ) {
        $page = get_post( $custom_404_id );
        if ( $page ) {
            return array( $page );
        }
    }
    return $posts;
}
add_filter( 'the_posts', 'mabble_custom_404_the_post' );