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
        add_action( 'admin_post_wp_care_clear_cache', array( $this, 'handle_clear_cache' ) );
        add_action( 'admin_post_wp_care_create_backup', array( $this, 'handle_create_backup' ) );
        add_action( 'admin_post_wp_care_create_temp_login', array( $this, 'handle_create_temp_login' ) );
        add_action( 'admin_post_wp_care_delete_migration', array( $this, 'handle_delete_migration' ) );
        add_action( 'wp_ajax_wp_care_migration_init', array( $this, 'ajax_migration_init' ) );
        add_action( 'wp_ajax_wp_care_migration_chunk', array( $this, 'ajax_migration_chunk' ) );
        add_action( 'wp_ajax_wp_care_migration_cancel', array( $this, 'ajax_migration_cancel' ) );
        add_action( 'wp_ajax_wp_care_migration_download', array( $this, 'ajax_migration_download' ) );
        add_action( 'admin_notices', array( $this, 'show_notices' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
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

        // Submenu - Tools page
        add_submenu_page(
            $this->menu_slug,
            __( 'WP Care Tools', 'wp-care-connector' ),
            __( 'Tools', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug . '-tools',
            array( $this, 'render_tools_page' )
        );

        // Submenu - Site Migration page
        add_submenu_page(
            $this->menu_slug,
            __( 'Site Migration', 'wp-care-connector' ),
            __( 'Site Migration', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug . '-migration',
            array( $this, 'render_migration_page' )
        );

        // Submenu - Health page
        add_submenu_page(
            $this->menu_slug,
            __( 'Site Health', 'wp-care-connector' ),
            __( 'Site Health', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug . '-health',
            array( 'WP_Care_Health_Page', 'render' )
        );

        // Submenu - Activity Log page
        add_submenu_page(
            $this->menu_slug,
            __( 'Activity Log', 'wp-care-connector' ),
            __( 'Activity Log', 'wp-care-connector' ),
            'manage_options',
            $this->menu_slug . '-activity',
            array( $this, 'render_activity_log_page' )
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

        // Enqueue migration JS only on the migration page
        if ( strpos( $hook_suffix, $this->menu_slug . '-migration' ) !== false ) {
            wp_enqueue_script(
                'wp-care-migration',
                WP_CARE_PLUGIN_URL . 'admin/js/migration.js',
                array( 'jquery' ),
                WP_CARE_VERSION,
                true
            );
            wp_localize_script( 'wp-care-migration', 'wpCareMigration', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wp_care_migration' ),
                'strings' => array(
                    'initializing'   => __( 'Initializing export...', 'wp-care-connector' ),
                    'exporting_db'   => __( 'Exporting database...', 'wp-care-connector' ),
                    'scanning'       => __( 'Scanning files...', 'wp-care-connector' ),
                    'archiving'      => __( 'Archiving files...', 'wp-care-connector' ),
                    'finalizing'     => __( 'Finalizing backup...', 'wp-care-connector' ),
                    'complete'       => __( 'Migration backup complete!', 'wp-care-connector' ),
                    'error'          => __( 'Export failed', 'wp-care-connector' ),
                    'confirm_cancel' => __( 'Are you sure you want to cancel the export?', 'wp-care-connector' ),
                ),
            ) );
        }
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
        $category = isset( $_POST['support_category'] ) ? sanitize_text_field( wp_unslash( $_POST['support_category'] ) ) : 'other';
        $subject = isset( $_POST['support_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['support_subject'] ) ) : '';
        $message = isset( $_POST['support_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['support_message'] ) ) : '';
        $priority = isset( $_POST['support_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['support_priority'] ) ) : 'normal';

        // Validate category
        $valid_categories = array( 'speed', 'security', 'broken-feature', 'content', 'other' );
        if ( ! in_array( $category, $valid_categories, true ) ) {
            $category = 'other';
        }

        if ( empty( $subject ) || empty( $message ) ) {
            $this->redirect_with_notice( 'error', __( 'Please fill in all required fields.', 'wp-care-connector' ) );
            return;
        }

        if ( strlen( $message ) < 20 ) {
            $this->redirect_with_notice( 'error', __( 'Description must be at least 20 characters.', 'wp-care-connector' ) );
            return;
        }

        // Capture fresh site context at submission time
        $site_mapper = new WP_Care_Site_Mapper();
        $site_context = $site_mapper->get_site_map( true ); // Force refresh

        // Get current user
        $current_user = wp_get_current_user();

        // Handle screenshot upload
        $screenshot_url = '';
        if ( ! empty( $_FILES['support_screenshot']['name'] ) ) {
            // Validate MIME type server-side (client accept="image/*" is easily bypassed)
            $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
            $finfo         = finfo_open( FILEINFO_MIME_TYPE );
            $mime_type      = finfo_file( $finfo, $_FILES['support_screenshot']['tmp_name'] );
            finfo_close( $finfo );

            if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
                wp_safe_redirect( add_query_arg( 'wpcare_error', 'invalid_file_type', wp_get_referer() ) );
                exit;
            }

            // Enforce 5MB size limit
            if ( $_FILES['support_screenshot']['size'] > 5 * 1024 * 1024 ) {
                wp_safe_redirect( add_query_arg( 'wpcare_error', 'file_too_large', wp_get_referer() ) );
                exit;
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $attachment_id = media_handle_upload( 'support_screenshot', 0 );
            if ( ! is_wp_error( $attachment_id ) ) {
                $screenshot_url = wp_get_attachment_url( $attachment_id );
            }
        }

        // Build support request
        $request = array(
            'id'             => wp_generate_uuid4(),
            'category'       => $category,
            'subject'        => $subject,
            'message'        => $message,
            'priority'       => $priority,
            'screenshot_url' => $screenshot_url,
            'user_email'     => $current_user->user_email,
            'user_name'      => $current_user->display_name,
            'site_context'   => $site_context,
            'submitted_at'   => current_time( 'mysql' ),
            'status'         => 'pending',
        );

        // Store request locally (will be synced to central API)
        $requests = get_option( $this->support_requests_key, array() );
        $requests[] = $request;
        update_option( $this->support_requests_key, $requests, false );

        // Log the activity
        WP_Care_Activity_Log::log( 'support_submitted', array(
            'subject'  => $subject,
            'category' => $category,
            'priority' => $priority,
        ) );

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
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Connect to Platform', 'wp-care-connector' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_care_consent" value="1"
                                       <?php checked( get_option( 'wp_care_consent', false ) ); ?>>
                                <?php esc_html_e( 'I consent to connect this site to the WP Care Platform', 'wp-care-connector' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, the following data will be transmitted to the WP Care Platform:', 'wp-care-connector' ); ?>
                            </p>
                            <ul style="list-style: disc; margin-left: 20px; color: #666;">
                                <li><?php esc_html_e( 'Site URL and name', 'wp-care-connector' ); ?></li>
                                <li><?php esc_html_e( 'WordPress and PHP versions', 'wp-care-connector' ); ?></li>
                                <li><?php esc_html_e( 'Active theme and plugins', 'wp-care-connector' ); ?></li>
                                <li><?php esc_html_e( 'Content statistics (post/page counts)', 'wp-care-connector' ); ?></li>
                            </ul>
                            <p class="description">
                                <?php esc_html_e( 'No passwords, personal data, or content is ever transmitted.', 'wp-care-connector' ); ?>
                            </p>
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
     * Render the tools page.
     *
     * Provides standalone site management tools that work without API connection.
     *
     * @return void
     */
    public function render_tools_page() {
        // Get backup list
        $backup = new WP_Care_Backup();
        $checkpoints = $backup->list_checkpoints();

        // Get active temp users
        $temp_login = new WP_Care_Temp_Login();
        $temp_users = $temp_login->get_active_temp_users();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Care Tools', 'wp-care-connector' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Standalone site management tools. These work independently of the WP Care API.', 'wp-care-connector' ); ?></p>

            <?php
            // Display temp login URL if just generated
            $temp_login_url = get_transient( 'wp_care_temp_login_url' );
            if ( $temp_login_url ) :
                delete_transient( 'wp_care_temp_login_url' );
            ?>
            <div class="notice notice-info" style="padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Temporary Login Link Generated', 'wp-care-connector' ); ?></h3>
                <p><?php esc_html_e( 'Copy this link and share it with whoever needs temporary admin access. It expires in 4 hours and works only once.', 'wp-care-connector' ); ?></p>
                <input type="text" value="<?php echo esc_url( $temp_login_url ); ?>" readonly
                       style="width: 100%; max-width: 600px; padding: 10px; font-family: monospace;"
                       onclick="this.select();">
                <p><button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $temp_login_url ); ?>'); this.textContent='Copied!';">
                    <?php esc_html_e( 'Copy to Clipboard', 'wp-care-connector' ); ?>
                </button></p>
            </div>
            <?php endif; ?>

            <div class="wp-care-tools-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">

                <!-- Cache Clearing -->
                <div class="wp-care-tool-card card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-performance" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Clear All Caches', 'wp-care-connector' ); ?>
                    </h2>
                    <p><?php esc_html_e( 'Clear caches from WordPress, page builders, and popular caching plugins (W3 Total Cache, WP Super Cache, LiteSpeed, WP Rocket, and more).', 'wp-care-connector' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wp_care_clear_cache', '_wpnonce' ); ?>
                        <input type="hidden" name="action" value="wp_care_clear_cache">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                            <?php esc_html_e( 'Clear All Caches', 'wp-care-connector' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Database Backup -->
                <div class="wp-care-tool-card card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-database" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Database Backup', 'wp-care-connector' ); ?>
                    </h2>
                    <p><?php esc_html_e( 'Create a checkpoint backup of your database. Useful before making changes or testing updates.', 'wp-care-connector' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wp_care_create_backup', '_wpnonce' ); ?>
                        <input type="hidden" name="action" value="wp_care_create_backup">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-backup" style="vertical-align: middle;"></span>
                            <?php esc_html_e( 'Create Backup', 'wp-care-connector' ); ?>
                        </button>
                    </form>
                    <?php if ( ! empty( $checkpoints ) ) : ?>
                        <h4 style="margin-bottom: 5px;"><?php esc_html_e( 'Recent Backups', 'wp-care-connector' ); ?></h4>
                        <ul style="margin: 0; font-size: 12px;">
                            <?php foreach ( array_slice( $checkpoints, 0, 3 ) as $cp ) : ?>
                                <li>
                                    <code><?php echo esc_html( $cp['id'] ); ?></code>
                                    <span style="color: #666;">(<?php echo esc_html( human_time_diff( $cp['created_at'] ) ); ?> ago)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Temporary Login -->
                <div class="wp-care-tool-card card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-admin-users" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Temporary Admin Login', 'wp-care-connector' ); ?>
                    </h2>
                    <p><?php esc_html_e( 'Generate a secure temporary admin login link. Perfect for giving support access without sharing your password. Expires in 4 hours.', 'wp-care-connector' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wp_care_create_temp_login', '_wpnonce' ); ?>
                        <input type="hidden" name="action" value="wp_care_create_temp_login">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-admin-network" style="vertical-align: middle;"></span>
                            <?php esc_html_e( 'Generate Login Link', 'wp-care-connector' ); ?>
                        </button>
                    </form>
                    <?php if ( ! empty( $temp_users ) ) : ?>
                        <h4 style="margin-bottom: 5px;"><?php esc_html_e( 'Active Temp Users', 'wp-care-connector' ); ?></h4>
                        <ul style="margin: 0; font-size: 12px;">
                            <?php foreach ( $temp_users as $user ) : ?>
                                <li>
                                    <?php echo esc_html( $user['username'] ); ?>
                                    <span style="color: #666;">(expires <?php echo esc_html( human_time_diff( $user['expires'] ) ); ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Site Migration -->
                <div class="wp-care-tool-card card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-migrate" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Site Migration', 'wp-care-connector' ); ?>
                    </h2>
                    <p><?php esc_html_e( 'Create a full site backup (database + files) for migration to a new host or domain.', 'wp-care-connector' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-migration' ) ); ?>" class="button button-primary">
                        <span class="dashicons dashicons-migrate" style="vertical-align: middle;"></span>
                        <?php esc_html_e( 'Go to Migration', 'wp-care-connector' ); ?>
                    </a>
                </div>

                <!-- Site Health Export -->
                <div class="wp-care-tool-card card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-download" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Export Site Info', 'wp-care-connector' ); ?>
                    </h2>
                    <p><?php esc_html_e( 'Download your site information as JSON. Useful for support requests or documentation.', 'wp-care-connector' ); ?></p>
                    <a href="<?php echo esc_url( rest_url( 'wp-care/v1/ping' ) ); ?>" class="button" target="_blank">
                        <span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
                        <?php esc_html_e( 'View Site Info', 'wp-care-connector' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-care-connector-status' ) ); ?>" class="button">
                        <?php esc_html_e( 'Full Status Page', 'wp-care-connector' ); ?>
                    </a>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Handle cache clearing.
     *
     * @return void
     */
    public function handle_clear_cache() {
        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_care_clear_cache' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        // Use the API endpoint's cache clearing logic
        $api = WP_Care_API_Endpoints::instance();
        $result = $api->cmd_clear_cache( array() );

        $cleared = implode( ', ', $result['cleared'] );
        $message = sprintf(
            __( 'Caches cleared successfully: %s', 'wp-care-connector' ),
            $cleared
        );

        WP_Care_Activity_Log::log( 'cache_cleared', array( 'cleared' => $result['cleared'] ) );

        $this->redirect_with_notice( 'success', $message );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-tools' ) );
        exit;
    }

    /**
     * Handle backup creation.
     *
     * @return void
     */
    public function handle_create_backup() {
        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_care_create_backup' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        $backup = new WP_Care_Backup();
        $checkpoint_id = $backup->create_checkpoint( 'manual_admin' );

        if ( $checkpoint_id === false ) {
            $this->redirect_with_notice( 'error', __( 'Failed to create backup. Check error logs.', 'wp-care-connector' ) );
        } else {
            WP_Care_Activity_Log::log( 'backup_created', array( 'checkpoint_id' => $checkpoint_id ) );
            $message = sprintf(
                __( 'Backup created successfully: %s', 'wp-care-connector' ),
                $checkpoint_id
            );
            $this->redirect_with_notice( 'success', $message );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-tools' ) );
        exit;
    }

    /**
     * Handle temporary login creation.
     *
     * @return void
     */
    public function handle_create_temp_login() {
        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_care_create_temp_login' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        $temp_login = new WP_Care_Temp_Login();
        $current_user = wp_get_current_user();
        $result = $temp_login->create_login( $current_user->user_login );

        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( 'error', $result->get_error_message() );
        } else {
            WP_Care_Activity_Log::log( 'temp_login_created', array( 'created_by' => $current_user->user_login ) );
            // Store the login URL in a transient so we can display it
            set_transient( 'wp_care_temp_login_url', $result, 60 );
            $this->redirect_with_notice( 'success', __( 'Temporary login created! The link is shown below.', 'wp-care-connector' ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-tools' ) );
        exit;
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

        // Save consent preference
        $consent = isset( $_POST['wp_care_consent'] ) && $_POST['wp_care_consent'] === '1';
        update_option( 'wp_care_consent', $consent );

        // Generate API key if not exists
        if ( ! WP_Care_Security::has_api_key() ) {
            WP_Care_Security::generate_api_key();
        }

        // Only register with central API if user has given explicit consent
        $message = __( 'Settings saved successfully.', 'wp-care-connector' );
        if ( $consent && ! empty( $api_url ) && WP_Care_Security::has_api_key() ) {
            $result = WP_Care_API_Endpoints::register_with_central_api();
            if ( is_wp_error( $result ) ) {
                $message = sprintf(
                    __( 'Settings saved. Registration failed: %s', 'wp-care-connector' ),
                    $result->get_error_message()
                );
            } else {
                $message = __( 'Settings saved and site registered with API.', 'wp-care-connector' );
            }
        } elseif ( ! $consent && ! empty( $api_url ) ) {
            $message = __( 'Settings saved. Check the consent box to connect to the platform.', 'wp-care-connector' );
        }

        WP_Care_Activity_Log::log( 'settings_saved', array( 'api_url' => $api_url, 'consent' => $consent ) );

        // Redirect with success
        $this->redirect_with_notice( 'success', $message );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings' ) );
        exit;
    }

    /**
     * Register the dashboard widget.
     *
     * @return void
     */
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'wp_care_dashboard_widget',
            __( 'WP Care', 'wp-care-connector' ),
            array( $this, 'render_dashboard_widget' )
        );
    }

    /**
     * Render the WP Care dashboard widget.
     *
     * Shows connection status, last health report, and quick links.
     *
     * @return void
     */
    public function render_dashboard_widget() {
        $connection = $this->check_connection_status();
        $last_report = get_option( 'wp_care_last_health_report', 0 );
        $pending_requests = count( get_option( $this->support_requests_key, array() ) );
        ?>
        <div class="wp-care-widget">
            <div style="display: flex; align-items: center; margin-bottom: 12px;">
                <span class="dashicons dashicons-<?php echo $connection['connected'] ? 'yes-alt' : 'warning'; ?>"
                      style="color: <?php echo $connection['connected'] ? '#00a32a' : '#d63638'; ?>; font-size: 20px; margin-right: 8px;"></span>
                <strong><?php echo esc_html( $connection['message'] ); ?></strong>
            </div>

            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 4px 0; color: #666;"><?php esc_html_e( 'Last Health Report', 'wp-care-connector' ); ?></td>
                    <td style="padding: 4px 0; text-align: right;">
                        <?php if ( $last_report ) : ?>
                            <?php echo esc_html( human_time_diff( $last_report ) ); ?> <?php esc_html_e( 'ago', 'wp-care-connector' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Never', 'wp-care-connector' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 4px 0; color: #666;"><?php esc_html_e( 'Pending Requests', 'wp-care-connector' ); ?></td>
                    <td style="padding: 4px 0; text-align: right;">
                        <?php echo esc_html( $pending_requests ); ?>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ); ?>" class="button button-primary" style="margin-right: 8px;">
                    <?php esc_html_e( 'Get Help', 'wp-care-connector' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-health' ) ); ?>" class="button">
                    <?php esc_html_e( 'Site Health', 'wp-care-connector' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render the site migration page.
     *
     * @return void
     */
    public function render_migration_page() {
        $migration  = new WP_Care_Migration();
        $migrations = $migration->list_migrations();
        include WP_CARE_PLUGIN_DIR . 'admin/views/migration-page.php';
    }

    /**
     * AJAX handler: Initialize migration export.
     *
     * @return void
     */
    public function ajax_migration_init() {
        check_ajax_referer( 'wp_care_migration' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-care-connector' ) ), 403 );
        }

        $options = isset( $_POST['options'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['options'] ) ) : array();

        // Convert string "true"/"false" to boolean
        foreach ( $options as $key => $value ) {
            $options[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
        }

        $migration = new WP_Care_Migration();
        $state = $migration->init_export( $options );

        if ( ! $state ) {
            wp_send_json_error( array( 'message' => __( 'Failed to initialize migration. Check directory permissions.', 'wp-care-connector' ) ) );
        }

        wp_send_json_success( $state );
    }

    /**
     * AJAX handler: Process migration chunk.
     *
     * @return void
     */
    public function ajax_migration_chunk() {
        check_ajax_referer( 'wp_care_migration' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-care-connector' ) ), 403 );
        }

        $migration_id = isset( $_POST['migration_id'] ) ? sanitize_file_name( wp_unslash( $_POST['migration_id'] ) ) : '';

        if ( empty( $migration_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Migration ID is required.', 'wp-care-connector' ) ) );
        }

        $migration = new WP_Care_Migration();
        $state = $migration->process_chunk( $migration_id );

        if ( $state['completed'] ) {
            WP_Care_Activity_Log::log( 'migration_created', array(
                'migration_id' => $migration_id,
                'size'         => isset( $state['archive_size_human'] ) ? $state['archive_size_human'] : '',
            ) );
        }

        wp_send_json_success( $state );
    }

    /**
     * AJAX handler: Cancel migration export.
     *
     * @return void
     */
    public function ajax_migration_cancel() {
        check_ajax_referer( 'wp_care_migration' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-care-connector' ) ), 403 );
        }

        $migration_id = isset( $_POST['migration_id'] ) ? sanitize_file_name( wp_unslash( $_POST['migration_id'] ) ) : '';

        if ( ! empty( $migration_id ) ) {
            $migration = new WP_Care_Migration();
            $migration->delete_migration( $migration_id );
        }

        wp_send_json_success( array( 'message' => __( 'Export cancelled.', 'wp-care-connector' ) ) );
    }

    /**
     * AJAX handler: Download migration file.
     *
     * @return void
     */
    public function ajax_migration_download() {
        check_ajax_referer( 'wp_care_migration_download', '_wpnonce', false ) || check_ajax_referer( 'wp_care_migration' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        $migration_id = isset( $_GET['id'] ) ? sanitize_file_name( wp_unslash( $_GET['id'] ) ) : '';

        if ( empty( $migration_id ) ) {
            wp_die( esc_html__( 'Migration ID is required.', 'wp-care-connector' ) );
        }

        $migration = new WP_Care_Migration();
        $file_path = $migration->get_download_path( $migration_id );

        if ( ! $file_path ) {
            wp_die( esc_html__( 'Migration file not found.', 'wp-care-connector' ) );
        }

        WP_Care_Activity_Log::log( 'migration_downloaded', array( 'migration_id' => $migration_id ) );

        $info     = $migration->get_migration_info( $migration_id );
        $filename = sanitize_file_name(
            wp_parse_url( get_site_url(), PHP_URL_HOST ) . '-migration-' . gmdate( 'Y-m-d' ) . '.zip'
        );

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        readfile( $file_path );
        exit;
    }

    /**
     * Handle migration deletion.
     *
     * @return void
     */
    public function handle_delete_migration() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_care_delete_migration' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-care-connector' ), '', array( 'response' => 403 ) );
        }

        $migration_id = isset( $_POST['migration_id'] ) ? sanitize_file_name( wp_unslash( $_POST['migration_id'] ) ) : '';

        if ( ! empty( $migration_id ) ) {
            $migration = new WP_Care_Migration();
            $deleted = $migration->delete_migration( $migration_id );

            if ( $deleted ) {
                WP_Care_Activity_Log::log( 'migration_deleted', array( 'migration_id' => $migration_id ) );
                $this->redirect_with_notice( 'success', __( 'Migration backup deleted.', 'wp-care-connector' ) );
            } else {
                $this->redirect_with_notice( 'error', __( 'Migration backup not found.', 'wp-care-connector' ) );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-migration' ) );
        exit;
    }

    /**
     * Render the activity log page.
     *
     * @return void
     */
    public function render_activity_log_page() {
        $entries = WP_Care_Activity_Log::get_entries();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Activity Log', 'wp-care-connector' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Recent actions performed by WP Care on this site.', 'wp-care-connector' ); ?></p>

            <?php if ( empty( $entries ) ) : ?>
                <div class="notice notice-info inline" style="margin-top: 20px;">
                    <p><?php esc_html_e( 'No activity logged yet.', 'wp-care-connector' ); ?></p>
                </div>
            <?php else : ?>
                <table class="widefat striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'wp-care-connector' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'wp-care-connector' ); ?></th>
                            <th><?php esc_html_e( 'User', 'wp-care-connector' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'wp-care-connector' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['timestamp'] ) ); ?>
                                <br><small style="color: #666;"><?php echo esc_html( human_time_diff( $entry['timestamp'] ) ); ?> <?php esc_html_e( 'ago', 'wp-care-connector' ); ?></small>
                            </td>
                            <td><strong><?php echo esc_html( WP_Care_Activity_Log::get_action_label( $entry['action'] ) ); ?></strong></td>
                            <td><?php echo esc_html( $entry['user'] ); ?></td>
                            <td>
                                <?php
                                if ( ! empty( $entry['details'] ) ) {
                                    $details = array();
                                    foreach ( $entry['details'] as $key => $value ) {
                                        if ( is_array( $value ) ) {
                                            $value = implode( ', ', $value );
                                        }
                                        $details[] = esc_html( $key ) . ': ' . esc_html( $value );
                                    }
                                    echo implode( '<br>', $details );
                                } else {
                                    echo '&mdash;';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top: 10px;">
                    <?php
                    printf(
                        esc_html__( 'Showing last %d entries. Older entries are automatically removed.', 'wp-care-connector' ),
                        WP_Care_Activity_Log::MAX_ENTRIES
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
