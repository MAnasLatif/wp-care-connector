<?php
/**
 * WP Care Migration - Full site backup for migration
 *
 * Creates ZIP archives containing database + wp-content files
 * for site migration purposes. Supports chunked processing
 * for large sites via AJAX, and single-shot for remote commands.
 *
 * @package WP_Care_Connector
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Care_Migration {

    /**
     * Base directory for migration backups.
     *
     * @var string
     */
    private $migration_dir;

    /**
     * Maximum number of migration backups to keep.
     *
     * @var int
     */
    private $max_migrations = 3;

    /**
     * Chunk timeout in seconds (for AJAX processing).
     *
     * @var int
     */
    private $chunk_timeout = 10;

    /**
     * Default export options.
     *
     * @var array
     */
    private $default_options = array(
        'include_database'          => true,
        'include_themes'            => true,
        'include_plugins'           => true,
        'include_uploads'           => true,
        'include_mu_plugins'        => false,
        'exclude_cache'             => true,
        'exclude_inactive_themes'   => false,
        'exclude_inactive_plugins'  => false,
        'exclude_spam_comments'     => true,
        'exclude_post_revisions'    => false,
    );

    /**
     * Constructor.
     */
    public function __construct() {
        $this->migration_dir = WP_CONTENT_DIR . '/wp-care-migrations';
    }

    /**
     * Ensure migration directory exists with security protections.
     *
     * @return bool True if directory is writable.
     */
    public static function ensure_migration_dir() {
        $dir = WP_CONTENT_DIR . '/wp-care-migrations';

        if ( ! file_exists( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                error_log( 'WP Care Migration: Failed to create migration directory' );
                return false;
            }
        }

        $htaccess_file = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            file_put_contents( $htaccess_file, "# Deny access to migration files\nOrder deny,allow\nDeny from all\n" );
        }

        $index_file = $dir . '/index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, '<?php // Silence is golden' );
        }

        return is_writable( $dir );
    }

    /**
     * Initialize a new migration export.
     *
     * @param array $options Export options.
     * @return array|false Migration state or false on failure.
     */
    public function init_export( $options = array() ) {
        if ( ! self::ensure_migration_dir() ) {
            return false;
        }

        $options = wp_parse_args( $options, $this->default_options );

        // Cast all options to boolean
        foreach ( $options as $key => $value ) {
            if ( array_key_exists( $key, $this->default_options ) ) {
                $options[ $key ] = (bool) $value;
            }
        }

        // Remove unknown options
        $options = array_intersect_key( $options, $this->default_options );

        $migration_id  = gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
        $working_dir   = $this->migration_dir . '/' . $migration_id;

        if ( ! wp_mkdir_p( $working_dir ) ) {
            error_log( 'WP Care Migration: Failed to create working directory' );
            return false;
        }

        $state = array(
            'migration_id'       => $migration_id,
            'phase'              => 'config',
            'progress'           => 0,
            'completed'          => false,
            'error'              => null,
            'options'            => $options,
            'created_at'         => gmdate( 'c' ),
            'working_dir'        => $working_dir,
            // Database state
            'table_index'        => 0,
            'table_offset'       => 0,
            'total_tables'       => 0,
            // File enumeration state
            'total_files_count'  => 0,
            'total_files_size'   => 0,
            // Archive state
            'filemap_offset'     => 0,
            'archived_files'     => 0,
            'archived_size'      => 0,
            'db_archived'        => false,
            'config_archived'    => false,
        );

        if ( ! $this->save_state( $migration_id, $state ) ) {
            return false;
        }

        return $state;
    }

    /**
     * Process next chunk of the export.
     *
     * @param string $migration_id The migration ID.
     * @return array Updated migration state.
     */
    public function process_chunk( $migration_id ) {
        $state = $this->load_state( $migration_id );
        if ( ! $state ) {
            return array( 'error' => 'Migration state not found', 'completed' => false );
        }

        if ( $state['completed'] ) {
            return $state;
        }

        if ( $state['error'] ) {
            return $state;
        }

        $start_time = time();

        switch ( $state['phase'] ) {
            case 'config':
                $this->phase_config( $state );
                $state['phase']    = 'database';
                $state['progress'] = 5;
                break;

            case 'database':
                if ( ! $state['options']['include_database'] ) {
                    $state['phase']    = 'enumerate';
                    $state['progress'] = 30;
                } else {
                    $done = $this->phase_database( $state, $start_time );
                    if ( $done ) {
                        $state['phase']    = 'enumerate';
                        $state['progress'] = 30;
                    } else {
                        // Calculate progress within database phase (5-30%)
                        if ( $state['total_tables'] > 0 ) {
                            $state['progress'] = 5 + (int) ( 25 * $state['table_index'] / $state['total_tables'] );
                        }
                    }
                }
                break;

            case 'enumerate':
                $this->phase_enumerate( $state );
                $state['phase']    = 'archive';
                $state['progress'] = 35;
                break;

            case 'archive':
                $done = $this->phase_archive( $state, $start_time );
                if ( $done ) {
                    $state['phase']    = 'finalize';
                    $state['progress'] = 95;
                } else {
                    // Calculate progress within archive phase (35-95%)
                    if ( $state['total_files_count'] > 0 ) {
                        $state['progress'] = 35 + (int) ( 60 * $state['archived_files'] / $state['total_files_count'] );
                    }
                }
                break;

            case 'finalize':
                $this->phase_finalize( $state );
                $state['phase']     = 'complete';
                $state['progress']  = 100;
                $state['completed'] = true;
                break;
        }

        $this->save_state( $migration_id, $state );
        return $state;
    }

    /**
     * Run the full export in one go (for remote command use).
     *
     * @param array $options Export options.
     * @return array Final migration state.
     */
    public function run_full_export( $options = array() ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        $state = $this->init_export( $options );
        if ( ! $state ) {
            return array( 'error' => 'Failed to initialize migration export' );
        }

        // Override chunk timeout for full export
        $this->chunk_timeout = 300;

        while ( ! $state['completed'] && ! $state['error'] ) {
            $state = $this->process_chunk( $state['migration_id'] );
        }

        return $state;
    }

    /**
     * Phase: Generate package.json config.
     *
     * @param array $state Migration state (by reference).
     */
    private function phase_config( &$state ) {
        $config = $this->build_package_config( $state['options'] );
        $config_path = $state['working_dir'] . '/package.json';

        if ( ! file_put_contents( $config_path, wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
            $state['error'] = 'Failed to write package.json';
        }
    }

    /**
     * Phase: Export database to SQL file.
     *
     * @param array $state      Migration state (by reference).
     * @param int   $start_time Start timestamp for timeout tracking.
     * @return bool True if database export is complete.
     */
    private function phase_database( &$state, $start_time ) {
        $sql_path = $state['working_dir'] . '/database.sql';

        // First attempt: try WP-CLI for the entire export
        if ( $state['table_index'] === 0 && $state['table_offset'] === 0 ) {
            if ( $this->check_wp_cli() ) {
                $abspath = ABSPATH;
                $command = sprintf(
                    'wp db export %s --path=%s 2>&1',
                    escapeshellarg( $sql_path ),
                    escapeshellarg( $abspath )
                );

                // Apply spam/revision exclusions if needed
                $output = shell_exec( $command );

                if ( file_exists( $sql_path ) && filesize( $sql_path ) > 0 ) {
                    return true;
                }

                error_log( 'WP Care Migration: WP-CLI export failed, using PHP fallback' );
            }
        }

        // PHP fallback: chunked table-by-table export
        return $this->export_database_chunk( $sql_path, $state, $start_time );
    }

    /**
     * Export database chunk by chunk (PHP fallback).
     *
     * @param string $sql_path   Path to SQL file.
     * @param array  $state      Migration state (by reference).
     * @param int    $start_time Start timestamp for timeout tracking.
     * @return bool True if complete.
     */
    private function export_database_chunk( $sql_path, &$state, $start_time ) {
        global $wpdb;

        // Get all tables
        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
        if ( ! $tables ) {
            $state['error'] = 'No database tables found';
            return true;
        }

        $table_names = array_map( function( $t ) { return $t[0]; }, $tables );
        $state['total_tables'] = count( $table_names );

        // Open file in append mode (or write mode if starting fresh)
        $mode = ( $state['table_index'] === 0 && $state['table_offset'] === 0 ) ? 'w' : 'a';
        $handle = fopen( $sql_path, $mode );
        if ( ! $handle ) {
            $state['error'] = 'Failed to open database export file';
            return true;
        }

        // Write header if starting fresh
        if ( $mode === 'w' ) {
            fwrite( $handle, "-- WP Care Migration Database Export\n" );
            fwrite( $handle, '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
            fwrite( $handle, '-- Site: ' . get_site_url() . "\n\n" );
            fwrite( $handle, "SET NAMES utf8mb4;\n" );
            fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );
        }

        $limit = 1000;

        for ( $i = $state['table_index']; $i < count( $table_names ); $i++ ) {
            // Check timeout
            if ( ( time() - $start_time ) >= $this->chunk_timeout ) {
                fclose( $handle );
                $state['table_index'] = $i;
                return false;
            }

            $table_name = $table_names[ $i ];

            // Write table structure if starting this table
            if ( $state['table_offset'] === 0 ) {
                $create_result = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_N );
                if ( $create_result ) {
                    fwrite( $handle, "-- Table: $table_name\n" );
                    fwrite( $handle, "DROP TABLE IF EXISTS `$table_name`;\n" );
                    fwrite( $handle, $create_result[1] . ";\n\n" );
                }
            }

            // Export data in chunks
            do {
                if ( ( time() - $start_time ) >= $this->chunk_timeout ) {
                    fclose( $handle );
                    $state['table_index']  = $i;
                    return false;
                }

                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `$table_name` LIMIT %d OFFSET %d",
                        $limit,
                        $state['table_offset']
                    ),
                    ARRAY_A
                );

                if ( $rows ) {
                    foreach ( $rows as $row ) {
                        // Apply spam comment exclusion
                        if ( $state['options']['exclude_spam_comments']
                            && $table_name === $wpdb->comments
                            && isset( $row['comment_approved'] )
                            && $row['comment_approved'] === 'spam'
                        ) {
                            continue;
                        }

                        // Apply post revision exclusion
                        if ( $state['options']['exclude_post_revisions']
                            && $table_name === $wpdb->posts
                            && isset( $row['post_type'] )
                            && $row['post_type'] === 'revision'
                        ) {
                            continue;
                        }

                        $values = array_map( function( $v ) use ( $wpdb ) {
                            if ( $v === null ) {
                                return 'NULL';
                            }
                            return "'" . $wpdb->_real_escape( $v ) . "'";
                        }, $row );

                        fwrite( $handle, "INSERT INTO `$table_name` VALUES (" . implode( ',', $values ) . ");\n" );
                    }
                }

                $state['table_offset'] += $limit;
                $fetched = count( $rows );
                unset( $rows );

            } while ( $fetched === $limit );

            fwrite( $handle, "\n" );

            // Reset offset for next table
            $state['table_offset'] = 0;
            $state['table_index']  = $i + 1;
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
        fclose( $handle );

        return true;
    }

    /**
     * Phase: Enumerate files to archive.
     *
     * @param array $state Migration state (by reference).
     */
    private function phase_enumerate( &$state ) {
        $filemap_path = $state['working_dir'] . '/filemap.txt';
        $handle = fopen( $filemap_path, 'w' );
        if ( ! $handle ) {
            $state['error'] = 'Failed to create filemap';
            return;
        }

        $exclusions = $this->get_exclusion_filters( $state['options'] );
        $base_dir   = WP_CONTENT_DIR;
        $total_files = 0;
        $total_size  = 0;

        $this->enumerate_directory( $base_dir, '', $exclusions, $handle, $total_files, $total_size );

        fclose( $handle );

        $state['total_files_count'] = $total_files;
        $state['total_files_size']  = $total_size;
    }

    /**
     * Recursively enumerate a directory.
     *
     * @param string   $base_dir   The base wp-content directory.
     * @param string   $relative   Current relative path from base.
     * @param array    $exclusions Directory names to exclude.
     * @param resource $handle     File handle for writing paths.
     * @param int      $total_files Total file count (by reference).
     * @param int      $total_size  Total size in bytes (by reference).
     */
    private function enumerate_directory( $base_dir, $relative, $exclusions, $handle, &$total_files, &$total_size ) {
        $full_path = $base_dir . ( $relative ? '/' . $relative : '' );

        if ( ! is_dir( $full_path ) || ! is_readable( $full_path ) ) {
            return;
        }

        $items = @scandir( $full_path );
        if ( $items === false ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $item_relative = $relative ? $relative . '/' . $item : $item;
            $item_full     = $base_dir . '/' . $item_relative;

            // Check exclusions at directory level
            if ( is_dir( $item_full ) ) {
                $should_exclude = false;
                foreach ( $exclusions as $exclusion ) {
                    // Check if the directory name or relative path matches an exclusion
                    if ( $item === $exclusion || strpos( $item_relative, $exclusion ) === 0 ) {
                        $should_exclude = true;
                        break;
                    }
                }
                if ( $should_exclude ) {
                    continue;
                }
                $this->enumerate_directory( $base_dir, $item_relative, $exclusions, $handle, $total_files, $total_size );
            } else {
                // Skip unreadable files
                if ( ! is_readable( $item_full ) ) {
                    continue;
                }

                $size = @filesize( $item_full );
                if ( $size === false ) {
                    continue;
                }

                fwrite( $handle, $item_relative . "\n" );
                $total_files++;
                $total_size += $size;
            }
        }
    }

    /**
     * Get directory exclusion list based on export options.
     *
     * @param array $options Export options.
     * @return array Directory names/paths to exclude.
     */
    private function get_exclusion_filters( $options ) {
        $exclusions = array(
            'wp-care-backups',
            'wp-care-migrations',
            'ai1wm-backups',
            'upgrade',
            'debug.log',
        );

        if ( ! $options['include_themes'] ) {
            $exclusions[] = 'themes';
        } elseif ( $options['exclude_inactive_themes'] ) {
            // Exclude all themes except active and parent theme
            $active_theme = get_stylesheet();
            $parent_theme = get_template();
            $themes_dir   = WP_CONTENT_DIR . '/themes';
            if ( is_dir( $themes_dir ) ) {
                $theme_dirs = @scandir( $themes_dir );
                if ( $theme_dirs ) {
                    foreach ( $theme_dirs as $theme ) {
                        if ( $theme === '.' || $theme === '..' ) {
                            continue;
                        }
                        if ( $theme !== $active_theme && $theme !== $parent_theme ) {
                            $exclusions[] = 'themes/' . $theme;
                        }
                    }
                }
            }
        }

        if ( ! $options['include_plugins'] ) {
            $exclusions[] = 'plugins';
        } elseif ( $options['exclude_inactive_plugins'] ) {
            $active_plugins = get_option( 'active_plugins', array() );
            $active_dirs    = array();
            foreach ( $active_plugins as $plugin ) {
                $parts = explode( '/', $plugin );
                if ( count( $parts ) > 1 ) {
                    $active_dirs[] = $parts[0];
                }
            }
            // Always include wp-care-connector
            $active_dirs[] = 'wp-care-connector';

            $plugins_dir = WP_CONTENT_DIR . '/plugins';
            if ( is_dir( $plugins_dir ) ) {
                $plugin_dirs = @scandir( $plugins_dir );
                if ( $plugin_dirs ) {
                    foreach ( $plugin_dirs as $plugin ) {
                        if ( $plugin === '.' || $plugin === '..' ) {
                            continue;
                        }
                        if ( is_dir( $plugins_dir . '/' . $plugin ) && ! in_array( $plugin, $active_dirs, true ) ) {
                            $exclusions[] = 'plugins/' . $plugin;
                        }
                    }
                }
            }
        }

        if ( ! $options['include_uploads'] ) {
            $exclusions[] = 'uploads';
        }

        if ( ! $options['include_mu_plugins'] ) {
            $exclusions[] = 'mu-plugins';
        }

        if ( $options['exclude_cache'] ) {
            $exclusions[] = 'cache';
            $exclusions[] = 'et-cache';
            $exclusions[] = 'w3tc-config';
            $exclusions[] = 'wp-rocket-config';
        }

        return $exclusions;
    }

    /**
     * Phase: Add files to ZIP archive.
     *
     * @param array $state      Migration state (by reference).
     * @param int   $start_time Start timestamp for timeout tracking.
     * @return bool True if archiving is complete.
     */
    private function phase_archive( &$state, $start_time ) {
        $zip_path     = $state['working_dir'] . '/migration.zip';
        $filemap_path = $state['working_dir'] . '/filemap.txt';

        $archiver = $this->get_archiver();
        if ( ! $archiver ) {
            $state['error'] = 'No ZIP archiver available. Please install the PHP zip extension.';
            return true;
        }

        if ( $archiver === 'ziparchive' ) {
            return $this->archive_with_ziparchive( $zip_path, $filemap_path, $state, $start_time );
        } else {
            return $this->archive_with_pclzip( $zip_path, $filemap_path, $state, $start_time );
        }
    }

    /**
     * Archive files using ZipArchive.
     *
     * @param string $zip_path     Path to ZIP file.
     * @param string $filemap_path Path to filemap.
     * @param array  $state        Migration state (by reference).
     * @param int    $start_time   Start timestamp.
     * @return bool True if complete.
     */
    private function archive_with_ziparchive( $zip_path, $filemap_path, &$state, $start_time ) {
        $zip = new ZipArchive();
        $flags = file_exists( $zip_path ) ? ZipArchive::CREATE : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
        $result = $zip->open( $zip_path, $flags );

        if ( $result !== true ) {
            $state['error'] = 'Failed to open ZIP archive (error code: ' . $result . ')';
            return true;
        }

        // Add config and database files first (only once)
        if ( ! $state['config_archived'] ) {
            $config_path = $state['working_dir'] . '/package.json';
            if ( file_exists( $config_path ) ) {
                $zip->addFile( $config_path, 'package.json' );
            }
            $state['config_archived'] = true;
        }

        if ( ! $state['db_archived'] && $state['options']['include_database'] ) {
            $sql_path = $state['working_dir'] . '/database.sql';
            if ( file_exists( $sql_path ) ) {
                $zip->addFile( $sql_path, 'database.sql' );
            }
            $state['db_archived'] = true;
        }

        // Process files from filemap
        if ( ! file_exists( $filemap_path ) ) {
            $zip->close();
            return true;
        }

        $handle = fopen( $filemap_path, 'r' );
        if ( ! $handle ) {
            $zip->close();
            $state['error'] = 'Failed to open filemap';
            return true;
        }

        // Seek to last position
        if ( $state['filemap_offset'] > 0 ) {
            fseek( $handle, $state['filemap_offset'] );
        }

        while ( ( $line = fgets( $handle ) ) !== false ) {
            if ( ( time() - $start_time ) >= $this->chunk_timeout ) {
                $state['filemap_offset'] = ftell( $handle );
                fclose( $handle );
                $zip->close();
                return false;
            }

            $relative_path = trim( $line );
            if ( empty( $relative_path ) ) {
                continue;
            }

            $full_path = WP_CONTENT_DIR . '/' . $relative_path;

            // Security: ensure path is within wp-content
            $real_path = realpath( $full_path );
            $real_content_dir = realpath( WP_CONTENT_DIR );
            if ( $real_path === false || strpos( $real_path, $real_content_dir ) !== 0 ) {
                continue;
            }

            if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
                $zip->addFile( $full_path, 'wp-content/' . $relative_path );
                $state['archived_files']++;
                $state['archived_size'] += filesize( $full_path );
            }
        }

        fclose( $handle );
        $zip->close();
        return true;
    }

    /**
     * Archive files using PclZip (WordPress bundled fallback).
     *
     * @param string $zip_path     Path to ZIP file.
     * @param string $filemap_path Path to filemap.
     * @param array  $state        Migration state (by reference).
     * @param int    $start_time   Start timestamp.
     * @return bool True if complete.
     */
    private function archive_with_pclzip( $zip_path, $filemap_path, &$state, $start_time ) {
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

        // PclZip doesn't support incremental adds as well, so we build file list and add in batches
        $handle = fopen( $filemap_path, 'r' );
        if ( ! $handle ) {
            $state['error'] = 'Failed to open filemap';
            return true;
        }

        if ( $state['filemap_offset'] > 0 ) {
            fseek( $handle, $state['filemap_offset'] );
        }

        $files_to_add = array();
        $batch_size   = 50;

        // Add config and database files first
        if ( ! $state['config_archived'] ) {
            $config_path = $state['working_dir'] . '/package.json';
            if ( file_exists( $config_path ) ) {
                $files_to_add[] = $config_path;
            }
            $state['config_archived'] = true;
        }

        if ( ! $state['db_archived'] && $state['options']['include_database'] ) {
            $sql_path = $state['working_dir'] . '/database.sql';
            if ( file_exists( $sql_path ) ) {
                $files_to_add[] = $sql_path;
            }
            $state['db_archived'] = true;
        }

        while ( ( $line = fgets( $handle ) ) !== false ) {
            if ( ( time() - $start_time ) >= $this->chunk_timeout ) {
                // Flush current batch
                if ( ! empty( $files_to_add ) ) {
                    $zip = new PclZip( $zip_path );
                    $zip->add( $files_to_add, PCLZIP_OPT_REMOVE_PATH, WP_CONTENT_DIR, PCLZIP_OPT_ADD_PATH, 'wp-content' );
                }
                $state['filemap_offset'] = ftell( $handle );
                fclose( $handle );
                return false;
            }

            $relative_path = trim( $line );
            if ( empty( $relative_path ) ) {
                continue;
            }

            $full_path = WP_CONTENT_DIR . '/' . $relative_path;

            $real_path = realpath( $full_path );
            $real_content_dir = realpath( WP_CONTENT_DIR );
            if ( $real_path === false || strpos( $real_path, $real_content_dir ) !== 0 ) {
                continue;
            }

            if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
                $files_to_add[] = $full_path;
                $state['archived_files']++;
                $state['archived_size'] += filesize( $full_path );
            }

            // Flush batch
            if ( count( $files_to_add ) >= $batch_size ) {
                $zip = new PclZip( $zip_path );
                $zip->add( $files_to_add, PCLZIP_OPT_REMOVE_PATH, WP_CONTENT_DIR, PCLZIP_OPT_ADD_PATH, 'wp-content' );
                $files_to_add = array();
            }
        }

        // Flush remaining
        if ( ! empty( $files_to_add ) ) {
            $zip = new PclZip( $zip_path );
            $zip->add( $files_to_add, PCLZIP_OPT_REMOVE_PATH, WP_CONTENT_DIR, PCLZIP_OPT_ADD_PATH, 'wp-content' );
        }

        fclose( $handle );
        return true;
    }

    /**
     * Phase: Finalize the migration archive.
     *
     * @param array $state Migration state (by reference).
     */
    private function phase_finalize( &$state ) {
        $zip_path = $state['working_dir'] . '/migration.zip';

        if ( ! file_exists( $zip_path ) ) {
            $state['error'] = 'Migration ZIP file not found';
            return;
        }

        $archive_size = filesize( $zip_path );

        // Write migration metadata
        $metadata = array(
            'id'                  => $state['migration_id'],
            'created_at'          => $state['created_at'],
            'completed_at'        => gmdate( 'c' ),
            'site_url'            => get_site_url(),
            'wp_version'          => get_bloginfo( 'version' ),
            'php_version'         => phpversion(),
            'plugin_version'      => WP_CARE_VERSION,
            'archive_file'        => 'migration.zip',
            'archive_size'        => $archive_size,
            'archive_size_human'  => size_format( $archive_size ),
            'options'             => $state['options'],
            'total_files'         => $state['archived_files'],
            'total_files_size'    => $state['archived_size'],
        );

        $metadata_path = $state['working_dir'] . '/migration.json';
        file_put_contents( $metadata_path, wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        // Cleanup temporary working files
        $temp_files = array( 'filemap.txt', 'state.json', 'database.sql', 'package.json' );
        foreach ( $temp_files as $file ) {
            $path = $state['working_dir'] . '/' . $file;
            if ( file_exists( $path ) ) {
                unlink( $path );
            }
        }

        // Store archive info in state for the response
        $state['archive_size']       = $archive_size;
        $state['archive_size_human'] = size_format( $archive_size );

        // Cleanup old migrations
        $this->cleanup_old_migrations();
    }

    /**
     * Build package.json with site metadata.
     *
     * @param array $options Export options.
     * @return array Config data.
     */
    private function build_package_config( $options ) {
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_data    = array();
        foreach ( $active_plugins as $plugin_file ) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if ( file_exists( $plugin_path ) ) {
                $data = get_plugin_data( $plugin_path, false, false );
                $plugin_data[] = array(
                    'file'    => $plugin_file,
                    'name'    => $data['Name'],
                    'version' => $data['Version'],
                );
            }
        }

        return array(
            'generator'     => 'wp-care-connector',
            'version'       => WP_CARE_VERSION,
            'created_at'    => gmdate( 'c' ),
            'site_url'      => get_site_url(),
            'home_url'      => get_home_url(),
            'wp_version'    => get_bloginfo( 'version' ),
            'php_version'   => phpversion(),
            'db_version'    => get_option( 'db_version' ),
            'charset'       => get_bloginfo( 'charset' ),
            'language'      => get_locale(),
            'theme'         => get_stylesheet(),
            'parent_theme'  => get_template() !== get_stylesheet() ? get_template() : null,
            'plugins'       => $plugin_data,
            'table_prefix'  => $GLOBALS['table_prefix'],
            'options'       => $options,
        );
    }

    /**
     * Check if WP-CLI is available.
     *
     * @return bool
     */
    private function check_wp_cli() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        $which_wp = shell_exec( 'which wp 2>/dev/null' );
        return ! empty( $which_wp );
    }

    /**
     * Determine which ZIP archiver is available.
     *
     * @return string|false 'ziparchive', 'pclzip', or false.
     */
    private function get_archiver() {
        if ( class_exists( 'ZipArchive' ) ) {
            return 'ziparchive';
        }

        $pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
        if ( file_exists( $pclzip_path ) ) {
            return 'pclzip';
        }

        return false;
    }

    /**
     * Save migration state to JSON file.
     *
     * @param string $migration_id Migration ID.
     * @param array  $state        State data.
     * @return bool
     */
    private function save_state( $migration_id, $state ) {
        $dir = $this->migration_dir . '/' . $migration_id;
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        return (bool) file_put_contents(
            $dir . '/state.json',
            wp_json_encode( $state, JSON_PRETTY_PRINT )
        );
    }

    /**
     * Load migration state from JSON file.
     *
     * @param string $migration_id Migration ID.
     * @return array|false
     */
    private function load_state( $migration_id ) {
        $file = $this->migration_dir . '/' . sanitize_file_name( $migration_id ) . '/state.json';
        if ( ! file_exists( $file ) ) {
            return false;
        }
        $data = json_decode( file_get_contents( $file ), true );
        return is_array( $data ) ? $data : false;
    }

    /**
     * List all available migration backups.
     *
     * @return array Migration metadata sorted by creation date descending.
     */
    public function list_migrations() {
        $migrations = array();

        if ( ! is_dir( $this->migration_dir ) ) {
            return $migrations;
        }

        $dirs = scandir( $this->migration_dir );
        foreach ( $dirs as $dir ) {
            if ( $dir === '.' || $dir === '..' ) {
                continue;
            }

            $metadata_file = $this->migration_dir . '/' . $dir . '/migration.json';
            if ( file_exists( $metadata_file ) ) {
                $metadata = json_decode( file_get_contents( $metadata_file ), true );
                if ( $metadata ) {
                    $migrations[] = $metadata;
                }
            }
        }

        usort( $migrations, function( $a, $b ) {
            return strcmp( $b['created_at'], $a['created_at'] );
        } );

        return $migrations;
    }

    /**
     * Delete a migration backup.
     *
     * @param string $migration_id Migration ID.
     * @return bool
     */
    public function delete_migration( $migration_id ) {
        $migration_id = sanitize_file_name( $migration_id );
        $dir = $this->migration_dir . '/' . $migration_id;

        if ( ! is_dir( $dir ) ) {
            return false;
        }

        return $this->recursive_delete( $dir );
    }

    /**
     * Get the download file path for a completed migration.
     *
     * @param string $migration_id Migration ID.
     * @return string|false File path or false.
     */
    public function get_download_path( $migration_id ) {
        $migration_id = sanitize_file_name( $migration_id );
        $zip_path = $this->migration_dir . '/' . $migration_id . '/migration.zip';

        if ( ! file_exists( $zip_path ) ) {
            return false;
        }

        return $zip_path;
    }

    /**
     * Get migration metadata.
     *
     * @param string $migration_id Migration ID.
     * @return array|false
     */
    public function get_migration_info( $migration_id ) {
        $migration_id = sanitize_file_name( $migration_id );
        $metadata_file = $this->migration_dir . '/' . $migration_id . '/migration.json';

        if ( ! file_exists( $metadata_file ) ) {
            return false;
        }

        $data = json_decode( file_get_contents( $metadata_file ), true );
        return is_array( $data ) ? $data : false;
    }

    // =========================================================================
    // Upload
    // =========================================================================

    /**
     * Handle an uploaded migration ZIP file.
     *
     * Validates the archive contains a package.json, stores it
     * in the migrations directory, and writes migration.json metadata.
     *
     * @param array $file $_FILES entry for the uploaded file.
     * @return array|WP_Error Migration metadata on success, WP_Error on failure.
     */
    public function handle_upload( $file ) {
        if ( ! self::ensure_migration_dir() ) {
            return new WP_Error( 'dir_failed', 'Migration directory is not writable.' );
        }

        // Basic file validation
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'upload_failed', 'No file was uploaded.' );
        }

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', 'Upload error code: ' . $file['error'] );
        }

        // Validate file extension
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'zip' ) {
            return new WP_Error( 'invalid_type', 'Only .zip files are accepted.' );
        }

        // Validate it's actually a ZIP by trying to open it
        $archiver = $this->get_archiver();
        if ( ! $archiver ) {
            return new WP_Error( 'no_archiver', 'No ZIP archiver available on this server.' );
        }

        $validation = $this->validate_archive( $file['tmp_name'] );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Create migration directory
        $migration_id = 'upload_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
        $working_dir  = $this->migration_dir . '/' . $migration_id;

        if ( ! wp_mkdir_p( $working_dir ) ) {
            return new WP_Error( 'dir_failed', 'Failed to create migration directory.' );
        }

        // Move uploaded file
        $zip_path = $working_dir . '/migration.zip';
        if ( ! move_uploaded_file( $file['tmp_name'], $zip_path ) ) {
            $this->recursive_delete( $working_dir );
            return new WP_Error( 'move_failed', 'Failed to store uploaded file.' );
        }

        $archive_size = filesize( $zip_path );

        // Write migration metadata
        $metadata = array(
            'id'                 => $migration_id,
            'created_at'         => gmdate( 'c' ),
            'completed_at'       => gmdate( 'c' ),
            'site_url'           => isset( $validation['site_url'] ) ? $validation['site_url'] : 'unknown',
            'wp_version'         => isset( $validation['wp_version'] ) ? $validation['wp_version'] : 'unknown',
            'php_version'        => isset( $validation['php_version'] ) ? $validation['php_version'] : 'unknown',
            'plugin_version'     => isset( $validation['version'] ) ? $validation['version'] : 'unknown',
            'archive_file'       => 'migration.zip',
            'archive_size'       => $archive_size,
            'archive_size_human' => size_format( $archive_size ),
            'source'             => 'upload',
            'original_filename'  => sanitize_file_name( $file['name'] ),
            'has_database'       => $validation['has_database'],
            'has_files'          => $validation['has_files'],
            'total_files'        => 0,
            'total_files_size'   => 0,
        );

        $metadata_path = $working_dir . '/migration.json';
        file_put_contents( $metadata_path, wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        $this->cleanup_old_migrations();

        return $metadata;
    }

    /**
     * Validate that a ZIP file is a valid migration archive.
     *
     * Checks for the presence of package.json and/or database.sql.
     *
     * @param string $zip_path Path to the ZIP file.
     * @return array|WP_Error Validation info or error.
     */
    private function validate_archive( $zip_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            // Fall back to basic size check if ZipArchive not available
            if ( filesize( $zip_path ) < 100 ) {
                return new WP_Error( 'invalid_archive', 'Archive file appears to be empty or corrupt.' );
            }
            return array(
                'has_database' => false,
                'has_files'    => true,
                'site_url'     => null,
                'wp_version'   => null,
                'php_version'  => null,
                'version'      => null,
            );
        }

        $zip = new ZipArchive();
        $result = $zip->open( $zip_path );
        if ( $result !== true ) {
            return new WP_Error( 'invalid_archive', 'Could not open ZIP file. It may be corrupt.' );
        }

        $has_database = ( $zip->locateName( 'database.sql' ) !== false );
        $has_files    = ( $zip->numFiles > ( $has_database ? 1 : 0 ) );

        // Try to read package.json for metadata
        $config = array(
            'site_url'    => null,
            'wp_version'  => null,
            'php_version' => null,
            'version'     => null,
        );

        $package_index = $zip->locateName( 'package.json' );
        if ( $package_index !== false ) {
            $package_content = $zip->getFromIndex( $package_index );
            if ( $package_content ) {
                $package = json_decode( $package_content, true );
                if ( is_array( $package ) ) {
                    $config['site_url']    = isset( $package['site_url'] ) ? $package['site_url'] : null;
                    $config['wp_version']  = isset( $package['wp_version'] ) ? $package['wp_version'] : null;
                    $config['php_version'] = isset( $package['php_version'] ) ? $package['php_version'] : null;
                    $config['version']     = isset( $package['version'] ) ? $package['version'] : null;
                }
            }
        }

        $zip->close();

        if ( ! $has_database && ! $has_files ) {
            return new WP_Error( 'empty_archive', 'The archive does not contain any migration data.' );
        }

        return array_merge( $config, array(
            'has_database' => $has_database,
            'has_files'    => $has_files,
        ) );
    }

    // =========================================================================
    // Restore
    // =========================================================================

    /**
     * Initialize a restore operation from a migration backup.
     *
     * Creates a restore state file to track chunked progress.
     *
     * @param string $migration_id Migration ID to restore from.
     * @param array  $options      Restore options: 'restore_database', 'restore_files'.
     * @return array|WP_Error Restore state or error.
     */
    public function init_restore( $migration_id, $options = array() ) {
        $migration_id = sanitize_file_name( $migration_id );
        $zip_path     = $this->get_download_path( $migration_id );

        if ( ! $zip_path ) {
            return new WP_Error( 'not_found', 'Migration backup not found.' );
        }

        $info = $this->get_migration_info( $migration_id );

        $defaults = array(
            'restore_database' => true,
            'restore_files'    => true,
        );
        $options = wp_parse_args( $options, $defaults );

        $state = array(
            'migration_id'       => $migration_id,
            'phase'              => 'checkpoint',
            'progress'           => 0,
            'completed'          => false,
            'error'              => null,
            'options'            => $options,
            'started_at'         => gmdate( 'c' ),
            'checkpoint_id'      => null,
            // Extract tracking
            'extracted_files'    => 0,
            'total_entries'      => 0,
            'zip_index'          => 0,
            'db_imported'        => false,
        );

        $working_dir = $this->migration_dir . '/' . $migration_id;
        if ( ! $this->save_state( $migration_id, $state ) ) {
            return new WP_Error( 'state_failed', 'Failed to save restore state.' );
        }

        return $state;
    }

    /**
     * Process next chunk of a restore operation.
     *
     * @param string $migration_id The migration ID being restored.
     * @return array Updated restore state.
     */
    public function process_restore_chunk( $migration_id ) {
        $state = $this->load_state( $migration_id );
        if ( ! $state ) {
            return array( 'error' => 'Restore state not found', 'completed' => false );
        }

        if ( $state['completed'] || $state['error'] ) {
            return $state;
        }

        $start_time = time();

        switch ( $state['phase'] ) {
            case 'checkpoint':
                $this->restore_phase_checkpoint( $state );
                $state['phase']    = 'database';
                $state['progress'] = 10;
                break;

            case 'database':
                if ( ! $state['options']['restore_database'] ) {
                    $state['phase']    = 'files';
                    $state['progress'] = 40;
                    $state['db_imported'] = true;
                } else {
                    $done = $this->restore_phase_database( $state );
                    if ( $done ) {
                        $state['phase']    = 'files';
                        $state['progress'] = 40;
                    } else {
                        $state['progress'] = 20;
                    }
                }
                break;

            case 'files':
                if ( ! $state['options']['restore_files'] ) {
                    $state['phase']     = 'complete';
                    $state['progress']  = 100;
                    $state['completed'] = true;
                } else {
                    $done = $this->restore_phase_files( $state, $start_time );
                    if ( $done ) {
                        $state['phase']     = 'complete';
                        $state['progress']  = 100;
                        $state['completed'] = true;
                    } else {
                        if ( $state['total_entries'] > 0 ) {
                            $state['progress'] = 40 + (int) ( 60 * $state['extracted_files'] / $state['total_entries'] );
                        }
                    }
                }
                break;
        }

        $this->save_state( $migration_id, $state );
        return $state;
    }

    /**
     * Run a full restore in one go (for remote command use).
     *
     * @param string $migration_id Migration ID to restore.
     * @param array  $options      Restore options.
     * @return array Final restore state.
     */
    public function run_full_restore( $migration_id, $options = array() ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        $state = $this->init_restore( $migration_id, $options );
        if ( is_wp_error( $state ) ) {
            return array( 'error' => $state->get_error_message(), 'completed' => false );
        }

        $this->chunk_timeout = 300;

        while ( ! $state['completed'] && ! $state['error'] ) {
            $state = $this->process_restore_chunk( $migration_id );
        }

        return $state;
    }

    /**
     * Restore phase: Create a database checkpoint before restoring.
     *
     * @param array $state Restore state (by reference).
     */
    private function restore_phase_checkpoint( &$state ) {
        $backup = new WP_Care_Backup();
        $checkpoint_id = $backup->create_checkpoint( 'pre_migration_restore' );

        if ( $checkpoint_id === false ) {
            error_log( 'WP Care Migration: Failed to create pre-restore checkpoint, proceeding anyway' );
        } else {
            $state['checkpoint_id'] = $checkpoint_id;
        }
    }

    /**
     * Restore phase: Extract and import database.sql from the archive.
     *
     * @param array $state Restore state (by reference).
     * @return bool True if complete.
     */
    private function restore_phase_database( &$state ) {
        if ( $state['db_imported'] ) {
            return true;
        }

        $zip_path = $this->get_download_path( $state['migration_id'] );
        if ( ! $zip_path ) {
            $state['error'] = 'Migration archive not found.';
            return true;
        }

        // Extract database.sql to a temp file
        $working_dir = dirname( $zip_path );
        $sql_path    = $working_dir . '/restore_database.sql';

        if ( ! file_exists( $sql_path ) ) {
            if ( class_exists( 'ZipArchive' ) ) {
                $zip = new ZipArchive();
                if ( $zip->open( $zip_path ) !== true ) {
                    $state['error'] = 'Failed to open migration archive.';
                    return true;
                }

                if ( $zip->locateName( 'database.sql' ) === false ) {
                    $zip->close();
                    $state['db_imported'] = true;
                    return true; // No database in archive, skip
                }

                $zip->extractTo( $working_dir, array( 'database.sql' ) );
                $zip->close();

                // Rename to avoid conflict with export temp files
                if ( file_exists( $working_dir . '/database.sql' ) ) {
                    rename( $working_dir . '/database.sql', $sql_path );
                }
            } else {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
                $zip = new PclZip( $zip_path );
                $result = $zip->extract( PCLZIP_OPT_BY_NAME, 'database.sql', PCLZIP_OPT_PATH, $working_dir );
                if ( $result && file_exists( $working_dir . '/database.sql' ) ) {
                    rename( $working_dir . '/database.sql', $sql_path );
                }
            }
        }

        if ( ! file_exists( $sql_path ) ) {
            // No database.sql found, skip
            $state['db_imported'] = true;
            return true;
        }

        // Import using WP-CLI or PHP fallback (reuse backup class pattern)
        $import_success = false;

        if ( $this->check_wp_cli() ) {
            $abspath = ABSPATH;
            $command = sprintf(
                'wp db import %s --path=%s 2>&1',
                escapeshellarg( $sql_path ),
                escapeshellarg( $abspath )
            );
            $output = shell_exec( $command );
            $import_success = ( strpos( $output, 'Success' ) !== false || strpos( $output, 'Query OK' ) !== false );
        }

        if ( ! $import_success ) {
            $import_success = $this->import_database_php( $sql_path );
        }

        // Cleanup temp SQL file
        if ( file_exists( $sql_path ) ) {
            unlink( $sql_path );
        }

        if ( ! $import_success ) {
            $state['error'] = 'Database import failed. Your previous database has been preserved in checkpoint: ' . ( $state['checkpoint_id'] ? $state['checkpoint_id'] : 'none' );
            return true;
        }

        $state['db_imported'] = true;
        return true;
    }

    /**
     * Import a SQL file using PHP (fallback when WP-CLI unavailable).
     *
     * @param string $filepath Path to SQL file.
     * @return bool True on success.
     */
    private function import_database_php( $filepath ) {
        global $wpdb;

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return false;
        }

        $statement     = '';
        $error_count   = 0;
        $success_count = 0;

        while ( ( $line = fgets( $handle ) ) !== false ) {
            $trimmed = trim( $line );
            if ( empty( $trimmed ) || strpos( $trimmed, '--' ) === 0 ) {
                continue;
            }

            $statement .= $line;

            if ( substr( rtrim( $statement ), -1 ) === ';' ) {
                $result = $wpdb->query( $statement );
                if ( $result === false ) {
                    $error_count++;
                } else {
                    $success_count++;
                }
                $statement = '';
            }
        }

        fclose( $handle );

        if ( $error_count > 0 ) {
            error_log( sprintf( 'WP Care Migration: DB import completed with %d errors and %d successes', $error_count, $success_count ) );
        }

        return $success_count > $error_count;
    }

    /**
     * Restore phase: Extract wp-content files from the archive.
     *
     * @param array $state      Restore state (by reference).
     * @param int   $start_time Start timestamp for timeout tracking.
     * @return bool True if complete.
     */
    private function restore_phase_files( &$state, $start_time ) {
        $zip_path = $this->get_download_path( $state['migration_id'] );
        if ( ! $zip_path ) {
            $state['error'] = 'Migration archive not found.';
            return true;
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            // PclZip fallback: extract all at once (no chunking support)
            return $this->restore_files_pclzip( $zip_path, $state );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            $state['error'] = 'Failed to open migration archive.';
            return true;
        }

        $state['total_entries'] = $zip->numFiles;

        for ( $i = $state['zip_index']; $i < $zip->numFiles; $i++ ) {
            if ( ( time() - $start_time ) >= $this->chunk_timeout ) {
                $state['zip_index'] = $i;
                $zip->close();
                return false;
            }

            $entry_name = $zip->getNameIndex( $i );
            if ( $entry_name === false ) {
                continue;
            }

            // Only extract wp-content/ entries
            if ( strpos( $entry_name, 'wp-content/' ) !== 0 ) {
                continue;
            }

            // Get relative path within wp-content
            $relative = substr( $entry_name, strlen( 'wp-content/' ) );
            if ( empty( $relative ) || substr( $relative, -1 ) === '/' ) {
                continue; // Skip directories (they'll be created automatically)
            }

            // Security: prevent path traversal
            if ( strpos( $relative, '..' ) !== false ) {
                continue;
            }

            $target_path = WP_CONTENT_DIR . '/' . $relative;
            $target_dir  = dirname( $target_path );

            // Create target directory if needed
            if ( ! is_dir( $target_dir ) ) {
                wp_mkdir_p( $target_dir );
            }

            // Extract the single file
            $content = $zip->getFromIndex( $i );
            if ( $content !== false ) {
                file_put_contents( $target_path, $content );
                $state['extracted_files']++;
            }
        }

        $zip->close();
        return true;
    }

    /**
     * Restore files using PclZip fallback (non-chunked).
     *
     * @param string $zip_path Path to ZIP file.
     * @param array  $state    Restore state (by reference).
     * @return bool True when complete.
     */
    private function restore_files_pclzip( $zip_path, &$state ) {
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

        $zip = new PclZip( $zip_path );
        $list = $zip->extract(
            PCLZIP_OPT_PATH, WP_CONTENT_DIR,
            PCLZIP_OPT_BY_PREG, '/^wp-content\//',
            PCLZIP_OPT_REMOVE_PATH, 'wp-content'
        );

        if ( $list === 0 ) {
            $state['error'] = 'File extraction failed: ' . $zip->errorInfo( true );
            return true;
        }

        $state['extracted_files'] = is_array( $list ) ? count( $list ) : 0;
        return true;
    }

    /**
     * Cleanup old migrations exceeding max count.
     */
    private function cleanup_old_migrations() {
        $migrations = $this->list_migrations();

        if ( count( $migrations ) > $this->max_migrations ) {
            $to_delete = array_slice( $migrations, $this->max_migrations );
            foreach ( $to_delete as $migration ) {
                $this->delete_migration( $migration['id'] );
            }
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path.
     * @return bool
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
}
