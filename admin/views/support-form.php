<?php
/**
 * WP Care Support Form Template
 *
 * Displays the support request form in wp-admin.
 * Presentation only - business logic in WP_Care_Admin class.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 *
 * @var array $form_data Form configuration data from WP_Care_Admin::render_support_form()
 *   - nonce_action: string
 *   - nonce_field: string HTML
 *   - form_action: string URL
 *   - user_email: string
 *   - user_name: string
 *   - site_context: array
 */

// Security check: prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap wp-care-wrap">
    <h1><?php esc_html_e( 'Get Help', 'wp-care-connector' ); ?></h1>

    <div class="wp-care-support-form-container">
        <div class="wp-care-form-intro">
            <p><?php esc_html_e( 'Need assistance with your website? Submit a support request below and our team will help you.', 'wp-care-connector' ); ?></p>
            <p class="description"><?php esc_html_e( 'Your site information will be automatically included to help us diagnose any issues quickly.', 'wp-care-connector' ); ?></p>
        </div>

        <form method="post" action="<?php echo esc_url( $form_data['form_action'] ); ?>" class="wp-care-support-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="wp_care_support_request" />
            <?php echo $form_data['nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="support_category"><?php esc_html_e( 'Category', 'wp-care-connector' ); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <select id="support_category" name="support_category" class="regular-text" required>
                                <option value=""><?php esc_html_e( '-- Select a category --', 'wp-care-connector' ); ?></option>
                                <option value="speed"><?php esc_html_e( 'Speed / Performance', 'wp-care-connector' ); ?></option>
                                <option value="security"><?php esc_html_e( 'Security', 'wp-care-connector' ); ?></option>
                                <option value="broken-feature"><?php esc_html_e( 'Broken Feature', 'wp-care-connector' ); ?></option>
                                <option value="content"><?php esc_html_e( 'Content / Design', 'wp-care-connector' ); ?></option>
                                <option value="other"><?php esc_html_e( 'Other', 'wp-care-connector' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="support_subject"><?php esc_html_e( 'Subject', 'wp-care-connector' ); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="support_subject" name="support_subject" class="regular-text" required placeholder="<?php esc_attr_e( 'Brief description of your issue', 'wp-care-connector' ); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="support_priority"><?php esc_html_e( 'Urgency', 'wp-care-connector' ); ?></label>
                        </th>
                        <td>
                            <select id="support_priority" name="support_priority" class="regular-text">
                                <option value="low"><?php esc_html_e( 'Low - General question', 'wp-care-connector' ); ?></option>
                                <option value="normal" selected><?php esc_html_e( 'Normal - Issue affecting functionality', 'wp-care-connector' ); ?></option>
                                <option value="high"><?php esc_html_e( 'High - Site not working properly', 'wp-care-connector' ); ?></option>
                                <option value="urgent"><?php esc_html_e( 'Urgent - Site is down', 'wp-care-connector' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="support_message"><?php esc_html_e( 'Description', 'wp-care-connector' ); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <textarea id="support_message" name="support_message" rows="8" class="large-text" required minlength="20" placeholder="<?php esc_attr_e( 'Please describe your issue in detail. Include any error messages you see and steps to reproduce the problem. (minimum 20 characters)', 'wp-care-connector' ); ?>"></textarea>
                            <p class="description"><?php esc_html_e( 'Minimum 20 characters. The more detail you provide, the faster we can help.', 'wp-care-connector' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="support_screenshot"><?php esc_html_e( 'Screenshot', 'wp-care-connector' ); ?></label>
                        </th>
                        <td>
                            <input type="file" id="support_screenshot" name="support_screenshot" accept="image/jpeg,image/png,image/gif,image/webp" />
                            <p class="description"><?php esc_html_e( 'Optional. Attach a screenshot of the issue (JPG, PNG, GIF, WebP). Max 5MB.', 'wp-care-connector' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Contact Email', 'wp-care-connector' ); ?>
                        </th>
                        <td>
                            <p class="description">
                                <strong><?php echo esc_html( $form_data['user_email'] ); ?></strong>
                                <br /><?php esc_html_e( 'We will respond to your admin email address.', 'wp-care-connector' ); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="wp-care-site-context">
                <h3><?php esc_html_e( 'Site Information (Auto-attached)', 'wp-care-connector' ); ?></h3>
                <p class="description"><?php esc_html_e( 'The following information will be included with your request to help us assist you:', 'wp-care-connector' ); ?></p>

                <div class="wp-care-context-summary">
                    <ul>
                        <li>
                            <strong><?php esc_html_e( 'WordPress:', 'wp-care-connector' ); ?></strong>
                            <?php echo esc_html( $form_data['site_context']['wp_version'] ); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'PHP:', 'wp-care-connector' ); ?></strong>
                            <?php echo esc_html( $form_data['site_context']['php_version'] ); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Theme:', 'wp-care-connector' ); ?></strong>
                            <?php echo esc_html( $form_data['site_context']['theme']['name'] ); ?>
                            (<?php echo esc_html( $form_data['site_context']['theme']['version'] ); ?>)
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Active Plugins:', 'wp-care-connector' ); ?></strong>
                            <?php
                            $active_count = count( array_filter( $form_data['site_context']['plugins'], function( $p ) {
                                return $p['active'];
                            } ) );
                            echo esc_html( $active_count );
                            ?>
                        </li>
                        <?php if ( ! empty( $form_data['site_context']['builders'] ) ) : ?>
                        <li>
                            <strong><?php esc_html_e( 'Page Builder:', 'wp-care-connector' ); ?></strong>
                            <?php
                            $builder_names = array_map( function( $b ) {
                                return $b['name'];
                            }, $form_data['site_context']['builders'] );
                            echo esc_html( implode( ', ', $builder_names ) );
                            ?>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <?php esc_html_e( 'Submit Support Request', 'wp-care-connector' ); ?>
                </button>
            </p>
        </form>
    </div>
</div>
