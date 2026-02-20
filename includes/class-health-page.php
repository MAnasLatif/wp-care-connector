<?php
/**
 * WP Care Health Page
 *
 * Admin page showing site health information using data from WP_Care_Site_Mapper.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Care_Health_Page {

    /**
     * Render the site health page.
     *
     * @return void
     */
    public static function render() {
        $site_mapper = new WP_Care_Site_Mapper();
        $data = $site_mapper->get_site_map( true );

        // Get WordPress update info
        $core_updates = get_site_transient( 'update_core' );
        $wp_update_available = false;
        if ( $core_updates && ! empty( $core_updates->updates ) ) {
            foreach ( $core_updates->updates as $update ) {
                if ( 'upgrade' === $update->response ) {
                    $wp_update_available = $update->current;
                    break;
                }
            }
        }

        // Calculate disk usage
        $upload_dir = wp_upload_dir();
        $disk_usage = self::get_directory_size( $upload_dir['basedir'] );

        // Get database size
        $db_size = self::get_database_size();

        // Last health report
        $last_report = get_option( 'wp_care_last_health_report', 0 );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Site Health', 'wp-care-connector' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Overview of your WordPress site health and configuration.', 'wp-care-connector' ); ?></p>

            <div class="wp-care-tools-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">

                <!-- Core Versions -->
                <div class="card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-wordpress" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Core', 'wp-care-connector' ); ?>
                    </h2>
                    <table class="widefat striped" style="border: none;">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e( 'WordPress', 'wp-care-connector' ); ?></strong></td>
                                <td>
                                    <?php echo esc_html( $data['wp_version'] ); ?>
                                    <?php if ( $wp_update_available ) : ?>
                                        <span style="color: #d63638; font-weight: 600;">
                                            &rarr; <?php echo esc_html( $wp_update_available ); ?> <?php esc_html_e( 'available', 'wp-care-connector' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color: #00a32a;"><?php esc_html_e( 'Up to date', 'wp-care-connector' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'PHP', 'wp-care-connector' ); ?></strong></td>
                                <td>
                                    <?php echo esc_html( $data['php_version'] ); ?>
                                    <?php if ( version_compare( $data['php_version'], '8.1', '<' ) ) : ?>
                                        <span style="color: #d63638;"><?php esc_html_e( 'Outdated — 8.1+ recommended', 'wp-care-connector' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #00a32a;"><?php esc_html_e( 'Good', 'wp-care-connector' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'Memory Limit', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( $data['environment']['memory_limit'] ); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'Max Upload', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( $data['environment']['max_upload_size'] ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Theme -->
                <div class="card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-admin-appearance" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Theme', 'wp-care-connector' ); ?>
                    </h2>
                    <table class="widefat striped" style="border: none;">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e( 'Active Theme', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( $data['theme']['name'] ); ?> (v<?php echo esc_html( $data['theme']['version'] ); ?>)</td>
                            </tr>
                            <?php if ( $data['theme']['parent'] ) : ?>
                            <tr>
                                <td><strong><?php esc_html_e( 'Parent Theme', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( $data['theme']['parent']['name'] ); ?> (v<?php echo esc_html( $data['theme']['parent']['version'] ); ?>)</td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td><strong><?php esc_html_e( 'Block Theme', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo $data['theme']['is_block_theme'] ? esc_html__( 'Yes', 'wp-care-connector' ) : esc_html__( 'No', 'wp-care-connector' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Storage -->
                <div class="card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-database" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Storage', 'wp-care-connector' ); ?>
                    </h2>
                    <table class="widefat striped" style="border: none;">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e( 'Uploads Directory', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( size_format( $disk_usage ) ); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'Database Size', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( $db_size ); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'Media Items', 'wp-care-connector' ); ?></strong></td>
                                <td><?php echo esc_html( number_format_i18n( $data['content']['media'] ) ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Last Report -->
                <div class="card" style="padding: 20px;">
                    <h2 style="margin-top: 0;">
                        <span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Health Reporting', 'wp-care-connector' ); ?>
                    </h2>
                    <table class="widefat striped" style="border: none;">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e( 'Last Report', 'wp-care-connector' ); ?></strong></td>
                                <td>
                                    <?php if ( $last_report ) : ?>
                                        <?php echo esc_html( human_time_diff( $last_report ) ); ?> <?php esc_html_e( 'ago', 'wp-care-connector' ); ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Never', 'wp-care-connector' ); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'Schedule', 'wp-care-connector' ); ?></strong></td>
                                <td>
                                    <?php
                                    $next = wp_next_scheduled( 'wp_care_submit_health' );
                                    if ( $next ) {
                                        printf(
                                            esc_html__( 'Next in %s', 'wp-care-connector' ),
                                            esc_html( human_time_diff( time(), $next ) )
                                        );
                                    } else {
                                        esc_html_e( 'Not scheduled', 'wp-care-connector' );
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e( 'SSL', 'wp-care-connector' ); ?></strong></td>
                                <td>
                                    <?php if ( $data['environment']['ssl'] ) : ?>
                                        <span style="color: #00a32a;"><?php esc_html_e( 'Active', 'wp-care-connector' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #d63638;"><?php esc_html_e( 'Inactive', 'wp-care-connector' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- Plugins Table -->
            <h2 style="margin-top: 30px;"><?php esc_html_e( 'Plugins', 'wp-care-connector' ); ?></h2>
            <?php
            $plugins_needing_update = array_filter( $data['plugins'], function( $p ) {
                return $p['update_available'];
            });
            if ( count( $plugins_needing_update ) > 0 ) :
            ?>
            <div class="notice notice-warning inline" style="margin: 10px 0;">
                <p>
                    <?php
                    printf(
                        esc_html__( '%d plugin(s) have updates available.', 'wp-care-connector' ),
                        count( $plugins_needing_update )
                    );
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Plugin', 'wp-care-connector' ); ?></th>
                        <th><?php esc_html_e( 'Version', 'wp-care-connector' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wp-care-connector' ); ?></th>
                        <th><?php esc_html_e( 'Update', 'wp-care-connector' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $data['plugins'] as $plugin ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $plugin['name'] ); ?></strong>
                            <?php if ( ! empty( $plugin['must_use'] ) ) : ?>
                                <em>(<?php esc_html_e( 'Must-Use', 'wp-care-connector' ); ?>)</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $plugin['version'] ); ?></td>
                        <td>
                            <?php if ( $plugin['active'] ) : ?>
                                <span style="color: #00a32a;"><?php esc_html_e( 'Active', 'wp-care-connector' ); ?></span>
                            <?php else : ?>
                                <span style="color: #666;"><?php esc_html_e( 'Inactive', 'wp-care-connector' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $plugin['update_available'] ) : ?>
                                <span style="color: #d63638; font-weight: 600;">
                                    <?php echo esc_html( $plugin['update_available'] ); ?> <?php esc_html_e( 'available', 'wp-care-connector' ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #00a32a;"><?php esc_html_e( 'Up to date', 'wp-care-connector' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top: 15px;">
                <?php
                printf(
                    esc_html__( 'Data captured at: %s', 'wp-care-connector' ),
                    esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $data['captured_at'] ) )
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get the size of a directory in bytes.
     *
     * @param string $path Directory path.
     * @return int Size in bytes.
     */
    private static function get_directory_size( $path ) {
        $size = 0;

        if ( ! is_dir( $path ) ) {
            return $size;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Get the total database size as a human-readable string.
     *
     * @return string Database size.
     */
    private static function get_database_size() {
        global $wpdb;

        $result = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = %s",
                DB_NAME
            )
        );

        if ( $result && isset( $result[0]->size ) ) {
            return size_format( (int) $result[0]->size );
        }

        return __( 'Unknown', 'wp-care-connector' );
    }
}
