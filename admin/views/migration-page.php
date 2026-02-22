<?php
/**
 * Site Migration admin page template.
 *
 * @package WP_Care_Connector
 * @since 1.2.0
 *
 * @var array $migrations List of existing migrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap wp-care-wrap">
    <h1><?php esc_html_e( 'Site Migration', 'wp-care-connector' ); ?></h1>

    <!-- Action Cards -->
    <div id="wp-care-action-cards" class="wp-care-action-cards">
        <div class="wp-care-action-card" id="wp-care-btn-create">
            <div class="wp-care-action-card-header">
                <span class="dashicons dashicons-migrate"></span>
                <?php esc_html_e( 'Create Migration', 'wp-care-connector' ); ?>
            </div>
            <div class="wp-care-action-card-body">
                <p><?php esc_html_e( 'Export your site\'s database, themes, plugins and uploads.', 'wp-care-connector' ); ?></p>
            </div>
        </div>
        <div class="wp-care-action-card" id="wp-care-btn-upload">
            <div class="wp-care-action-card-header">
                <span class="dashicons dashicons-upload"></span>
                <?php esc_html_e( 'Upload Migration', 'wp-care-connector' ); ?>
            </div>
            <div class="wp-care-action-card-body">
                <p><?php esc_html_e( 'Upload a .zip migration file from another site.', 'wp-care-connector' ); ?></p>
            </div>
        </div>
    </div>

    <!-- Create Migration Panel (hidden by default) -->
    <div id="wp-care-panel-create" class="card wp-care-panel" style="display: none;">
        <h2>
            <?php esc_html_e( 'Create Migration', 'wp-care-connector' ); ?>
            <button type="button" class="wp-care-panel-close">&times;</button>
        </h2>

        <div class="wp-care-migration-checkboxes">
            <div>
                <h3 style="margin-bottom: 8px;"><?php esc_html_e( 'Include in migration', 'wp-care-connector' ); ?></h3>
                <label><input type="checkbox" id="wp-care-opt-include_database" checked> <?php esc_html_e( 'Database', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_themes" checked> <?php esc_html_e( 'Themes', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_plugins" checked> <?php esc_html_e( 'Plugins', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_uploads" checked> <?php esc_html_e( 'Media / Uploads', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-include_mu_plugins"> <?php esc_html_e( 'Must-Use Plugins', 'wp-care-connector' ); ?></label>
            </div>
            <div>
                <h3 style="margin-top: 16px; margin-bottom: 8px;"><?php esc_html_e( 'Exclusions', 'wp-care-connector' ); ?></h3>
                <label><input type="checkbox" id="wp-care-opt-exclude_cache" checked> <?php esc_html_e( 'Exclude cache files', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_inactive_themes"> <?php esc_html_e( 'Exclude inactive themes', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_inactive_plugins"> <?php esc_html_e( 'Exclude inactive plugins', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_spam_comments" checked> <?php esc_html_e( 'Exclude spam comments', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-opt-exclude_post_revisions"> <?php esc_html_e( 'Exclude post revisions', 'wp-care-connector' ); ?></label>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <button id="wp-care-migration-start" class="button button-primary">
                <span class="dashicons dashicons-migrate" style="vertical-align: middle; margin-right: 4px;"></span>
                <?php esc_html_e( 'Create Migration', 'wp-care-connector' ); ?>
            </button>
        </div>
    </div>

    <!-- Upload Migration Panel (hidden by default) -->
    <div id="wp-care-panel-upload" class="card wp-care-panel" style="display: none;">
        <h2>
            <?php esc_html_e( 'Upload Migration', 'wp-care-connector' ); ?>
            <button type="button" class="wp-care-panel-close">&times;</button>
        </h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="wp-care-upload-form">
            <?php wp_nonce_field( 'wp_care_upload_migration', '_wpnonce' ); ?>
            <input type="hidden" name="action" value="wp_care_upload_migration">
            <input type="file" id="wp-care-file-input" name="migration_file" accept=".zip" required style="position: absolute; left: -9999px;">

            <!-- Drop Zone -->
            <div class="wp-care-upload-zone" id="wp-care-upload-zone">
                <span class="dashicons dashicons-cloud-upload"></span>
                <p class="wp-care-upload-title"><?php esc_html_e( 'Drag & drop your migration file here', 'wp-care-connector' ); ?></p>
                <p class="wp-care-upload-or"><?php esc_html_e( 'or', 'wp-care-connector' ); ?></p>
                <button type="button" class="button" id="wp-care-browse-btn"><?php esc_html_e( 'Browse Files', 'wp-care-connector' ); ?></button>
                <p class="wp-care-upload-hint">
                    <?php
                    printf(
                        esc_html__( '.zip files only — Maximum size: %s', 'wp-care-connector' ),
                        esc_html( size_format( wp_max_upload_size() ) )
                    );
                    ?>
                </p>
            </div>

            <!-- Selected File -->
            <div class="wp-care-upload-file" id="wp-care-upload-file" style="display: none;">
                <span class="dashicons dashicons-media-archive"></span>
                <div class="wp-care-upload-file-info">
                    <span class="wp-care-upload-file-name" id="wp-care-upload-file-name"></span>
                    <span class="wp-care-upload-file-size" id="wp-care-upload-file-size"></span>
                </div>
                <button type="button" class="wp-care-upload-file-remove" id="wp-care-upload-file-remove">&times;</button>
            </div>

            <div id="wp-care-upload-submit-wrap" style="display: none; margin-top: 16px;">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-upload" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e( 'Upload Migration', 'wp-care-connector' ); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Progress Section (shown during export/restore, hides everything else) -->
    <div id="wp-care-migration-progress" class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; display: none;">
        <h2 id="wp-care-progress-title" style="margin-top: 0;"><?php esc_html_e( 'Migration Progress', 'wp-care-connector' ); ?></h2>
        <div class="wp-care-progress-bar">
            <div class="wp-care-progress-fill" style="width: 0%;"></div>
        </div>
        <p class="wp-care-progress-status" style="margin-bottom: 4px;"><?php esc_html_e( 'Initializing...', 'wp-care-connector' ); ?></p>
        <p class="wp-care-progress-detail" style="margin-top: 0;"></p>
        <button id="wp-care-migration-cancel" class="button" style="margin-top: 10px;">
            <?php esc_html_e( 'Cancel', 'wp-care-connector' ); ?>
        </button>
    </div>

    <!-- Download Section (shown after export complete) -->
    <div id="wp-care-migration-download" class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; display: none;">
        <h2 style="margin-top: 0; color: #00a32a;">
            <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
            <?php esc_html_e( 'Migration Complete', 'wp-care-connector' ); ?>
        </h2>
        <p id="wp-care-migration-filesize"></p>
        <a href="#" id="wp-care-migration-download-link" class="button button-primary">
            <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span>
            <?php esc_html_e( 'Download Migration File', 'wp-care-connector' ); ?>
        </a>
    </div>

    <!-- Restore Complete Section -->
    <div id="wp-care-restore-complete" class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; display: none;">
        <h2 style="margin-top: 0; color: #00a32a;">
            <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
            <?php esc_html_e( 'Restore Complete', 'wp-care-connector' ); ?>
        </h2>
        <p><?php esc_html_e( 'Your site has been restored from the migration.', 'wp-care-connector' ); ?></p>
        <p id="wp-care-restore-checkpoint" style="color: #666;"></p>
    </div>

    <!-- Error Section -->
    <div id="wp-care-migration-error" class="notice notice-error" style="max-width: 800px; margin-top: 20px; display: none;">
        <p id="wp-care-migration-error-message"></p>
    </div>

    <!-- Available Migrations Table -->
    <div id="wp-care-migrations-table">
        <h2><?php esc_html_e( 'Available Migrations', 'wp-care-connector' ); ?></h2>
        <?php if ( ! empty( $migrations ) ) : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'wp-care-connector' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'wp-care-connector' ); ?></th>
                    <th><?php esc_html_e( 'Size', 'wp-care-connector' ); ?></th>
                    <th style=" text-align: center;"><?php esc_html_e( 'Actions', 'wp-care-connector' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $migrations as $m ) : ?>
                <tr>
                    <td>
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $m['created_at'] ) ) ); ?>
                        <br><small style="color: #666;"><?php echo esc_html( human_time_diff( strtotime( $m['created_at'] ) ) ); ?> <?php esc_html_e( 'ago', 'wp-care-connector' ); ?></small>
                    </td>
                    <td>
                        <?php if ( ! empty( $m['source'] ) && $m['source'] === 'upload' ) : ?>
                            <span class="dashicons dashicons-upload" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                            <?php esc_html_e( 'Uploaded', 'wp-care-connector' ); ?>
                            <?php if ( ! empty( $m['site_url'] ) && $m['site_url'] !== 'unknown' ) : ?>
                                <br><small style="color: #666;"><?php echo esc_html( $m['site_url'] ); ?></small>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="dashicons dashicons-admin-site-alt3" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                            <?php esc_html_e( 'This site', 'wp-care-connector' ); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $m['archive_size_human'] ); ?></td>
                    <td style="text-align: center;">
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=wp_care_migration_download&id=' . urlencode( $m['id'] ) ), 'wp_care_migration_download' ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'Download', 'wp-care-connector' ); ?>
                        </a>
                        <button type="button" class="button button-small wp-care-restore-btn" data-id="<?php echo esc_attr( $m['id'] ); ?>">
                            <span class="dashicons dashicons-backup" style="vertical-align: middle; font-size: 14px; width: 14px; height: 14px;"></span>
                            <?php esc_html_e( 'Restore', 'wp-care-connector' ); ?>
                        </button>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
                            <?php wp_nonce_field( 'wp_care_delete_migration', '_wpnonce' ); ?>
                            <input type="hidden" name="action" value="wp_care_delete_migration">
                            <input type="hidden" name="migration_id" value="<?php echo esc_attr( $m['id'] ); ?>">
                            <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this migration?', 'wp-care-connector' ); ?>');">
                                <?php esc_html_e( 'Delete', 'wp-care-connector' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p class="description"><?php esc_html_e( 'No migrations yet. Use the cards above to get started.', 'wp-care-connector' ); ?></p>
        <?php endif; ?>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="wp-care-restore-modal" class="wp-care-modal-backdrop" style="display: none;">
        <div class="wp-care-modal-content">
            <h2 style="margin-top: 0; color: #d63638;">
                <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                <?php esc_html_e( 'Confirm Restore', 'wp-care-connector' ); ?>
            </h2>
            <p><strong><?php esc_html_e( 'This will overwrite your current site data.', 'wp-care-connector' ); ?></strong></p>
            <p><?php esc_html_e( 'A database checkpoint will be created before restoring so you can roll back if needed.', 'wp-care-connector' ); ?></p>

            <div class="wp-care-migration-checkboxes" style="background: #f6f7f7; padding: 12px; border-radius: 4px; margin: 15px 0;">
                <label><input type="checkbox" id="wp-care-restore-database" checked> <?php esc_html_e( 'Restore database', 'wp-care-connector' ); ?></label>
                <label><input type="checkbox" id="wp-care-restore-files" checked> <?php esc_html_e( 'Restore files (themes, plugins, uploads)', 'wp-care-connector' ); ?></label>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button id="wp-care-restore-confirm" class="button button-primary" style="background: #d63638; border-color: #d63638;">
                    <?php esc_html_e( 'Restore Site', 'wp-care-connector' ); ?>
                </button>
                <button id="wp-care-restore-cancel-modal" class="button">
                    <?php esc_html_e( 'Cancel', 'wp-care-connector' ); ?>
                </button>
            </div>
        </div>
    </div>
</div>
