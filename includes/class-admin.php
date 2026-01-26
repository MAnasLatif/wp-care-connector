<?php
/**
 * WP Care Admin Class
 *
 * Thin wrapper for WordPress admin integration. Handles menu registration,
 * admin page rendering, and form submission handling. Core functionality
 * lives in other classes (Security, SiteMapper, etc.) to support CLI-only operation.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

// Security check: prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_Care_Admin
 *
 * Admin UI integration:
 * - Menu page registration
 * - Support form with context capture
 * - Status page display
 * - Admin notice handling
 */
class WP_Care_Admin {

    /**
     * Menu slug for the main admin page.
     *
     * @var string
     */
    private $menu_slug = 'wp-care-connector';

    /**
     * Option key for storing support requests.
     *
     * @var string
     */
    private $support_requests_key = 'wp_care_support_requests';

    /**
     * Nonce action for support form.
     *
     * @var string
     */
    private $nonce_action = 'wp_care_support_form';

    /**
     * Constructor - registers admin hooks.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_wp_care_support_request', array( $this, 'handle_support_submission' ) );
        add_action( 'admin_post_wp_care_save_settings', array( $this, 'handle_settings_save' ) );
        add_action( 'admin_notices', array( $this, 'show_notices' ) );
    }

    /**
     * Register admin menu pages.
     *
     * Creates main menu item with support form as default page,
     * and status page as submenu.
     *
     * @return void
     */
    public function register_menu() {
        // Main menu page - Get Help form
        add_menu_page(
            __( 'WP Care Help', 'wp-care-connector' ),
            __( 'Get Help', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug,
            array( $this, 'render_support_form' ),
            'dashicons-sos',
            80
        );

        // Submenu - Status page
        add_submenu_page(
            $this->menu_slug,
            __( 'WP Care Status', 'wp-care-connector' ),
            __( 'Status', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug . '-status',
            array( $this, 'render_status_page' )
        );

        // Submenu - Settings page
        add_submenu_page(
            $this->menu_slug,
            __( 'WP Care Settings', 'wp-care-connector' ),
            __( 'Settings', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug . '-settings',
            array( $this, 'render_settings_page' )
        );

        // Rename the first submenu item from "WP Care Help" to "Get Help"
        global $submenu;
        if ( isset( $submenu[ $this->menu_slug ] ) ) {
            $submenu[ $this->menu_slug ][0][0] = __( 'Get Help', 'wp-care-connector' );
        }
    }

    /**
     * Enqueue admin CSS only on plugin pages.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets( $hook_suffix ) {
        // Only load on our plugin pages
        if ( strpos( $hook_suffix, $this->menu_slug ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wp-care-admin',
            WP_CARE_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            WP_CARE_VERSION
        );
    }

    /**
     * Render the support request form.
     *
     * Captures site context before displaying form so support agents
     * have full diagnostic information when request is submitted.
     *
     * @return void
     */
    public function render_support_form() {
        // Capture current site context
        $site_mapper = new WP_Care_Site_Mapper();
        $site_context = $site_mapper->get_site_map();

        // Get current user info
        $current_user = wp_get_current_user();

        // Pass data to template
        $form_data = array(
            'nonce_action' => $this->nonce_action,
            'nonce_field'  => wp_nonce_field( $this->nonce_action, '_wpnonce', true, false ),
            'form_action'  => admin_url( 'admin-post.php' ),
            'user_email'   => $current_user->user_email,
            'user_name'    => $current_user->display_name,
            'site_context' => $site_context,
        );

        // Include template
        include WP_CARE_PLUGIN_DIR . 'admin/views/support-form.php';
    }

    /**
     * Render the plugin status page.
     *
     * Displays API key (masked), connection status, and site diagnostics.
     *
     * @return void
     */
    public function render_status_page() {
        // Get status information from Security class
        $status_data = array(
            'api_key_display'  => WP_Care_Security::get_key_display(),
            'has_api_key'      => WP_Care_Security::has_api_key(),
            'plugin_version'   => WP_CARE_VERSION,
            'rest_url'         => rest_url( 'wp-care/v1/' ),
        );

        // Get site map for additional context
        $site_mapper = new WP_Care_Site_Mapper();
        $status_data['site_info'] = $site_mapper->get_site_map();

        // Check connection to central API (simple health check)
        $status_data['connection_status'] = $this->check_connection_status();

        // Get pending support requests count
        $requests = get_option( $this->support_requests_key, array() );
        $status_data['pending_requests'] = count( $requests );

        // Include template
        include WP_CARE_PLUGIN_DIR . 'admin/views/status-page.php';
    }

    /**
     * Handle support form submission.
     *
     * Validates form data, captures context, and stores request.
     * Redirects back to form with success/error message.
     *
     * @return void
     */
    public function handle_support_submission() {
        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $this->nonce_action ) ) {
            wp_die(
                esc_html__( 'Security check failed. Please try again.', 'wp-care-connector' ),
                esc_html__( 'Error', 'wp-care-connector' ),
                array( 'response' => 403, 'back_link' => true )
            );
        }

        // Check user capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to submit support requests.', 'wp-care-connector' ),
                esc_html__( 'Error', 'wp-care-connector' ),
                array( 'response' => 403, 'back_link' => true )
            );
        }

        // Validate required fields
        $subject = isset( $_POST['support_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['support_subject'] ) ) : '';
        $message = isset( $_POST['support_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['support_message'] ) ) : '';
        $priority = isset( $_POST['support_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['support_priority'] ) ) : 'normal';

        if ( empty( $subject ) || empty( $message ) ) {
            $this->redirect_with_notice( 'error', __( 'Please fill in all required fields.', 'wp-care-connector' ) );
            return;
        }

        // Capture fresh site context at submission time
        $site_mapper = new WP_Care_Site_Mapper();
        $site_context = $site_mapper->get_site_map( true ); // Force refresh

        // Get current user
        $current_user = wp_get_current_user();

        // Build support request
        $request = array(
            'id'           => wp_generate_uuid4(),
            'subject'      => $subject,
            'message'      => $message,
            'priority'     => $priority,
            'user_email'   => $current_user->user_email,
            'user_name'    => $current_user->display_name,
            'site_context' => $site_context,
            'submitted_at' => current_time( 'mysql' ),
            'status'       => 'pending',
        );

        // Store request locally (will be synced to central API)
        $requests = get_option( $this->support_requests_key, array() );
        $requests[] = $request;
        update_option( $this->support_requests_key, $requests, false );

        // Log the submission
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[WP Care] Support request submitted: %s (ID: %s)',
                $subject,
                $request['id']
            ) );
        }

        // Redirect with success
        $this->redirect_with_notice( 'success', __( 'Your support request has been submitted successfully.', 'wp-care-connector' ) );
    }

    /**
     * Display admin notices from transient.
     *
     * Shows success or error messages after form submission.
     *
     * @return void
     */
    public function show_notices() {
        // Check for notice transient
        $notice = get_transient( 'wp_care_admin_notice' );

        if ( ! $notice ) {
            return;
        }

        // Delete transient immediately
        delete_transient( 'wp_care_admin_notice' );

        // Determine notice class
        $class = 'notice notice-' . esc_attr( $notice['type'] );
        if ( $notice['type'] === 'success' ) {
            $class .= ' is-dismissible';
        }

        // Output notice
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr( $class ),
            esc_html( $notice['message'] )
        );
    }

    /**
     * Check connection status to central API.
     *
     * Performs a simple connectivity check.
     *
     * @return array Connection status with 'connected' bool and 'message' string.
     */
    private function check_connection_status() {
        // For now, return status based on API key existence
        // Full connectivity check will be implemented when central API is ready
        if ( ! WP_Care_Security::has_api_key() ) {
            return array(
                'connected' => false,
                'message'   => __( 'API key not configured', 'wp-care-connector' ),
            );
        }

        return array(
            'connected' => true,
            'message'   => __( 'Ready for connection', 'wp-care-connector' ),
        );
    }

    /**
     * Redirect with admin notice.
     *
     * Sets transient notice and redirects back to support form.
     *
     * @param string $type    Notice type: 'success' or 'error'.
     * @param string $message The message to display.
     * @return void
     */
    private function redirect_with_notice( $type, $message ) {
        // Set notice transient (expires in 30 seconds)
        set_transient( 'wp_care_admin_notice', array(
            'type'    => $type,
            'message' => $message,
        ), 30 );

        // Redirect back to form
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug ) );
        exit;
    }

    /**
     * Get all pending support requests.
     *
     * @return array Array of support request data.
     */
    public function get_pending_requests() {
        return get_option( $this->support_requests_key, array() );
    }

    /**
     * Clear a support request by ID.
     *
     * @param string $request_id The request UUID to clear.
     * @return bool True if request was found and cleared.
     */
    public function clear_request( $request_id ) {
        $requests = get_option( $this->support_requests_key, array() );
        $found = false;

        foreach ( $requests as $key => $request ) {
            if ( isset( $request['id'] ) && $request['id'] === $request_id ) {
                unset( $requests[ $key ] );
                $found = true;
                break;
            }
        }

        if ( $found ) {
            // Re-index array and save
            update_option( $this->support_requests_key, array_values( $requests ), false );
        }

        return $found;
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        $api_url = get_option( 'wp_care_api_url', '' );
        $api_key = WP_Care_Security::get_key_display();
        $has_key = WP_Care_Security::has_api_key();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Care Settings', 'wp-care-connector' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wp_care_settings', '_wpnonce' ); ?>
                <input type="hidden" name="action" value="wp_care_save_settings">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wp_care_api_url"><?php esc_html_e( 'API URL', 'wp-care-connector' ); ?></label>
                        </th>
                        <td>
                            <input type="url" name="wp_care_api_url" id="wp_care_api_url"
                                   value="<?php echo esc_attr( $api_url ); ?>"
                                   class="regular-text"
                                   placeholder="http://167.172.60.154:3000">
                            <p class="description">
                                <?php esc_html_e( 'The URL of your WP Care central API server.', 'wp-care-connector' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'API Key', 'wp-care-connector' ); ?>
                        </th>
                        <td>
                            <code style="font-size: 14px; padding: 5px 10px; background: #f0f0f0;">
                                <?php echo esc_html( $has_key ? $api_key : __( 'Not generated', 'wp-care-connector' ) ); ?>
                            </code>
                            <?php if ( ! $has_key ) : ?>
                                <p class="description" style="color: #d63638;">
                                    <?php esc_html_e( 'API key will be generated automatically when you save settings.', 'wp-care-connector' ); ?>
                                </p>
                            <?php else : ?>
                                <p class="description">
                                    <?php esc_html_e( 'This key authenticates your site with the central API.', 'wp-care-connector' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'wp-care-connector' ) ); ?>
            </form>

            <?php if ( $api_url && $has_key ) : ?>
            <hr>
            <h2><?php esc_html_e( 'Connection Test', 'wp-care-connector' ); ?></h2>
            <p>
                <button type="button" class="button" id="wp-care-test-connection">
                    <?php esc_html_e( 'Test Connection', 'wp-care-connector' ); ?>
                </button>
                <span id="wp-care-test-result" style="margin-left: 10px;"></span>
            </p>
            <script>
            document.getElementById('wp-care-test-connection').addEventListener('click', function() {
                var resultSpan = document.getElementById('wp-care-test-result');
                resultSpan.textContent = '<?php esc_html_e( 'Testing...', 'wp-care-connector' ); ?>';
                resultSpan.style.color = '#666';

                fetch('<?php echo esc_url( rest_url( 'wp-care/v1/health' ) ); ?>', {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        resultSpan.textContent = '✓ <?php esc_html_e( 'Plugin endpoint working!', 'wp-care-connector' ); ?>';
                        resultSpan.style.color = '#00a32a';
                    } else {
                        resultSpan.textContent = '✗ <?php esc_html_e( 'Unexpected response', 'wp-care-connector' ); ?>';
                        resultSpan.style.color = '#d63638';
                    }
                })
                .catch(function(err) {
                    resultSpan.textContent = '✗ <?php esc_html_e( 'Connection failed', 'wp-care-connector' ); ?>: ' + err.message;
                    resultSpan.style.color = '#d63638';
                });
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle settings form submission.
     *
     * @return void
     */
    public function handle_settings_save() {
        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_care_settings' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        // Check capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        // Save API URL
        $api_url = isset( $_POST['wp_care_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['wp_care_api_url'] ) ) : '';
        update_option( 'wp_care_api_url', $api_url );

        // Generate API key if not exists
        if ( ! WP_Care_Security::has_api_key() ) {
            WP_Care_Security::generate_api_key();
        }

        // Redirect with success
        $this->redirect_with_notice( 'success', __( 'Settings saved successfully.', 'wp-care-connector' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings' ) );
        exit;
    }
}
