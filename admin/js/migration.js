/**
 * WP Care Migration AJAX Controller
 *
 * Handles chunked export process from the admin UI.
 *
 * @package WP_Care_Connector
 * @since 1.2.0
 */
(function($) {
    'use strict';

    var WPCareMigration = {
        migrationId: null,
        isRunning: false,

        /**
         * Collect export options from checkboxes.
         */
        getOptions: function() {
            var options = {};
            var optionKeys = [
                'include_database', 'include_themes', 'include_plugins',
                'include_uploads', 'include_mu_plugins', 'exclude_cache',
                'exclude_inactive_themes', 'exclude_inactive_plugins',
                'exclude_spam_comments', 'exclude_post_revisions'
            ];

            optionKeys.forEach(function(key) {
                var $checkbox = $('#wp-care-opt-' + key);
                if ($checkbox.length) {
                    options[key] = $checkbox.is(':checked');
                }
            });

            return options;
        },

        /**
         * Start a new migration export.
         */
        startExport: function() {
            if (this.isRunning) {
                return;
            }

            this.isRunning = true;
            this.updateUI('running');

            var self = this;
            var options = this.getOptions();

            $.ajax({
                url: wpCareMigration.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_care_migration_init',
                    _wpnonce: wpCareMigration.nonce,
                    options: options
                },
                success: function(response) {
                    if (response.success && response.data && response.data.migration_id) {
                        self.migrationId = response.data.migration_id;
                        self.processChunk();
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : wpCareMigration.strings.error;
                        self.handleError(msg);
                    }
                },
                error: function(xhr) {
                    self.handleError(wpCareMigration.strings.error + ' (HTTP ' + xhr.status + ')');
                }
            });
        },

        /**
         * Process next chunk via AJAX.
         */
        processChunk: function() {
            if (!this.isRunning || !this.migrationId) {
                return;
            }

            var self = this;

            $.ajax({
                url: wpCareMigration.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_care_migration_chunk',
                    _wpnonce: wpCareMigration.nonce,
                    migration_id: self.migrationId
                },
                success: function(response) {
                    if (!self.isRunning) {
                        return;
                    }

                    if (!response.success) {
                        var msg = (response.data && response.data.message) ? response.data.message : wpCareMigration.strings.error;
                        self.handleError(msg);
                        return;
                    }

                    var state = response.data;

                    if (state.error) {
                        self.handleError(state.error);
                        return;
                    }

                    self.updateProgress(state);

                    if (state.completed) {
                        self.showDownloadLink(state);
                    } else {
                        // Continue processing
                        setTimeout(function() {
                            self.processChunk();
                        }, 100);
                    }
                },
                error: function(xhr) {
                    self.handleError(wpCareMigration.strings.error + ' (HTTP ' + xhr.status + ')');
                }
            });
        },

        /**
         * Cancel running export.
         */
        cancelExport: function() {
            if (!confirm(wpCareMigration.strings.confirm_cancel)) {
                return;
            }

            this.isRunning = false;

            if (this.migrationId) {
                $.ajax({
                    url: wpCareMigration.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_care_migration_cancel',
                        _wpnonce: wpCareMigration.nonce,
                        migration_id: this.migrationId
                    }
                });
            }

            this.migrationId = null;
            this.updateUI('idle');
        },

        /**
         * Update progress display.
         */
        updateProgress: function(state) {
            var progress = state.progress || 0;
            var phase = state.phase || '';

            $('.wp-care-progress-fill').css('width', progress + '%');

            var phaseLabels = {
                'config': wpCareMigration.strings.initializing,
                'database': wpCareMigration.strings.exporting_db,
                'enumerate': wpCareMigration.strings.scanning,
                'archive': wpCareMigration.strings.archiving,
                'finalize': wpCareMigration.strings.finalizing,
                'complete': wpCareMigration.strings.complete
            };

            var label = phaseLabels[phase] || phase;
            $('.wp-care-progress-status').text(label + ' (' + progress + '%)');

            var detail = '';
            if (phase === 'archive' && state.archived_files > 0) {
                detail = state.archived_files + ' / ' + state.total_files_count + ' files';
            } else if (phase === 'database' && state.total_tables > 0) {
                detail = state.table_index + ' / ' + state.total_tables + ' tables';
            }
            $('.wp-care-progress-detail').text(detail);
        },

        /**
         * Show download link after completion.
         */
        showDownloadLink: function(state) {
            this.isRunning = false;

            var downloadUrl = wpCareMigration.ajaxUrl +
                '?action=wp_care_migration_download' +
                '&id=' + encodeURIComponent(this.migrationId) +
                '&_wpnonce=' + encodeURIComponent(wpCareMigration.nonce);

            $('#wp-care-migration-download-link').attr('href', downloadUrl);
            $('#wp-care-migration-filesize').text(
                state.archive_size_human ? 'File size: ' + state.archive_size_human : ''
            );

            $('#wp-care-migration-progress').hide();
            $('#wp-care-migration-download').show();
            $('#wp-care-migration-cancel').hide();
            $('#wp-care-migration-start').prop('disabled', false).show();
        },

        /**
         * Handle errors.
         */
        handleError: function(message) {
            this.isRunning = false;
            this.migrationId = null;

            $('#wp-care-migration-error-message').text(message);
            $('#wp-care-migration-error').show();
            $('#wp-care-migration-progress').hide();

            this.updateUI('idle');
        },

        /**
         * Update UI state.
         */
        updateUI: function(state) {
            if (state === 'running') {
                $('#wp-care-migration-start').prop('disabled', true);
                $('#wp-care-migration-cancel').show();
                $('#wp-care-migration-progress').show();
                $('#wp-care-migration-download').hide();
                $('#wp-care-migration-error').hide();
                $('.wp-care-progress-fill').css('width', '0%');
                $('.wp-care-progress-status').text(wpCareMigration.strings.initializing);
                $('.wp-care-progress-detail').text('');
                // Disable option checkboxes during export
                $('.wp-care-migration-checkboxes input').prop('disabled', true);
            } else {
                $('#wp-care-migration-start').prop('disabled', false);
                $('#wp-care-migration-cancel').hide();
                // Re-enable option checkboxes
                $('.wp-care-migration-checkboxes input').prop('disabled', false);
            }
        }
    };

    $(document).ready(function() {
        $('#wp-care-migration-start').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.startExport();
        });

        $('#wp-care-migration-cancel').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.cancelExport();
        });
    });
})(jQuery);
