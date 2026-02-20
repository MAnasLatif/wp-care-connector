<?php
/**
 * WP Care Temp Login - Temporary admin access system
 *
 * Provides time-limited administrator access with full audit logging.
 * Temp users automatically expire after 4 hours and are cleaned up.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_Care_Temp_Login
 *
 * Creates temporary admin users with secure token-based login links.
 * All events are logged for audit trail.
 */
class WP_Care_Temp_Login {

    /**
     * Option key for storing temp login audit log.
     *
     * @var string
     */
    private $log_option = 'wp_care_temp_login_log';

    /**
     * Number of hours until temp login expires.
     *
     * @var int
     */
    private $expiry_hours = 4;

    /**
     * Constructor.
     *
     * Sets up hooks for authentication and cleanup.
     */
    public function __construct() {
        // Hook for login validation - runs early to catch login requests
        add_action( 'init', array( $this, 'maybe_authenticate' ), 1 );

        // Hook for cron cleanup of individual temp users
        add_action( 'wp_care_cleanup_temp_user', array( $this, 'cleanup_user' ) );

        // Hook for expired user check on every page load (fallback for missed crons)
        add_action( 'init', array( $this, 'cleanup_expired_users' ), 5 );
    }

    /**
     * Create a temporary admin login.
     *
     * Generates a secure token and creates a temporary administrator user
     * that expires after the configured number of hours.
     *
     * @param string $requester_id Identifier of who requested the login (e.g., support agent ID).
     * @return string|WP_Error Login URL on success, WP_Error on failure.
     */
    public function create_login( $requester_id = '' ) {
        // Generate secure 64-character token
        $token = wp_generate_password( 64, false, false );

        // Create unique username
        $username = 'wp_care_temp_' . wp_generate_password( 8, false, false );

        // Calculate expiry timestamp
        $expiry = time() + ( $this->expiry_hours * HOUR_IN_SECONDS );

        // Create the temporary user
        $user_data = array(
            'user_login'   => $username,
            'user_pass'    => wp_generate_password( 24, true, true ),
            'user_email'   => $username . '@temp.wp-care.local',
            'role'         => 'administrator',
            'display_name' => 'WP Care Support',
            'first_name'   => 'WP Care',
            'last_name'    => 'Support',
        );

        $user_id = wp_insert_user( $user_data );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Store metadata for token validation and cleanup
        update_user_meta( $user_id, '_wp_care_temp_user', true );
        update_user_meta( $user_id, '_wp_care_token_hash', wp_hash_password( $token ) );
        update_user_meta( $user_id, '_wp_care_expiry', $expiry );
        update_user_meta( $user_id, '_wp_care_requester', sanitize_text_field( $requester_id ) );
        update_user_meta( $user_id, '_wp_care_created', time() );

        // Schedule cleanup at expiry time
        wp_schedule_single_event( $expiry, 'wp_care_cleanup_temp_user', array( $user_id ) );

        // Log the creation event
        $this->log_event( 'created', $user_id, $requester_id );

        // Send email notification to site admin (SEC-05)
        $this->notify_admin_temp_login_created( $username, $expiry, $requester_id );

        // Build and return the login URL
        $login_url = add_query_arg(
            array(
                'wp-care-login' => $token,
                'uid'           => $user_id,
            ),
            site_url()
        );

        return $login_url;
    }

    /**
     * Handle login link authentication.
     *
     * Validates the token and user, sets authentication cookie,
     * and redirects to admin dashboard.
     *
     * @return void
     */
    public function maybe_authenticate() {
        // Check if this is a temp login request
        if ( ! isset( $_GET['wp-care-login'] ) || ! isset( $_GET['uid'] ) ) {
            return;
        }

        // Sanitize inputs
        $token   = sanitize_text_field( wp_unslash( $_GET['wp-care-login'] ) );
        $user_id = absint( $_GET['uid'] );

        // Validate user exists and is a temp user
        $is_temp = get_user_meta( $user_id, '_wp_care_temp_user', true );
        if ( ! $is_temp ) {
            wp_die(
                esc_html__( 'Invalid login link.', 'wp-care-connector' ),
                esc_html__( 'Login Error', 'wp-care-connector' ),
                array( 'response' => 403 )
            );
        }

        // Check expiry
        $expiry = get_user_meta( $user_id, '_wp_care_expiry', true );
        if ( time() > intval( $expiry ) ) {
            $this->cleanup_user( $user_id );
            wp_die(
                esc_html__( 'Login link has expired.', 'wp-care-connector' ),
                esc_html__( 'Login Expired', 'wp-care-connector' ),
                array( 'response' => 403 )
            );
        }

        // Validate token
        $stored_hash = get_user_meta( $user_id, '_wp_care_token_hash', true );
        if ( ! $stored_hash || ! wp_check_password( $token, $stored_hash ) ) {
            wp_die(
                esc_html__( 'Invalid login token.', 'wp-care-connector' ),
                esc_html__( 'Login Error', 'wp-care-connector' ),
                array( 'response' => 403 )
            );
        }

        // Get IP and user agent for logging
        $ip_address = $this->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        // Log the successful login
        $this->log_event( 'logged_in', $user_id, '', $ip_address, $user_agent );

        // Clear the token hash - one-time use only
        delete_user_meta( $user_id, '_wp_care_token_hash' );

        // Authenticate the user
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, false );

        // Redirect to admin dashboard
        wp_safe_redirect( admin_url() );
        exit;
    }

    /**
     * Cleanup a temporary user.
     *
     * Deletes the temporary user and reassigns any content to admin user.
     * Logs the deletion event.
     *
     * @param int $user_id User ID to cleanup.
     * @return void
     */
    public function cleanup_user( $user_id ) {
        $user_id = absint( $user_id );

        // Verify it's actually a temp user
        if ( ! get_user_meta( $user_id, '_wp_care_temp_user', true ) ) {
            return;
        }

        // Log deletion event
        $this->log_event( 'deleted', $user_id );

        // Require user deletion file
        require_once ABSPATH . 'wp-admin/includes/user.php';

        // Delete user, reassigning any content to user ID 1 (primary admin)
        wp_delete_user( $user_id, 1 );

        // Clear any scheduled cleanup for this user
        wp_clear_scheduled_hook( 'wp_care_cleanup_temp_user', array( $user_id ) );
    }

    /**
     * Cleanup all expired temporary users.
     *
     * Fallback cleanup for any users that weren't cleaned up via cron.
     * Runs on every init as a safety net.
     *
     * @return void
     */
    public function cleanup_expired_users() {
        // Only run cleanup once per minute max
        $last_cleanup = get_transient( 'wp_care_last_temp_cleanup' );
        if ( $last_cleanup ) {
            return;
        }
        set_transient( 'wp_care_last_temp_cleanup', true, 60 );

        // Find all temp users
        $users = get_users(
            array(
                'meta_key'   => '_wp_care_temp_user',
                'meta_value' => true,
            )
        );

        foreach ( $users as $user ) {
            $expiry = get_user_meta( $user->ID, '_wp_care_expiry', true );
            if ( $expiry && time() > intval( $expiry ) ) {
                $this->cleanup_user( $user->ID );
            }
        }
    }

    /**
     * Log a temp login event.
     *
     * Stores audit log entries with full details for security tracking.
     *
     * @param string $event_type Event type (created, logged_in, deleted, revoked).
     * @param int    $user_id    Temp user ID.
     * @param string $requester_id Who requested the login.
     * @param string $ip         IP address (for login events).
     * @param string $user_agent User agent string (for login events).
     * @return void
     */
    private function log_event( $event_type, $user_id, $requester_id = '', $ip = '', $user_agent = '' ) {
        $log = get_option( $this->log_option, array() );

        // Build log entry
        $entry = array(
            'event'        => sanitize_text_field( $event_type ),
            'user_id'      => absint( $user_id ),
            'requester_id' => sanitize_text_field( $requester_id ),
            'ip'           => sanitize_text_field( $ip ?: $this->get_client_ip() ),
            'user_agent'   => sanitize_text_field( $user_agent ),
            'timestamp'    => time(),
            'datetime'     => current_time( 'mysql' ),
        );

        $log[] = $entry;

        // Keep only last 100 entries
        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, -100 );
        }

        // Don't autoload this option - only needed when viewing logs
        update_option( $this->log_option, $log, false );
    }

    /**
     * Notify site admin when a temp login is created (SEC-05).
     *
     * Sends email to admin_email so site owner is aware when
     * temporary admin access is granted.
     *
     * @param string $username     The temp user's username.
     * @param int    $expiry       Unix timestamp when login expires.
     * @param string $requester_id Who requested the login.
     * @return bool Whether email was sent.
     */
    private function notify_admin_temp_login_created( $username, $expiry, $requester_id = '' ) {
        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) {
            return false;
        }

        $site_name = get_bloginfo( 'name' );
        $site_url  = get_site_url();

        $subject = sprintf(
            /* translators: %s: site name */
            __( '[%s] WP Care: Temporary admin login created', 'wp-care-connector' ),
            $site_name
        );

        $expiry_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry );
        $expires_in_hours = round( ( $expiry - time() ) / HOUR_IN_SECONDS, 1 );

        $message = sprintf(
            __(
                "A temporary administrator login has been created for WP Care support access.\n\n" .
                "Site: %s (%s)\n" .
                "Username: %s\n" .
                "Expires: %s (in %s hours)\n",
                'wp-care-connector'
            ),
            $site_name,
            $site_url,
            $username,
            $expiry_formatted,
            $expires_in_hours
        );

        if ( ! empty( $requester_id ) ) {
            $message .= sprintf(
                /* translators: %s: requester ID */
                __( "Requested by: %s\n", 'wp-care-connector' ),
                $requester_id
            );
        }

        $message .= "\n" . __(
            "This temporary account will be automatically deleted when it expires.\n\n" .
            "If you did not request support access, please contact us immediately:\n" .
            "- Revoke access from WP Admin > WP Care > Temp Logins\n" .
            "- Or reply to this email\n\n" .
            "-- \n" .
            "WP Care Support System",
            'wp-care-connector'
        );

        return wp_mail( $admin_email, $subject, $message );
    }

    /**
     * Get the temp login audit log.
     *
     * @return array Log entries, most recent first.
     */
    public function get_login_log() {
        $log = get_option( $this->log_option, array() );
        return array_reverse( $log );
    }

    /**
     * Get all active (non-expired) temporary users.
     *
     * @return array Array of active temp user data.
     */
    public function get_active_temp_users() {
        $users = get_users(
            array(
                'meta_key'   => '_wp_care_temp_user',
                'meta_value' => true,
            )
        );

        $active = array();
        foreach ( $users as $user ) {
            $expiry = get_user_meta( $user->ID, '_wp_care_expiry', true );
            if ( $expiry && time() < intval( $expiry ) ) {
                $active[] = array(
                    'user_id'    => $user->ID,
                    'username'   => $user->user_login,
                    'expiry'     => intval( $expiry ),
                    'expires_in' => intval( $expiry ) - time(),
                    'requester'  => get_user_meta( $user->ID, '_wp_care_requester', true ),
                    'created'    => get_user_meta( $user->ID, '_wp_care_created', true ),
                );
            }
        }

        return $active;
    }

    /**
     * Revoke a temporary login.
     *
     * Early deletion of a temp user before expiry.
     *
     * @param int $user_id User ID to revoke.
     * @return bool True if revoked, false if not a temp user.
     */
    public function revoke_login( $user_id ) {
        $user_id = absint( $user_id );

        if ( ! get_user_meta( $user_id, '_wp_care_temp_user', true ) ) {
            return false;
        }

        // Log as revoked (different from expired deletion)
        $this->log_event( 'revoked', $user_id );

        // Cleanup the user
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $user_id, 1 );
        wp_clear_scheduled_hook( 'wp_care_cleanup_temp_user', array( $user_id ) );

        return true;
    }

    /**
     * Get the client IP address.
     *
     * Handles proxied requests and common header formats.
     *
     * @return string Client IP address.
     */
    private function get_client_ip() {
        $ip = '';

        // Check for proxy headers first
        $headers = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                // X-Forwarded-For can contain multiple IPs - get the first one
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }
                break;
            }
        }

        return $ip;
    }

    /**
     * Clear all temp login log entries.
     *
     * Admin utility function.
     *
     * @return void
     */
    public function clear_log() {
        delete_option( $this->log_option );
    }
}
