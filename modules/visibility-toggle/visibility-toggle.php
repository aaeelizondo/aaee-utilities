<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the block visibility meta field.
 * NOTE: We register the field as a string here because the block attribute 
 * is a boolean in JS, but when saved to post meta, WP sometimes converts it 
 * to a string representation ('true'/'false' or '1'/'0').
 */
function aaee_register_block_visibility_meta() {
    register_post_meta( '', 'aaee_live_hide', array(
        'show_in_rest' => true,
        'single'       => true,
        // The type must be handled carefully. Since your JS uses boolean, 
        // we'll use string to allow 'true'/'false' storage via the REST API.
        'type'         => 'string', 
        'default'      => 'false',
        'auth_callback' => function() {
            return current_user_can( 'edit_posts' );
        }
    ) );
}
add_action( 'init', 'aaee_register_block_visibility_meta' );


/**
 * Enqueue the required assets for the Gutenberg Editor.
 */
function aaee_enqueue_visibility_assets() {
    // --- CORRECTED PATHING TO 'build/' ---
    $asset_dir_url = plugin_dir_url( __FILE__ ) . 'build/';
    $asset_dir_path = plugin_dir_path( __FILE__ ) . 'build/';
    
    // Check if the script file exists before enqueuing
    if ( ! file_exists( $asset_dir_path . 'index.js' ) ) {
        // If the file name is index.js (as suggested by src/index.js)
        return; 
    }
    // -------------------------

    // 1. Enqueue the JavaScript (React component for the sidebar control)
    wp_enqueue_script(
        'aaee-visibility-editor-script',
        // Use index.js as the filename, matching the standard build output
        $asset_dir_url . 'index.js', 
        // These dependencies are required for your JS to run correctly.
        array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor', 'wp-components', 'wp-data', 'wp-edit-post' ),
        filemtime( $asset_dir_path . 'index.js' ), // Versioning
        true // Load in footer
    );

    // 2. Enqueuing CSS (Assuming a build output of index.css)
    wp_enqueue_style(
        'aaee-visibility-editor-style',
        $asset_dir_url . 'index.css',
        array( 'wp-components' ),
        filemtime( $asset_dir_path . 'index.css' ) 
    );
}
// Hook into the Gutenberg editor assets action
add_action( 'enqueue_block_editor_assets', 'aaee_enqueue_visibility_assets' );


/**
 * Apply the visibility filter on the front-end.
 * This function determines if the block should be rendered based on the meta value (aaeeLiveHide).
 */
function aaee_render_block_visibility_filter( $block_content, $block ) {
    // Only apply the filter to actual block rendering on the frontend (not the editor).
    // Check for your specific attribute name: aaeeLiveHide
    if ( is_admin() || ! isset( $block['attrs']['aaeeLiveHide'] ) ) {
        return $block_content;
    }

    // The value will be a PHP boolean or a string 'true'/'false' or 1/0 from the database/REST API.
    $should_hide = $block['attrs']['aaeeLiveHide'];

    // Check if the value is explicitly set to true (boolean or string 'true'/'1')
    if ( $should_hide === true || $should_hide === 'true' || $should_hide === 1 || $should_hide === '1' ) {
        return ''; // Return an empty string (hide the block).
    }

    // Otherwise, return the block content (show the block).
    return $block_content;
}
add_filter( 'render_block', 'aaee_render_block_visibility_filter', 10, 2 );