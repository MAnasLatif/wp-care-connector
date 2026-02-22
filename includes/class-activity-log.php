<?php
/**
 * WP Care Activity Log
 *
 * Rolling buffer activity log stored in WordPress options.
 * Other classes call WP_Care_Activity_Log::log() to record actions.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Care_Activity_Log {

    /**
     * Option key for storing activity log entries.
     *
     * @var string
     */
    const OPTION_KEY = 'wp_care_activity_log';

    /**
     * Maximum number of log entries to keep.
     *
     * @var int
     */
    const MAX_ENTRIES = 50;

    /**
     * Log an activity entry.
     *
     * @param string $action  The action identifier (e.g. 'command_executed', 'temp_login_created').
     * @param array  $details Additional details about the action.
     * @return void
     */
    public static function log( $action, $details = array() ) {
        $entries = get_option( self::OPTION_KEY, array() );

        $entry = array(
            'action'    => sanitize_text_field( $action ),
            'details'   => $details,
            'timestamp' => time(),
            'user'      => function_exists( 'wp_get_current_user' ) ? wp_get_current_user()->user_login : 'system',
        );

        // Prepend new entry (newest first)
        array_unshift( $entries, $entry );

        // Trim to max size
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, 0, self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $entries, false );
    }

    /**
     * Get all log entries.
     *
     * @param int $limit Number of entries to return. Default 50.
     * @return array Log entries, newest first.
     */
    public static function get_entries( $limit = 50 ) {
        $entries = get_option( self::OPTION_KEY, array() );
        return array_slice( $entries, 0, $limit );
    }

    /**
     * Clear all log entries.
     *
     * @return void
     */
    public static function clear() {
        update_option( self::OPTION_KEY, array(), false );
    }

    /**
     * Get a human-readable label for an action.
     *
     * @param string $action The action identifier.
     * @return string Human-readable label.
     */
    public static function get_action_label( $action ) {
        $labels = array(
            'command_executed'    => __( 'Command Executed', 'wp-care-connector' ),
            'temp_login_created' => __( 'Temporary Login Created', 'wp-care-connector' ),
            'temp_login_used'    => __( 'Temporary Login Used', 'wp-care-connector' ),
            'health_reported'    => __( 'Health Report Sent', 'wp-care-connector' ),
            'backup_created'     => __( 'Backup Created', 'wp-care-connector' ),
            'cache_cleared'      => __( 'Cache Cleared', 'wp-care-connector' ),
            'plugin_updated'     => __( 'Plugin Updated', 'wp-care-connector' ),
            'support_submitted'    => __( 'Support Request Submitted', 'wp-care-connector' ),
            'settings_saved'       => __( 'Settings Saved', 'wp-care-connector' ),
            'migration_created'    => __( 'Migration Backup Created', 'wp-care-connector' ),
            'migration_deleted'    => __( 'Migration Backup Deleted', 'wp-care-connector' ),
            'migration_downloaded' => __( 'Migration Backup Downloaded', 'wp-care-connector' ),
        );

        return isset( $labels[ $action ] ) ? $labels[ $action ] : sanitize_text_field( $action );
    }
}
