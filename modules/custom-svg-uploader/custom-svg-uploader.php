<?php
// Module: Custom SVG Uploader (ID: custom_svg_uploader)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------
// A. ALLOW SVG MIME TYPE
// -----------------------------------------------------------

/**
 * Filters the list of allowed mime types and file extensions to include SVG.
 * The check is limited to users with 'manage_options' capability (Administrators) for security.
 *
 * @param array $mimes Mime types keyed by the file extension regex.
 * @return array Modified array of mime types.
 */
function mabble_allow_svg_upload_mimes( $mimes ) {
    // Only allow Administrators to upload SVGs for security reasons
    if ( current_user_can( 'manage_options' ) ) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
    }
    return $mimes;
}
add_filter( 'upload_mimes', 'mabble_allow_svg_upload_mimes' );

// -----------------------------------------------------------
// B. FIX STRICT MIME CHECK (For WP 5.0+ Uploads)
// -----------------------------------------------------------

/**
 * Overrides strict file type checking specifically for SVG files to ensure successful upload.
 * This is crucial because WordPress often incorrectly detects the MIME type of SVGs.
 *
 * @param array $data File data array (ext, type, proper_type).
 * @param string $file Full path to the file.
 * @param string $filename The name of the file.
 * @param string $mimes Mime types.
 * @return array Modified file data.
 */
function mabble_fix_svg_mime_type( $data, $file, $filename, $mimes ) {
    $filetype = wp_check_filetype( $filename, $mimes );

    // If the file extension is 'svg' AND the user is trusted, force the correct mime type.
    if ( strpos( $filetype['ext'], 'svg' ) !== false && current_user_can( 'manage_options' ) ) {
        $data['ext'] = $filetype['ext'];
        $data['type'] = 'image/svg+xml';
        $data['proper_mime_type'] = 'image/svg+xml';
    }
    return $data;
}
add_filter( 'wp_check_filetype_and_ext', 'mabble_fix_svg_mime_type', 10, 4 );

// -----------------------------------------------------------
// C. ENABLE SVG DISPLAY IN MEDIA LIBRARY AND BLOCKS
// -----------------------------------------------------------

/**
 * Enqueues a small CSS snippet to ensure SVGs display correctly (at 100% size) 
 * in the Media Grid and within the block editor previews. Without this, SVGs 
 * often appear as broken or invisible images in the admin UI.
 */
function mabble_svg_media_display_styles() {
    // Only load the style in the admin area and for trusted users
    if ( is_admin() && current_user_can( 'manage_options' ) ) {
        echo '<style type="text/css">
            /* Display SVGs correctly in the media grid/modal */
            .attachment.details .thumbnail img[src$=".svg"], 
            .attachment.details .thumbnail img[src$=".svgz"],
            .media-icon img[src$=".svg"],
            .media-icon img[src$=".svgz"] {
                width: 100% !important;
                height: auto !important;
            }
            /* Fix SVG display in blocks preview */
            .block-editor-block-list__block img[src$=".svg"] {
                max-width: 100%;
                height: auto;
            }
        </style>';
    }
}
add_action( 'admin_head', 'mabble_svg_media_display_styles' );
add_action( 'customize_controls_print_styles', 'mabble_svg_media_display_styles' );