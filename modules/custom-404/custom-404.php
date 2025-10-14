<?php
// Module: Custom 404 Page (ID: custom_404)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define option key for the 404 page ID
define( 'MABBLE_404_OPTION_KEY', 'mabble_custom_404_page_id' );

// Check if the 'custom_404' module is active
$modules = get_option( 'aaee_modules' );
$is_module_active = ! empty( $modules['custom_404_page'] ); // NOTE: Module ID is 'custom_404_page' based on your aaee-utilities.php include check

// -----------------------------------------------------------
// A. REGISTER SETTINGS AND FIELDS (Only run if module is active)
// -----------------------------------------------------------

if ( $is_module_active ) {

    /**
     * Registers the settings field for the custom 404 page.
     */
    function mabble_register_custom_404_settings() {
        
        // Register the setting
        // Option Group: aaee_options_group (from your main plugin file)
        register_setting( 'aaee_options_group', MABBLE_404_OPTION_KEY, 'absint' );

        // Add a new section to the main settings page
        // Page Slug: mabble-utilities (CORRECTED from aaee-utilities)
        add_settings_section(
            'mabble_404_settings_section',
            'Custom 404 Page Setup',
            'mabble_404_settings_section_callback',
            'mabble-utilities' // <--- CORRECTED SLUG
        );

        // Add the dropdown field
        // Page Slug: mabble-utilities (must match the section slug)
        add_settings_field(
            'mabble_404_page_id_field',
            'Select 404 Page',
            'mabble_render_404_page_dropdown',
            'mabble-utilities', // <--- CORRECTED SLUG
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
            'hierarchical'      => 0,         // <-- ADD THIS: Ensures all published pages are listed
        ) );
        
        echo '<p class="description">The content of the selected page will be displayed, but the HTTP status code will correctly remain 404.</p>';
    }
    
    
    // -----------------------------------------------------------
    // B. CORE 404 OVERRIDE LOGIC (Only run if module is active)
    // -----------------------------------------------------------

    /**
     * Loads the content of the selected page when a 404 error is detected.
     */
    function mabble_custom_404_template_redirect() {
        global $wp_query;

        $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
        
        // Check if it's a 404 error AND a custom page is selected
        if ( is_404() && $custom_404_id > 0 ) {
            
            // Ensure the selected page is actually published
            $page = get_post( $custom_404_id );

            if ( $page && $page->post_status == 'publish' ) {
                
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
        // If the query was successfully manipulated to display our custom 404 page
        $custom_404_id = absint( get_option( MABBLE_404_OPTION_KEY ) );
        if ( is_singular() && get_the_ID() == $custom_404_id ) {
             $classes[] = 'error404'; // Add the standard 404 class
             $classes[] = 'custom-404-active';
        }
        return $classes;
    }
    add_filter( 'body_class', 'mabble_404_body_class' );

}