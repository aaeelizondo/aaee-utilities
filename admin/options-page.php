<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Module Definitions (Titles and Descriptions)
$aaee_module_definitions = array(
    'visibility_toggle' => array(
        'title' => 'Gutenberg Block Visibility Toggle',
        'description' => 'Adds a sidebar control to all Gutenberg blocks, allowing you to hide them from the public front-end view.',
    ),
    'custom_menu_attributes' => array(
        'title' => 'Custom Menu Item Attributes (Accessibility)',
        'description' => 'Adds a text field to all navigation menu items for injecting custom attributes (e.g., aria-label, data-*) for accessibility and front-end scripting.',
    ),
    'code_injection' => array(
        'title' => 'Code Injection',
        'description' => 'Allows injecting custom code (JavaScript, tracking pixels, etc.) into the <head>, after the opening <body>, and before the closing <body>.',
    ),
    'custom_404_page' => array(
        'title' => 'Custom 404 Page',
        'description' => 'Allows setting any published page as the custom page displayed on a 404 "Not Found" error.',
    ),
    'custom_redirects' => array(
        'title' => 'Custom Redirection Manager (301/302)',
        'description' => 'Dedicated management for explicit 301 (Permanent) and 302 (Temporary) redirects.',
    ),
    'custom_login' => array(
        'title' => 'Custom Login URL',
        'description' => 'Enhance security by changing the default WordPress login URL (/wp-admin, /wp-login.php) to a custom slug.',
    ),
);

/**
 * Registers all settings fields and sections for the Mabble Utilities page.
 */
function aaee_register_settings() {
    register_setting( 'aaee_options_group', 'aaee_modules' );

    add_settings_section(
        'aaee_module_settings_section',
        'Module Management',
        'aaee_module_settings_section_callback',
        'mabble-utilities' // Updated slug
    );

    global $aaee_module_definitions;

    // Dynamically add fields for each defined module
    foreach ( $aaee_module_definitions as $module_key => $module_data ) {
        add_settings_field(
            "{$module_key}_field",
            $module_data['title'], // Use title from definition
            'aaee_render_toggle_field',
            'mabble-utilities', // Updated slug
            'aaee_module_settings_section',
            array( 'module' => $module_key, 'description' => $module_data['description'] ) // Pass module key and description
        );
    }
}
add_action( 'admin_init', 'aaee_register_settings' );

/**
 * Renders the section header (briefing text).
 */
function aaee_module_settings_section_callback() {
    echo '<p>Enable or disable individual modules to control features and improve site performance.</p>';
}

/**
 * Renders the custom toggle switch field and description, plus a direct link 
 * for the Code Injection module, the 404 module, and the Redirection module if active.
 */
function aaee_render_toggle_field( $args ) {
    $module_key = $args['module'];
    $description = $args['description'];
    $options = get_option( 'aaee_modules' );
    $is_active = isset( $options[ $module_key ] ) ? 1 : 0;
    
    // Define the link data based on the module key
    $link_data = array();
    if ( $module_key === 'code_injection' ) {
        $link_data['url'] = admin_url( 'options-general.php?page=mabble-code-injection' );
        $link_data['text'] = 'Inject Code Now';
        $link_data['class'] = 'button-primary';
    } elseif ( $module_key === 'custom_404_page' ) {
        // Use the slug defined in custom-404.php: 'mabble-404-settings'
        $link_data['url'] = admin_url( 'options-general.php?page=mabble-404-settings' );
        $link_data['text'] = 'Manage 404 Settings & Logs';
        $link_data['class'] = 'button-secondary';
    } elseif ( $module_key === 'custom_redirects' ) {
        // Use the slug defined in custom-redirects.php: 'mabble-redirects-settings'
        $link_data['url'] = admin_url( 'tools.php?page=mabble-redirects-settings' ); // NOTE: Uses tools.php
        $link_data['text'] = 'Manage Redirection Rules';
        $link_data['class'] = 'button-secondary';
    } elseif ( $module_key === 'custom_login' ) {
        // Use the slug defined in custom-login.php: 'mabble-custom-login-settings'
        $link_data['url'] = admin_url( 'options-general.php?page=mabble-custom-login-settings' );
        $link_data['text'] = 'Set Custom Login URL';
        $link_data['class'] = 'button-primary';
    }


    // --- Toggle Switch HTML ---
    echo '<div style="display: flex; align-items: center; gap: 20px;">';
    echo '<label class="aaee-toggle-switch">';
    echo '<input type="checkbox" name="aaee_modules[' . esc_attr( $module_key ) . ']" value="1" ' . checked( 1, $is_active, false ) . '>';
    echo '<span class="aaee-toggle-slider"></span>';
    echo '</label>';
    
    // --- Module Admin Button ---
    if ( $is_active && ! empty( $link_data ) ) {
        echo '<a href="' . esc_url($link_data['url']) . '" class="button ' . esc_attr($link_data['class']) . '" style="margin: 0;">' . esc_html($link_data['text']) . '</a>';
    }
    
    echo '</div>'; // Close flex container
    
    // --- Description ---
    echo '<p class="description" style="max-width: 600px; margin-top: 5px;">' . esc_html( $description ) . '</p>';
}

/**
 * Renders the actual options page HTML.
 */
function aaee_utilities_options_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Add CSS for the toggle switch (style remains the same)
    echo '<style>
        .aaee-toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .aaee-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .aaee-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
            border-radius: 34px;
        }

        .aaee-toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .aaee-toggle-slider {
            background-color: #2196F3;
        }

        input:focus + .aaee-toggle-slider {
            box-shadow: 0 0 1px #2196F3;
        }

        input:checked + .aaee-toggle-slider:before {
            -webkit-transform: translateX(26px);
            -ms-transform: translateX(26px);
            transform: translateX(26px);
        }
    </style>';
    
    // Actual admin page markup
    ?>
    <div class="wrap">
        <h1>Mabble Utilities Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'aaee_options_group' );
            do_settings_sections( 'mabble-utilities' ); // Updated slug
            submit_button( 'Save Module Settings' );
            ?>
        </form>
    </div>
    <?php
}