<?php
/**
 * Site Migration admin page template.
 *
 * @package WP_Care_Connector
 * @since 1.2.0
 *
 * @var array $migrations List of existing migration backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap wp-care-wrap">
    <h1><?php esc_html_e( 'Site Migration', 'wp-care-connector' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Create a full site backup (database + files) for migration to a new host or domain.', 'wp-care-connector' ); ?></p>

    <!-- Export Options -->
    <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Export Options', 'wp-care-connector' ); ?></h2>

        <div class="wp-care-migration-checkboxes">
            <div class="">
                <h3 style="margin-bottom: 8px;"><?php esc_html_e( 'Include in backup', 'wp-care-connector' ); ?></h3>
                <label><input type="checkbox" id="wp-care-opt-include_database" checked> <?php esc_html_e( 'Database', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_themes" checked> <?php esc_html_e( 'Themes', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_plugins" checked> <?php esc_html_e( 'Plugins', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_uploads" checked> <?php esc_html_e( 'Media / Uploads', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_mu_plugins"> <?php esc_html_e( 'Must-Use Plugins', 'wp-care-connector' ); ?></label>
            </div>
            <div class="">
                <h3 style="margin-top: 16px; margin-bottom: 8px;"><?php esc_html_e( 'Exclusions', 'wp-care-connector' ); ?></h3>
                <label><input type="checkbox" id="wp-care-opt-exclude_cache" checked> <?php esc_html_e( 'Exclude cache files', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_inactive_themes"> <?php esc_html_e( 'Exclude inactive themes', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_inactive_plugins"> <?php esc_html_e( 'Exclude inactive plugins', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_spam_comments" checked> <?php esc_html_e( 'Exclude spam comments', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_post_revisions"> <?php esc_html_e( 'Exclude post revisions', 'wp-care-connector' ); ?></label>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <button id="wp-care-migration-start" class="button button-primary button-hero">
                <span class="dashicons dashicons-migrate" style="vertical-align: middle; margin-right: 4px;"></span>
                <?php esc_html_e( 'Create Migration Backup', 'wp-care-connector' ); ?>
            </button>
            <button id="wp-care-migration-cancel" class="button" style="display: none; margin-left: 10px;">
                <?php esc_html_e( 'Cancel', 'wp-care-connector' ); ?>
            </button>
        </div>
    </div>

    <!-- Progress Section -->
    <div id="wp-care-migration-progress" class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; display: none;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Export Progress', 'wp-care-connector' ); ?></h2>
        <div class="wp-care-progress-bar">
            <div class="wp-care-progress-fill" style="width: 0%;"></div>
        </div>
        <p class="wp-care-progress-status" style="margin-bottom: 4px;"><?php esc_html_e( 'Initializing...', 'wp-care-connector' ); ?></p>
        <p class="wp-care-progress-detail" style="margin-top: 0;"></p>
    </div>

    <!-- Download Section -->
    <div id="wp-care-migration-download" class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; display: none;">
        <h2 style="margin-top: 0; color: #00a32a;">
            <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
            <?php esc_html_e( 'Migration Backup Complete!', 'wp-care-connector' ); ?>
        </h2>
        <p id="wp-care-migration-filesize"></p>
        <a href="#" id="wp-care-migration-download-link" class="button button-primary button-hero">
            <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span>
            <?php esc_html_e( 'Download Migration File', 'wp-care-connector' ); ?>
        </a>
    </div>

    <!-- Error Section -->
    <div id="wp-care-migration-error" class="notice notice-error" style="max-width: 800px; margin-top: 20px; display: none;">
        <p id="wp-care-migration-error-message"></p>
    </div>

    <!-- Existing Migrations -->
    <?php if ( ! empty( $migrations ) ) : ?>
    <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
        <h2 style="margin-top: 0;"><?php esc_html_e( 'Previous Migration Backups', 'wp-care-connector' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'wp-care-connector' ); ?></th>
                    <th><?php esc_html_e( 'Size', 'wp-care-connector' ); ?></th>
                    <th><?php esc_html_e( 'Files', 'wp-care-connector' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wp-care-connector' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $migrations as $m ) : ?>
                <tr>
                    <td>
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $m['created_at'] ) ) ); ?>
                        <br><small style="color: #666;"><?php echo esc_html( human_time_diff( strtotime( $m['created_at'] ) ) ); ?> <?php esc_html_e( 'ago', 'wp-care-connector' ); ?></small>
                    </td>
                    <td><?php echo esc_html( $m['archive_size_human'] ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( $m['total_files'] ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=wp_care_migration_download&id=' . urlencode( $m['id'] ) ), 'wp_care_migration_download' ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'Download', 'wp-care-connector' ); ?>
                        </a>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
                            <?php wp_nonce_field( 'wp_care_delete_migration', '_wpnonce' ); ?>
                            <input type="hidden" name="action" value="wp_care_delete_migration">
                            <input type="hidden" name="migration_id" value="<?php echo esc_attr( $m['id'] ); ?>">
                            <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this migration backup?', 'wp-care-connector' ); ?>');">
                                <?php esc_html_e( 'Delete', 'wp-care-connector' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
