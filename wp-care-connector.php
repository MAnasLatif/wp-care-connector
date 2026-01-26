<?php
/**
 * Plugin Name: WP Care Connector
 * Plugin URI: https://wpcare.io
 * Description: Secure remote WordPress management for WP Care Platform
 * Version: 1.0.0
 * Author: WP Care
 * Author URI: https://wpcare.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-care-connector
 * Requires at least: 4.7
 * Requires PHP: 5.6
 */

// Security check: prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_CARE_VERSION', '1.0.0');
define('WP_CARE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_CARE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_CARE_PLUGIN_FILE', __FILE__);

// Include required files
require_once WP_CARE_PLUGIN_DIR . 'includes/class-security.php';
require_once WP_CARE_PLUGIN_DIR . 'includes/class-backup.php';
require_once WP_CARE_PLUGIN_DIR . 'includes/class-site-mapper.php';
require_once WP_CARE_PLUGIN_DIR . 'includes/class-temp-login.php';
require_once WP_CARE_PLUGIN_DIR . 'includes/class-api-endpoints.php';
require_once WP_CARE_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Plugin activation hook
 *
 * Generates API key, schedules cleanup, creates backup directory
 */
function wp_care_activate() {
    // Generate API key if not exists
    if (!get_option('wp_care_api_key_encrypted')) {
        WP_Care_Security::generate_api_key();
    }

    // Schedule hourly cleanup of expired temporary users
    if (!wp_next_scheduled('wp_care_cleanup_expired_users')) {
        wp_schedule_event(time(), 'hourly', 'wp_care_cleanup_expired_users');
    }

    // Create backup directory with protection
    $backup_dir = WP_CONTENT_DIR . '/wp-care-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);

        // Create .htaccess to protect backup directory
        $htaccess_content = "# Deny all access to backup files\n";
        $htaccess_content .= "Order deny,allow\n";
        $htaccess_content .= "Deny from all\n";
        file_put_contents($backup_dir . '/.htaccess', $htaccess_content);

        // Also create index.php for additional protection
        file_put_contents($backup_dir . '/index.php', '<?php // Silence is golden.');
    }

    // Flush rewrite rules for REST API endpoints
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wp_care_activate');

/**
 * Plugin deactivation hook
 *
 * Clears scheduled events but preserves API key for reactivation
 */
function wp_care_deactivate() {
    // Clear scheduled cleanup events
    $timestamp = wp_next_scheduled('wp_care_cleanup_expired_users');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wp_care_cleanup_expired_users');
    }

    // Clear all wp_care_cleanup_temp_user events
    wp_clear_scheduled_hook('wp_care_cleanup_temp_user');

    // Note: We do NOT delete the API key here
    // User might reactivate the plugin and expect their key to still work
}
register_deactivation_hook(__FILE__, 'wp_care_deactivate');

/**
 * Initialize plugin on plugins_loaded
 *
 * Loads text domain and initializes plugin components
 */
function wp_care_init() {
    // Load text domain for translations
    load_plugin_textdomain(
        'wp-care-connector',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    // Initialize site mapper and register cache hooks
    $site_mapper = new WP_Care_Site_Mapper();
    $site_mapper->register_cache_hooks();

    // Initialize temporary login system (hooks into init for authentication)
    new WP_Care_Temp_Login();

    // Initialize API endpoints (registers REST routes)
    WP_Care_API_Endpoints::instance();

    // Initialize admin UI only in admin context
    if ( is_admin() ) {
        new WP_Care_Admin();
    }
}
add_action('plugins_loaded', 'wp_care_init', 10);

/**
 * Cleanup expired temporary users
 *
 * Hooked to wp_care_cleanup_expired_users cron event
 */
function wp_care_cleanup_expired_users_callback() {
    // Get all temporary users
    $temp_users = get_users([
        'meta_key' => '_wp_care_temp_user',
        'meta_value' => true,
    ]);

    foreach ($temp_users as $user) {
        $expiry = get_user_meta($user->ID, '_wp_care_expiry', true);

        // Delete if expired
        if ($expiry && time() > intval($expiry)) {
            // Require user deletion file if not loaded
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user->ID);
        }
    }
}
add_action('wp_care_cleanup_expired_users', 'wp_care_cleanup_expired_users_callback');
