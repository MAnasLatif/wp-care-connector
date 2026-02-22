<?php
/**
 * WP Care Connector Uninstall
 *
 * Cleans up all plugin data when uninstalled via WordPress admin.
 * Only runs when plugin is properly uninstalled, not just deactivated.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

// Security check: only run if called from WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up all plugin data
 */

// Delete API key options
delete_option('wp_care_api_key_encrypted');
delete_option('wp_care_api_key_hash');

// Delete any other plugin options that might be added
delete_option('wp_care_registered');
delete_option('wp_care_central_url');
delete_option('wp_care_site_id');

// Clear any scheduled cron events
wp_clear_scheduled_hook('wp_care_cleanup_expired_users');
wp_clear_scheduled_hook('wp_care_cleanup_temp_user');

// Delete all temporary users created by the plugin
$temp_users = get_users([
    'meta_key' => '_wp_care_temp_user',
    'meta_value' => true,
    'fields' => 'ID',
]);

if (!empty($temp_users)) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($temp_users as $user_id) {
        wp_delete_user($user_id);
    }
}

// Clean up backup directory if empty
$backup_dir = WP_CONTENT_DIR . '/wp-care-backups';
if (is_dir($backup_dir)) {
    // Get directory contents (excluding . and ..)
    $files = array_diff(scandir($backup_dir), ['.', '..']);

    // Remove protection files first
    $protection_files = ['.htaccess', 'index.php'];
    foreach ($protection_files as $file) {
        $file_path = $backup_dir . '/' . $file;
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    // Update files list after removing protection files
    $files = array_diff(scandir($backup_dir), ['.', '..']);

    // Only remove directory if completely empty
    if (empty($files)) {
        rmdir($backup_dir);
    }
}

// Clean up migration directory
$migration_dir = WP_CONTENT_DIR . '/wp-care-migrations';
if (is_dir($migration_dir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($migration_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }
    rmdir($migration_dir);
}

// Clean up any transients
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wp_care_%'
     OR option_name LIKE '_transient_timeout_wp_care_%'"
);

// Note: We intentionally do NOT delete:
// - User activity logs (they might be needed for audit)
// - Backup files (user should manually remove if desired)
