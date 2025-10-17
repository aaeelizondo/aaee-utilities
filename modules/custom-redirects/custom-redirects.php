<?php
// Module: Custom Redirection Manager (ID: custom_redirects)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants
define( 'MABBLE_REDIRECTS_RULES_KEY', 'mabble_redirect_rules' ); // Key for all redirection rules
define( 'MABBLE_REDIRECTS_SETTINGS_SLUG', 'mabble-redirects-settings' ); // Menu slug

// Check if the module is active (should already be checked by the main plugin file, but safe practice)
$modules = get_option( 'aaee_modules' );
$is_module_active = ! empty( $modules['custom_redirects'] );

// -----------------------------------------------------------
// A. ADMIN MENU AND SETTINGS PAGE
// -----------------------------------------------------------

if ( $is_module_active && is_admin() ) {
    
    // FIX 1: Hook the form processor to run early on admin_init, preventing white screen issues.
    add_action( 'admin_init', 'mabble_process_redirect_form' );
    
	/**
	 * Renders the main settings page HTML and includes the rule table.
	 */
	function mabble_render_redirects_settings_page() {
		?>
		<div class="wrap">
			<h1>Mabble Redirection Manager (301/302)</h1>
			
			<?php mabble_render_redirect_notices(); // Render success/error notices ?>

			<?php mabble_render_redirect_form(); ?>

			<h2>Active Redirection Rules</h2>
			<?php mabble_render_redirects_table(); ?>
			
		</div>
		<?php
	}
}

// -----------------------------------------------------------
// B. ADMIN LOGIC AND UTILITIES
// -----------------------------------------------------------

/**
 * Sanitizes and validates a single redirect rule.
 * @param array $rule Rule data.
 * @return array|false Sanitized rule or false on failure.
 */
function mabble_sanitize_redirect_rule( $rule ) {
    if ( ! is_array( $rule ) ) {
        return false;
    }

    $source = isset( $rule['source_url'] ) ? sanitize_text_field( $rule['source_url'] ) : '';
    $target = isset( $rule['target_url'] ) ? esc_url_raw( $rule['target_url'] ) : '';
    $status = isset( $rule['status_code'] ) ? absint( $rule['status_code'] ) : 301;

    // Source URL must be a relative path and non-empty. Use wp_parse_url for consistency.
    $source_path = wp_parse_url( $source, PHP_URL_PATH );
    if ( empty( $source_path ) ) {
        return false; // Source is invalid or empty
    }
    
    // Target URL must be a valid URL (absolute or relative)
    if ( empty( $target ) ) {
        return false; // Target is empty
    }

    // Status code must be 301 or 302
    if ( $status !== 301 && $status !== 302 ) {
        $status = 301;
    }
    
    // Ensure relative paths start with a slash and do not contain the domain
    $source_path = '/' . ltrim( $source_path, '/' );

    return array(
        'source_url'  => $source_path, 
        'target_url'  => $target,
        'status_code' => $status,
    );
}

/**
 * Processes the creation, editing, and deletion of redirect rules.
 */
function mabble_process_redirect_form() {
    
    // FIX 1: Only proceed if we are on the correct page and a POST action is present
    if ( ! is_admin() || ! isset( $_GET['page'] ) || $_GET['page'] !== MABBLE_REDIRECTS_SETTINGS_SLUG ) {
        return;
    }

    // Check if the form was actually submitted
    if ( ! isset( $_POST['redirect_action'] ) ) {
        return;
    }
    
    if ( ! isset( $_POST['mabble_redirect_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
		// Do not return here. wp_verify_nonce below will handle the security failure.
        // But if there's no nonce field, we definitely stop.
        if ( ! isset( $_POST['mabble_redirect_nonce'] ) ) {
            return;
        }
	}

	if ( ! wp_verify_nonce( $_POST['mabble_redirect_nonce'], 'mabble-manage-redirects' ) ) {
		wp_die( 'Security check failed.' );
	}

    $action         = isset( $_POST['redirect_action'] ) ? sanitize_text_field( $_POST['redirect_action'] ) : '';
	$rule_id        = isset( $_POST['rule_id'] ) ? sanitize_text_field( $_POST['rule_id'] ) : ''; // Used for edit/delete
	$redirect_rules = get_option( MABBLE_REDIRECTS_RULES_KEY, array() );
    
    // Create a clean redirect URL, removing all notices and edit IDs
    $redirect_url   = admin_url( 'tools.php?page=' . MABBLE_REDIRECTS_SETTINGS_SLUG );
    $success_msg    = '';
    $error_msg      = '';

    // --- Process DELETE Rule ---
    if ( $action === 'delete' && ! empty( $rule_id ) ) {
        if ( isset( $redirect_rules[ $rule_id ] ) ) {
            unset( $redirect_rules[ $rule_id ] );
            update_option( MABBLE_REDIRECTS_RULES_KEY, $redirect_rules );
            $success_msg = 'The redirect rule was successfully deleted.';
        } else {
            $error_msg = 'Error: The specified redirect rule was not found.';
        }
    }

    // --- Process ADD/EDIT Rule ---
    if ( $action === 'add' || $action === 'edit' ) {
        $new_rule = mabble_sanitize_redirect_rule( $_POST );

        if ( $new_rule ) {
            // Check for duplicate source URLs (cannot have two rules for the same source)
            foreach ( $redirect_rules as $id => $rule ) {
                // Ensure comparison is only done against other rules if in edit mode
                if ( $rule['source_url'] === $new_rule['source_url'] && $id !== $rule_id ) {
                    $error_msg = 'Error: A rule for the source URL <code>' . esc_html( $new_rule['source_url'] ) . '</code> already exists.';
                    break;
                }
            }
            
            if ( empty( $error_msg ) ) {
                if ( $action === 'add' ) {
                    // Create new unique ID
                    $new_id = uniqid( 'rule_', true );
                    $redirect_rules[ $new_id ] = $new_rule;
                    $success_msg = 'New redirect rule created successfully.';
                } elseif ( $action === 'edit' && ! empty( $rule_id ) && isset( $redirect_rules[ $rule_id ] ) ) {
                    $redirect_rules[ $rule_id ] = $new_rule;
                    $success_msg = 'Redirect rule updated successfully.';
                } else {
                    $error_msg = 'Error: Invalid rule ID provided for editing.';
                }

                if ( empty( $error_msg ) ) {
                    update_option( MABBLE_REDIRECTS_RULES_KEY, $redirect_rules );
                }
            }
        } else {
            $error_msg = 'Error: Invalid Source or Target URL provided. Source must be a relative path (e.g., /old-page/) and Target must be a valid URL.';
        }
    }

    // Redirect to show notice
    if ( $success_msg ) {
        $redirect_url = add_query_arg( 'mabble-redirect-success', urlencode( $success_msg ), $redirect_url );
        wp_redirect( $redirect_url );
        exit;
    } elseif ( $error_msg ) {
        // Only show error on the current page if it's not a redirect
        $redirect_url = add_query_arg( 'mabble-redirect-error', urlencode( $error_msg ), $redirect_url );
        wp_redirect( $redirect_url );
        exit;
    }
}

/**
 * Renders success and error notices after processing a form.
 */
function mabble_render_redirect_notices() {
	if ( isset( $_GET['mabble-redirect-success'] ) ) {
		$msg = urldecode( sanitize_text_field( $_GET['mabble-redirect-success'] ) );
		echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> ' . wp_kses_post( $msg ) . '</p></div>';
	}
	if ( isset( $_GET['mabble-redirect-error'] ) ) {
		$msg = urldecode( sanitize_text_field( $_GET['mabble-redirect-error'] ) );
		echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . wp_kses_post( $msg ) . '</p></div>';
	}
}

/**
 * Renders the form for adding/editing redirect rules.
 */
function mabble_render_redirect_form() {
    // Default values for a new rule
    $rule = array(
        'rule_id'     => '',
        'source_url'  => '',
        'target_url'  => '',
        'status_code' => 301,
        'action'      => 'add',
        'submit_text' => 'Add New Redirect Rule',
        'heading'     => 'Create New Redirect Rule',
    );
    
    // Check for edit mode (using GET parameter)
    $is_edit_mode = false;
    if ( isset( $_GET['edit-id'] ) ) {
        $edit_id = sanitize_text_field( $_GET['edit-id'] );
        $redirect_rules = get_option( MABBLE_REDIRECTS_RULES_KEY, array() );
        
        if ( isset( $redirect_rules[ $edit_id ] ) ) {
            $rule_data = $redirect_rules[ $edit_id ];
            $rule['rule_id']     = $edit_id;
            $rule['source_url']  = $rule_data['source_url'];
            $rule['target_url']  = $rule_data['target_url'];
            $rule['status_code'] = $rule_data['status_code'];
            $rule['action']      = 'edit';
            $rule['submit_text'] = 'Update Redirect Rule';
            $rule['heading']     = 'Edit Redirect Rule';
            $is_edit_mode = true;
        }
    }
    
    // Define the style class for highlighting
    $form_class = $is_edit_mode ? 'mabble-edit-highlight' : '';
    ?>

    <h3><?php echo esc_html( $rule['heading'] ); ?></h3>
    <p class="description">Source URL should be the relative path (e.g., <code>/old-page/</code>). Target URL can be a full external URL or a relative path.</p>
    
    <style>
        .mabble-edit-highlight {
            border: 2px solid #007cba !important; /* WordPress Blue */
            box-shadow: 0 0 10px rgba(0, 124, 186, 0.5);
        }
    </style>

    <form method="post" action="<?php echo esc_url( remove_query_arg( 'edit-id', $_SERVER['REQUEST_URI'] ) ); ?>" 
          class="<?php echo esc_attr($form_class); ?>"
          style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; background: #f9f9f9; padding: 15px; border: 1px solid #eee;">
        
        <input type="hidden" name="redirect_action" value="<?php echo esc_attr( $rule['action'] ); ?>">
        <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['rule_id'] ); ?>">
        <?php wp_nonce_field( 'mabble-manage-redirects', 'mabble_redirect_nonce' ); ?>

        <div style="flex-grow: 1;">
            <label for="source_url">Source URL (Path only, e.g., `/old-page/`)</label>
            <input type="text" id="source_url" name="source_url" value="<?php echo esc_attr( $rule['source_url'] ); ?>" required placeholder="/old-page-to-redirect/" class="regular-text" style="width: 100%;">
        </div>

        <div style="flex-grow: 1.5;">
            <label for="target_url">Target URL (Full URL or relative path)</label>
            <input type="text" id="target_url" name="target_url" value="<?php echo esc_attr( $rule['target_url'] ); ?>" required placeholder="https://example.com/new-page/" class="regular-text" style="width: 100%;">
        </div>

        <div>
            <label for="status_code">Status</label>
            <select id="status_code" name="status_code">
                <option value="301" <?php selected( $rule['status_code'], 301 ); ?>>301 Permanent</option>
                <option value="302" <?php selected( $rule['status_code'], 302 ); ?>>302 Temporary</option>
            </select>
        </div>

        <button type="submit" class="button button-primary"><?php echo esc_html( $rule['submit_text'] ); ?></button>
        <?php if ( $rule['action'] === 'edit' ) : ?>
            <a href="<?php echo esc_url( remove_query_arg( 'edit-id', $_SERVER['REQUEST_URI'] ) ); ?>" class="button button-secondary">Cancel Edit</a>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * Renders the table displaying the active redirect rules.
 */
function mabble_render_redirects_table() {
	$redirect_rules = get_option( MABBLE_REDIRECTS_RULES_KEY, array() );
	
    // FIX 3: Use a reliable base URL for actions
    $base_url = admin_url( 'tools.php?page=' . MABBLE_REDIRECTS_SETTINGS_SLUG );
    ?>
    <style>
        .mabble-redirects-table th, .mabble-redirects-table td { padding: 8px 10px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        .mabble-redirects-table th { background-color: #f3f3f3; }
        .mabble-redirects-table code { background: #f0f0f0; padding: 2px 4px; border-radius: 3px; }
    </style>
    
	<table class="wp-list-table widefat fixed striped mabble-redirects-table">
		<thead>
			<tr>
				<th width="35%">Source URL</th>
				<th width="35%">Target URL</th>
				<th width="15%">Status</th>
				<th width="15%">Actions</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $redirect_rules ) ) : ?>
				<?php foreach ( $redirect_rules as $id => $rule ) : 
                    // Create Action URLs
                    $edit_url = add_query_arg( 'edit-id', $id, $base_url );
                ?>
					<tr>
						<td><code><?php echo esc_html( $rule['source_url'] ); ?></code></td>
						<td><a href="<?php echo esc_url( $rule['target_url'] ); ?>" target="_blank"><code><?php echo esc_html( $rule['target_url'] ); ?></code></a></td>
						<td>
                            <?php 
                                echo absint( $rule['status_code'] ); 
                                echo $rule['status_code'] === 301 ? ' (Permanent)' : ' (Temporary)';
                            ?>
                        </td>
						<td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary button-small">Edit</a>
                            
                            <form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:inline-block; margin: 0;">
                                <input type="hidden" name="redirect_action" value="delete">
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr( $id ); ?>">
                                <?php wp_nonce_field( 'mabble-manage-redirects', 'mabble_redirect_nonce' ); ?>
                                <button type="submit" class="button button-small" 
                                    onclick="return confirm('Are you sure you want to delete this redirect rule?');">
                                    Delete
                                </button>
                            </form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="4">No custom redirect rules are currently active.</td> 
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
    <?php
}

// -----------------------------------------------------------
// C. CORE REDIRECTION ENGINE
// -----------------------------------------------------------

/**
 * Checks the current request against all saved rules and executes the redirect if found.
 */
function mabble_execute_custom_redirects() {
    // Check if module is active
    $modules = get_option( 'aaee_modules' );
    if ( empty( $modules['custom_redirects'] ) ) {
        return;
    }

    // Get the full requested URI path (e.g., /old-page/?query=1)
    $requested_uri = esc_url_raw( $_SERVER['REQUEST_URI'] );
    
    // Get the path component, normalized to start with a slash
    $requested_path_only = wp_parse_url( $requested_uri, PHP_URL_PATH );
    $requested_path_only = '/' . ltrim( $requested_path_only, '/' ); 
    
    $redirect_rules = get_option( MABBLE_REDIRECTS_RULES_KEY, array() );

    if ( empty( $redirect_rules ) ) {
        return;
    }

    // Check rules for a direct path match
    foreach ( $redirect_rules as $rule ) {
        // Source URL is stored as a clean path (e.g., /old-page/)
        $source_url_clean = $rule['source_url']; 
        
        // A. EXACT PATH MATCH: Match the requested path against the stored source.
        if ( $requested_path_only === $source_url_clean ) {
            
            $target_url = esc_url( $rule['target_url'] );
            $status_code = absint( $rule['status_code'] );

            // If the target is a relative path, resolve it against the home URL
            if ( strpos( $target_url, 'http' ) !== 0 ) {
                $target_url = home_url( $target_url );
            }
            
            // Execute the redirection and stop WordPress loading
            wp_redirect( $target_url, $status_code ); 
            exit;
        }
    }
}
// FIX 2: Hook with priority 0 to run before WordPress's default rewrite and 404 handling.
add_action( 'template_redirect', 'mabble_execute_custom_redirects', 0 );