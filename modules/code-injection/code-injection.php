<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------
// A. REGISTER SETTINGS, FIELDS, AND SECTIONS
// -----------------------------------------------------------

/**
 * Registers the settings, fields, and sections for the Code Injection submenu page.
 */
function aaee_register_code_injection_settings() {
    // Register the main option group
    register_setting( 'aaee_code_injection_group', 'aaee_injection_code', 'aaee_sanitize_code_injection' );

    // Define the section slug here
    $section_slug = 'aaee_code_injection_section';

    // Add a settings section
    add_settings_section(
        $section_slug,
        'Global Code Injection Fields',
        'aaee_code_injection_section_callback',
        'aaee-code-injection' // Settings page slug
    );

    // Field 1: Code in <head>
    add_settings_field(
        'head_code_field',
        'Code in &lt;head&gt; (wp_head)',
        'aaee_render_textarea_field',
        'aaee-code-injection',
        $section_slug, // Correct section slug
        array(
            'id' => 'head_code',
            'description' => 'Injected right before the closing &lt;/head&gt; tag. Use for analytics, verification, and critical CSS/JS.',
        )
    );

    // Field 2: Code after <body>
    add_settings_field(
        'body_open_code_field',
        'Code After &lt;body&gt; (wp_body_open)',
        'aaee_render_textarea_field',
        'aaee-code-injection',
        $section_slug, // Correct section slug
        array(
            'id' => 'body_open_code',
            'description' => 'Injected immediately after the opening &lt;body&gt; tag. Use for Tag Manager or other body-top trackers.',
        )
    );

    // Field 3: Code before </body> (THE FIX IS HERE)
    add_settings_field(
        'footer_code_field',
        'Code Before &lt;/body&gt; (wp_footer)',
        'aaee_render_textarea_field',
        'aaee-code-injection',
        $section_slug, // *** THIS WAS THE ERROR *** (It previously referenced the page slug)
        array(
            'id' => 'footer_code',
            'description' => 'Injected right before the closing &lt;/body&gt; tag. Ideal for deferrable JavaScript, conversion pixels, and chat widgets.',
        )
    );
}
add_action( 'admin_init', 'aaee_register_code_injection_settings' );

/**
 * Renders the section header text.
 */
function aaee_code_injection_section_callback() {
    echo '<p>Add custom JavaScript, CSS, or HTML snippets to various crucial locations across your site. **Be cautious with what you paste here.**</p>';
}

/**
 * Renders a reusable textarea field for the settings.
 */
function aaee_render_textarea_field( $args ) {
    $options = get_option( 'aaee_injection_code' );
    $id = esc_attr( $args['id'] );
    $value = isset( $options[ $id ] ) ? $options[ $id ] : '';
    $description = isset( $args['description'] ) ? $args['description'] : '';

    echo '<textarea 
            id="' . $id . '" 
            name="aaee_injection_code[' . $id . ']" 
            rows="10" 
            cols="80" 
            class="large-text code">' . 
            esc_textarea( $value ) . 
         '</textarea>';

    if ( ! empty( $description ) ) {
        echo '<p class="description">' . esc_html( $description ) . '</p>';
    }
}

/**
 * Sanitizes the textarea inputs. No sanitation is applied as we are expecting raw code.
 */
function aaee_sanitize_code_injection( $input ) {
    return $input;
}


// -----------------------------------------------------------
// B. RENDER THE SETTINGS PAGE
// -----------------------------------------------------------

/**
 * Renders the Code Injection submenu page HTML.
 */
function aaee_code_injection_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Code Injection</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'aaee_code_injection_group' );
            do_settings_sections( 'aaee-code-injection' );
            submit_button( 'Save Injection Code' );
            ?>
        </form>
    </div>
    <?php
}


// -----------------------------------------------------------
// C. FRONT-END INJECTION LOGIC
// -----------------------------------------------------------

/**
 * Injects code into the requested locations on the frontend.
 */
function aaee_inject_custom_code() {
    $options = get_option( 'aaee_injection_code' );

    // Hook 1: wp_head (Injected right before </head>)
    if ( ! empty( $options['head_code'] ) ) {
        add_action( 'wp_head', function() use ($options) {
            echo "\n\n" . $options['head_code'] . "\n\n";
        });
    }

    // Hook 2: wp_body_open (Injected right after <body>)
    if ( ! empty( $options['body_open_code'] ) ) {
        add_action( 'wp_body_open', function() use ($options) {
            echo "\n\n" . $options['body_open_code'] . "\n\n";
        });
    }

    // Hook 3: wp_footer (Injected right before </body>)
    if ( ! empty( $options['footer_code'] ) ) {
        add_action( 'wp_footer', function() use ($options) {
            echo "\n\n" . $options['footer_code'] . "\n\n";
        });
    }
}
add_action( 'plugins_loaded', 'aaee_inject_custom_code', 20 );