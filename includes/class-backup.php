<?php
/**
 * WP Care Backup - Checkpoint backup system
 *
 * Creates database checkpoints before destructive operations
 * and restores them if needed.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_Care_Backup
 *
 * Handles checkpoint creation and restoration for safe remote operations.
 */
class WP_Care_Backup {

    /**
     * Directory for storing backups.
     *
     * @var string
     */
    private $backup_dir;

    /**
     * Maximum number of checkpoints to keep.
     *
     * @var int
     */
    private $max_checkpoints = 5;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->backup_dir = WP_CONTENT_DIR . '/wp-care-backups';
    }

    /**
     * Ensure backup directory exists and is protected.
     *
     * @return bool True if directory is writable, false otherwise.
     */
    public static function ensure_backup_dir() {
        $backup_dir = WP_CONTENT_DIR . '/wp-care-backups';

        // Create directory if it doesn't exist
        if ( ! file_exists( $backup_dir ) ) {
            if ( ! wp_mkdir_p( $backup_dir ) ) {
                error_log( 'WP Care Backup: Failed to create backup directory' );
                return false;
            }
        }

        // Create .htaccess to deny web access
        $htaccess_file = $backup_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            $htaccess_content = "# Deny access to backup files\n";
            $htaccess_content .= "Order deny,allow\n";
            $htaccess_content .= "Deny from all\n";
            file_put_contents( $htaccess_file, $htaccess_content );
        }

        // Create index.php for additional protection
        $index_file = $backup_dir . '/index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, '<?php // Silence is golden' );
        }

        return is_writable( $backup_dir );
    }

    /**
     * Create a checkpoint backup before a destructive operation.
     *
     * @param string $operation_type Type of operation (e.g., 'plugin_update', 'settings_change').
     * @return string|false Checkpoint ID on success, false on failure.
     */
    public function create_checkpoint( $operation_type ) {
        // Ensure backup directory exists
        if ( ! self::ensure_backup_dir() ) {
            error_log( 'WP Care Backup: Backup directory not writable, skipping checkpoint' );
            return false;
        }

        // Generate unique checkpoint ID
        $checkpoint_id = gmdate( 'Y-m-d_His' ) . '_' . wp_generate_password( 6, false, false );
        $checkpoint_dir = $this->backup_dir . '/' . $checkpoint_id;

        // Create checkpoint directory
        if ( ! wp_mkdir_p( $checkpoint_dir ) ) {
            error_log( 'WP Care Backup: Failed to create checkpoint directory' );
            return false;
        }

        // Export database
        $db_file = $checkpoint_dir . '/database.sql';
        $export_success = false;

        if ( $this->check_wp_cli() ) {
            // Use WP-CLI for database export
            $abspath = ABSPATH;
            $command = sprintf(
                'wp db export %s --path=%s 2>&1',
                escapeshellarg( $db_file ),
                escapeshellarg( $abspath )
            );
            $output = shell_exec( $command );
            $export_success = file_exists( $db_file ) && filesize( $db_file ) > 0;

            if ( ! $export_success ) {
                error_log( 'WP Care Backup: WP-CLI export failed, trying PHP fallback' );
            }
        }

        // PHP fallback if WP-CLI not available or failed
        if ( ! $export_success ) {
            $export_success = $this->php_db_export( $db_file );
        }

        if ( ! $export_success ) {
            error_log( 'WP Care Backup: All database export methods failed' );
            // Clean up checkpoint directory
            $this->delete_checkpoint( $checkpoint_id );
            return false;
        }

        // Save checkpoint metadata
        $metadata = array(
            'id'             => $checkpoint_id,
            'created_at'     => gmdate( 'c' ),
            'operation_type' => $operation_type,
            'wp_version'     => get_bloginfo( 'version' ),
            'site_url'       => get_site_url(),
            'db_size'        => filesize( $db_file ),
        );

        $metadata_file = $checkpoint_dir . '/checkpoint.json';
        if ( ! file_put_contents( $metadata_file, wp_json_encode( $metadata, JSON_PRETTY_PRINT ) ) ) {
            error_log( 'WP Care Backup: Failed to save checkpoint metadata' );
            $this->delete_checkpoint( $checkpoint_id );
            return false;
        }

        // Cleanup old checkpoints
        $this->cleanup_old_checkpoints();

        return $checkpoint_id;
    }

    /**
     * Restore from a checkpoint.
     *
     * @param string $checkpoint_id Checkpoint ID to restore.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function restore_checkpoint( $checkpoint_id ) {
        $checkpoint_dir = $this->backup_dir . '/' . $checkpoint_id;

        // Verify checkpoint exists
        if ( ! is_dir( $checkpoint_dir ) ) {
            return new WP_Error(
                'checkpoint_not_found',
                sprintf( 'Checkpoint %s not found', $checkpoint_id )
            );
        }

        // Load metadata
        $metadata_file = $checkpoint_dir . '/checkpoint.json';
        if ( ! file_exists( $metadata_file ) ) {
            return new WP_Error(
                'metadata_missing',
                'Checkpoint metadata file not found'
            );
        }

        $metadata = json_decode( file_get_contents( $metadata_file ), true );
        if ( ! $metadata ) {
            return new WP_Error(
                'metadata_invalid',
                'Could not parse checkpoint metadata'
            );
        }

        // Verify site_url matches to prevent cross-site restore
        if ( isset( $metadata['site_url'] ) && $metadata['site_url'] !== get_site_url() ) {
            return new WP_Error(
                'site_mismatch',
                sprintf(
                    'Checkpoint was created for %s, cannot restore to %s',
                    $metadata['site_url'],
                    get_site_url()
                )
            );
        }

        // Verify database file exists
        $db_file = $checkpoint_dir . '/database.sql';
        if ( ! file_exists( $db_file ) ) {
            return new WP_Error(
                'database_missing',
                'Database backup file not found'
            );
        }

        // Import database
        $import_success = false;

        if ( $this->check_wp_cli() ) {
            $abspath = ABSPATH;
            $command = sprintf(
                'wp db import %s --path=%s 2>&1',
                escapeshellarg( $db_file ),
                escapeshellarg( $abspath )
            );
            $output = shell_exec( $command );
            // Check if import succeeded by looking for success message
            $import_success = strpos( $output, 'Success' ) !== false || strpos( $output, 'Query OK' ) !== false;

            if ( ! $import_success ) {
                error_log( 'WP Care Backup: WP-CLI import output: ' . $output );
            }
        }

        // PHP fallback
        if ( ! $import_success ) {
            $import_success = $this->php_db_import( $db_file );
        }

        if ( ! $import_success ) {
            return new WP_Error(
                'restore_failed',
                'Database import failed'
            );
        }

        return true;
    }

    /**
     * List all available checkpoints.
     *
     * @return array Array of checkpoint metadata, sorted by created_at descending.
     */
    public function list_checkpoints() {
        $checkpoints = array();

        if ( ! is_dir( $this->backup_dir ) ) {
            return $checkpoints;
        }

        $dirs = scandir( $this->backup_dir );
        foreach ( $dirs as $dir ) {
            if ( $dir === '.' || $dir === '..' ) {
                continue;
            }

            $metadata_file = $this->backup_dir . '/' . $dir . '/checkpoint.json';
            if ( file_exists( $metadata_file ) ) {
                $metadata = json_decode( file_get_contents( $metadata_file ), true );
                if ( $metadata ) {
                    $checkpoints[] = $metadata;
                }
            }
        }

        // Sort by created_at descending
        usort( $checkpoints, function( $a, $b ) {
            return strtotime( $b['created_at'] ) - strtotime( $a['created_at'] );
        } );

        return $checkpoints;
    }

    /**
     * Delete a checkpoint.
     *
     * @param string $checkpoint_id Checkpoint ID to delete.
     * @return bool True on success, false on failure.
     */
    public function delete_checkpoint( $checkpoint_id ) {
        $checkpoint_dir = $this->backup_dir . '/' . $checkpoint_id;

        if ( ! is_dir( $checkpoint_dir ) ) {
            return false;
        }

        return $this->recursive_delete( $checkpoint_dir );
    }

    /**
     * Cleanup old checkpoints, keeping only the most recent ones.
     *
     * @return void
     */
    private function cleanup_old_checkpoints() {
        $checkpoints = $this->list_checkpoints();

        // Delete oldest checkpoints exceeding max
        if ( count( $checkpoints ) > $this->max_checkpoints ) {
            $to_delete = array_slice( $checkpoints, $this->max_checkpoints );
            foreach ( $to_delete as $checkpoint ) {
                $this->delete_checkpoint( $checkpoint['id'] );
            }
        }
    }

    /**
     * Check if WP-CLI is available.
     *
     * @return bool True if WP-CLI is available.
     */
    private function check_wp_cli() {
        // Check if running within WP-CLI
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }

        // Check if wp command is available in PATH
        $which_wp = shell_exec( 'which wp 2>/dev/null' );
        return ! empty( $which_wp );
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path.
     * @return bool True on success.
     */
    private function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }

        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;
            if ( is_dir( $path ) ) {
                $this->recursive_delete( $path );
            } else {
                unlink( $path );
            }
        }

        return rmdir( $dir );
    }

    /**
     * PHP-based database export fallback.
     *
     * @param string $filepath Path to save the SQL file.
     * @return bool True on success, false on failure.
     */
    private function php_db_export( $filepath ) {
        global $wpdb;

        // Extend time limit for large databases
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        // Check available memory
        $memory_limit = ini_get( 'memory_limit' );
        $memory_bytes = $this->return_bytes( $memory_limit );
        if ( $memory_bytes > 0 && $memory_bytes < 67108864 ) { // 64MB
            error_log( 'WP Care Backup: Low memory warning - backup may fail on large databases' );
        }

        $handle = fopen( $filepath, 'w' );
        if ( ! $handle ) {
            error_log( 'WP Care Backup: Could not open file for writing' );
            return false;
        }

        // Write header
        fwrite( $handle, "-- WP Care Backup\n" );
        fwrite( $handle, "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
        fwrite( $handle, "-- Site: " . get_site_url() . "\n\n" );
        fwrite( $handle, "SET NAMES utf8mb4;\n" );
        fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );

        // Get all tables
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        if ( ! $tables ) {
            fclose( $handle );
            return false;
        }

        foreach ( $tables as $table ) {
            $table_name = $table[0];

            // Get table structure
            $create_result = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_N );
            if ( ! $create_result ) {
                continue;
            }

            fwrite( $handle, "-- Table: $table_name\n" );
            fwrite( $handle, "DROP TABLE IF EXISTS `$table_name`;\n" );
            fwrite( $handle, $create_result[1] . ";\n\n" );

            // Export data in chunks to avoid memory issues
            $offset = 0;
            $limit = 1000;

            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `$table_name` LIMIT %d OFFSET %d",
                        $limit,
                        $offset
                    ),
                    ARRAY_A
                );

                if ( $rows ) {
                    foreach ( $rows as $row ) {
                        $values = array_map( function( $v ) use ( $wpdb ) {
                            if ( $v === null ) {
                                return 'NULL';
                            }
                            return "'" . $wpdb->_real_escape( $v ) . "'";
                        }, $row );

                        fwrite( $handle, "INSERT INTO `$table_name` VALUES (" . implode( ',', $values ) . ");\n" );
                    }
                }

                $offset += $limit;

                // Free memory
                unset( $rows );

            } while ( $wpdb->num_rows === $limit );

            fwrite( $handle, "\n" );
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
        fclose( $handle );

        return file_exists( $filepath ) && filesize( $filepath ) > 0;
    }

    /**
     * PHP-based database import fallback.
     *
     * @param string $filepath Path to the SQL file.
     * @return bool True on success, false on failure.
     */
    private function php_db_import( $filepath ) {
        global $wpdb;

        // Extend time limit for large imports
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        if ( ! file_exists( $filepath ) ) {
            return false;
        }

        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return false;
        }

        $statement = '';
        $error_count = 0;
        $success_count = 0;

        while ( ( $line = fgets( $handle ) ) !== false ) {
            // Skip comments and empty lines
            $trimmed = trim( $line );
            if ( empty( $trimmed ) || strpos( $trimmed, '--' ) === 0 ) {
                continue;
            }

            // Accumulate statement
            $statement .= $line;

            // Check for statement end
            if ( substr( rtrim( $statement ), -1 ) === ';' ) {
                // Execute statement
                $result = $wpdb->query( $statement );
                if ( $result === false ) {
                    $error_count++;
                    error_log( 'WP Care Backup: Import error - ' . $wpdb->last_error );
                } else {
                    $success_count++;
                }

                // Reset for next statement
                $statement = '';
            }
        }

        fclose( $handle );

        // Consider success if we executed more statements than errors
        $success = $success_count > $error_count;
        if ( $error_count > 0 ) {
            error_log( sprintf(
                'WP Care Backup: Import completed with %d errors and %d successful statements',
                $error_count,
                $success_count
            ) );
        }

        return $success;
    }

    /**
     * Convert memory limit string to bytes.
     *
     * @param string $val Memory limit string (e.g., '64M').
     * @return int Bytes.
     */
    private function return_bytes( $val ) {
        $val = trim( $val );
        $last = strtolower( $val[ strlen( $val ) - 1 ] );
        $val = (int) $val;

        switch ( $last ) {
            case 'g':
                $val *= 1024;
                // Fall through
            case 'm':
                $val *= 1024;
                // Fall through
            case 'k':
                $val *= 1024;
        }

        return $val;
    }
}
