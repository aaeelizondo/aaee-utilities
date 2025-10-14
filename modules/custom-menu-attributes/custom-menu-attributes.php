<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- A. INJECT CUSTOM FIELDS INTO MENU EDITOR (STRING INPUT) ---

/**
 * Adds a single text area for custom link attributes in the menu editor.
 */
function aaee_menu_item_custom_fields( $item_id, $item, $depth, $args ) {
    // Retrieve the single saved string from the database.
    $saved_attributes_string = get_post_meta( $item_id, '_aaee_custom_attributes_string', true );
    ?>
    <div class="aaee-custom-attributes-string-wrapper" style="margin-top: 10px; border: 1px solid #ccc; padding: 10px;">
        <p class="field-custom-attributes description">
            <label for="edit-menu-item-attributes-<?php echo esc_attr($item_id); ?>">
                <strong>Custom Link Attributes (e.g., aria-label="Label" data-id="123")</strong>
            </label>
            <textarea 
                id="edit-menu-item-attributes-<?php echo esc_attr($item_id); ?>" 
                class="widefat code edit-menu-item-custom" 
                rows="3" 
                cols="20" 
                name="_aaee_custom_attributes_string[<?php echo esc_attr($item_id); ?>]" 
                placeholder="Example: aria-label='Open in new tab' data-tracking='footer-link'"
            ><?php echo esc_textarea( $saved_attributes_string ); ?></textarea>
            <span class="description" style="display: block; margin-top: 5px;">
                Enter raw HTML attributes and values exactly as they should appear in the &lt;a&gt; tag. Use double or single quotes.
            </span>
        </p>
    </div>
    <?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'aaee_menu_item_custom_fields', 10, 4 );


// --------------------------------------------------------------------------------


// --- B. SAVE CUSTOM FIELDS (STRING INPUT) ---

/**
 * Saves the custom attributes string when the menu is saved.
 */
function aaee_save_menu_item_custom_fields( $menu_id, $menu_item_db_id ) {
    // 1. Check if the single POST data for this specific menu item ID exists.
    if ( isset( $_POST['_aaee_custom_attributes_string'][ $menu_item_db_id ] ) ) {
        
        // 2. Retrieve and sanitize the entire string input.
        $new_attributes_string = trim( $_POST['_aaee_custom_attributes_string'][ $menu_item_db_id ] );
        $clean_attributes_string = sanitize_text_field( $new_attributes_string );

        if ( ! empty( $clean_attributes_string ) ) {
            // Save the single string.
            update_post_meta( $menu_item_db_id, '_aaee_custom_attributes_string', $clean_attributes_string );
        } else {
            // If the field is empty, remove any saved meta.
            delete_post_meta( $menu_item_db_id, '_aaee_custom_attributes_string' );
        }
    } else {
        // If the field wasn't submitted in the POST data, remove any saved meta.
        delete_post_meta( $menu_item_db_id, '_aaee_custom_attributes_string' );
    }
}
add_action( 'wp_update_nav_menu_item', 'aaee_save_menu_item_custom_fields', 10, 2 );


// --------------------------------------------------------------------------------


// --- C. APPLY ATTRIBUTES TO FRONTEND HTML (STRING INPUT) ---

/**
 * Parses the saved attributes string and adds them to the <a> tag on the frontend.
 */
function aaee_apply_custom_menu_link_attributes( $atts, $item, $args, $depth ) {
    $attributes_string = get_post_meta( $item->ID, '_aaee_custom_attributes_string', true );

    if ( ! empty( $attributes_string ) ) {
        
        // Regex: /([a-zA-Z0-9_-]+)\s*=\s*([\'"])(.*?)\2/
        // Matches: KEY = "VALUE" or KEY = 'VALUE'
        if ( preg_match_all( '/([a-zA-Z0-9_-]+)\s*=\s*([\'"])(.*?)\2/', $attributes_string, $matches, PREG_SET_ORDER ) ) {
            
            foreach ( $matches as $match ) {
                $key = trim( $match[1] ); // Attribute name (e.g., aria-label)
                $value = trim( $match[3] ); // Attribute value (e.g., New Label)

                if ( ! empty( $key ) ) {
                    // Sanitize the value and add it to the attributes array.
                    $atts[ $key ] = sanitize_text_field( $value );
                }
            }
        }
    }

    return $atts;
}
add_filter( 'nav_menu_link_attributes', 'aaee_apply_custom_menu_link_attributes', 10, 4 );


// --------------------------------------------------------------------------------

// NOTE: The previous JavaScript enqueue function is no longer needed and is REMOVED.