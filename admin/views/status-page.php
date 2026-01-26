<?php
/**
 * WP Care Status Page Template
 *
 * Displays plugin status, API key (masked), and connection information.
 * Presentation only - data gathering in WP_Care_Admin class.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 *
 * @var array $status_data Status information from WP_Care_Admin::render_status_page()
 *   - api_key_display: string (masked key)
 *   - has_api_key: bool
 *   - plugin_version: string
 *   - rest_url: string
 *   - site_info: array (from SiteMapper)
 *   - connection_status: array (connected, message)
 *   - pending_requests: int
 */

// Security check: prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap wp-care-wrap">
    <h1><?php esc_html_e( 'WP Care Status', 'wp-care-connector' ); ?></h1>

    <div class="wp-care-status-container">
        <!-- Connection Status -->
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'Connection Status', 'wp-care-connector' ); ?></h2>
            <div class="wp-care-status-indicator <?php echo $status_data['connection_status']['connected'] ? 'status-connected' : 'status-disconnected'; ?>">
                <span class="status-dot"></span>
                <span class="status-text">
                    <?php echo esc_html( $status_data['connection_status']['message'] ); ?>
                </span>
            </div>
        </div>

        <!-- API Configuration -->
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'API Configuration', 'wp-care-connector' ); ?></h2>
            <table class="wp-care-status-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'API Key', 'wp-care-connector' ); ?></th>
                        <td>
                            <code class="wp-care-api-key"><?php echo esc_html( $status_data['api_key_display'] ); ?></code>
                            <?php if ( $status_data['has_api_key'] ) : ?>
                                <span class="dashicons dashicons-yes-alt status-ok" title="<?php esc_attr_e( 'Configured', 'wp-care-connector' ); ?>"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-warning status-warning" title="<?php esc_attr_e( 'Not configured', 'wp-care-connector' ); ?>"></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'REST API Endpoint', 'wp-care-connector' ); ?></th>
                        <td><code><?php echo esc_html( $status_data['rest_url'] ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Plugin Version', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( $status_data['plugin_version'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Pending Requests', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( $status_data['pending_requests'] ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Site Information -->
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'Site Information', 'wp-care-connector' ); ?></h2>
            <table class="wp-care-status-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'WordPress Version', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( $status_data['site_info']['wp_version'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'PHP Version', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( $status_data['site_info']['php_version'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Site URL', 'wp-care-connector' ); ?></th>
                        <td><a href="<?php echo esc_url( $status_data['site_info']['site_url'] ); ?>" target="_blank"><?php echo esc_html( $status_data['site_info']['site_url'] ); ?></a></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Theme', 'wp-care-connector' ); ?></th>
                        <td>
                            <?php echo esc_html( $status_data['site_info']['theme']['name'] ); ?>
                            <span class="wp-care-version">(v<?php echo esc_html( $status_data['site_info']['theme']['version'] ); ?>)</span>
                            <?php if ( $status_data['site_info']['theme']['is_block_theme'] ) : ?>
                                <span class="wp-care-badge"><?php esc_html_e( 'Block Theme', 'wp-care-connector' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $status_data['site_info']['theme']['parent'] ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Parent Theme', 'wp-care-connector' ); ?></th>
                        <td>
                            <?php echo esc_html( $status_data['site_info']['theme']['parent']['name'] ); ?>
                            <span class="wp-care-version">(v<?php echo esc_html( $status_data['site_info']['theme']['parent']['version'] ); ?>)</span>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Environment -->
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'Environment', 'wp-care-connector' ); ?></h2>
            <table class="wp-care-status-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Memory Limit', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( $status_data['site_info']['environment']['memory_limit'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Max Upload Size', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( $status_data['site_info']['environment']['max_upload_size'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'SSL', 'wp-care-connector' ); ?></th>
                        <td>
                            <?php if ( $status_data['site_info']['environment']['ssl'] ) : ?>
                                <span class="dashicons dashicons-yes status-ok"></span> <?php esc_html_e( 'Enabled', 'wp-care-connector' ); ?>
                            <?php else : ?>
                                <span class="dashicons dashicons-no status-warning"></span> <?php esc_html_e( 'Disabled', 'wp-care-connector' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Debug Mode', 'wp-care-connector' ); ?></th>
                        <td>
                            <?php if ( $status_data['site_info']['environment']['debug_mode'] ) : ?>
                                <span class="dashicons dashicons-warning status-warning"></span> <?php esc_html_e( 'Enabled', 'wp-care-connector' ); ?>
                            <?php else : ?>
                                <span class="dashicons dashicons-yes status-ok"></span> <?php esc_html_e( 'Disabled', 'wp-care-connector' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Multisite', 'wp-care-connector' ); ?></th>
                        <td><?php echo $status_data['site_info']['environment']['multisite'] ? esc_html__( 'Yes', 'wp-care-connector' ) : esc_html__( 'No', 'wp-care-connector' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'WP-CLI Available', 'wp-care-connector' ); ?></th>
                        <td><?php echo $status_data['site_info']['environment']['wp_cli_available'] ? esc_html__( 'Yes', 'wp-care-connector' ) : esc_html__( 'No', 'wp-care-connector' ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Page Builders -->
        <?php if ( ! empty( $status_data['site_info']['builders'] ) ) : ?>
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'Detected Page Builders', 'wp-care-connector' ); ?></h2>
            <table class="wp-care-status-table">
                <tbody>
                    <?php foreach ( $status_data['site_info']['builders'] as $builder_slug => $builder ) : ?>
                    <tr>
                        <th><?php echo esc_html( $builder['name'] ); ?></th>
                        <td>
                            <span class="wp-care-version">v<?php echo esc_html( $builder['version'] ); ?></span>
                            <?php if ( isset( $builder['pro'] ) && $builder['pro'] ) : ?>
                                <span class="wp-care-badge wp-care-badge-pro"><?php esc_html_e( 'Pro', 'wp-care-connector' ); ?> v<?php echo esc_html( $builder['pro'] ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Content Summary -->
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'Content Summary', 'wp-care-connector' ); ?></h2>
            <table class="wp-care-status-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Posts', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $status_data['site_info']['content']['posts'] ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Pages', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $status_data['site_info']['content']['pages'] ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Media Items', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $status_data['site_info']['content']['media'] ) ); ?></td>
                    </tr>
                    <?php if ( isset( $status_data['site_info']['content']['products'] ) ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Products', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $status_data['site_info']['content']['products'] ) ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e( 'Users', 'wp-care-connector' ); ?></th>
                        <td><?php echo esc_html( number_format_i18n( $status_data['site_info']['content']['users'] ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Active Plugins Summary -->
        <div class="wp-care-status-card">
            <h2><?php esc_html_e( 'Active Plugins', 'wp-care-connector' ); ?></h2>
            <?php
            $active_plugins = array_filter( $status_data['site_info']['plugins'], function( $p ) {
                return $p['active'];
            } );
            ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of active plugins */
                    esc_html__( '%d active plugins', 'wp-care-connector' ),
                    count( $active_plugins )
                );
                ?>
            </p>
            <ul class="wp-care-plugin-list">
                <?php foreach ( $active_plugins as $plugin ) : ?>
                <li>
                    <?php echo esc_html( $plugin['name'] ); ?>
                    <span class="wp-care-version">(v<?php echo esc_html( $plugin['version'] ); ?>)</span>
                    <?php if ( ! empty( $plugin['must_use'] ) ) : ?>
                        <span class="wp-care-badge"><?php esc_html_e( 'MU', 'wp-care-connector' ); ?></span>
                    <?php endif; ?>
                    <?php if ( $plugin['update_available'] ) : ?>
                        <span class="wp-care-badge wp-care-badge-update"><?php esc_html_e( 'Update available', 'wp-care-connector' ); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Last Update -->
        <div class="wp-care-status-footer">
            <p class="description">
                <?php
                printf(
                    /* translators: %s: timestamp */
                    esc_html__( 'Site information captured at: %s', 'wp-care-connector' ),
                    esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status_data['site_info']['captured_at'] ) )
                );
                ?>
            </p>
        </div>
    </div>
</div>
