/**
 * WP Care Migration AJAX Controller
 *
 * Handles chunked export and restore processes from the admin UI.
 *
 * @package WP_Care_Connector
 * @since 1.2.0
 */
(function($) {
    'use strict';

    var WPCareMigration = {
        migrationId: null,
        isRunning: false,
        mode: null, // 'export' or 'restore'

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

        // =================================================================
        // Panel Toggle
        // =================================================================

        /**
         * Toggle a panel open/closed. Closes other panels first.
         */
        togglePanel: function(panelId) {
            var $panel = $('#' + panelId);
            var isVisible = $panel.is(':visible');

            // Close all panels
            $('.wp-care-panel').hide();

            if (!isVisible) {
                // Hide table, show panel
                $('#wp-care-migrations-table').hide();
                $panel.show();
            } else {
                // Panel was open, now closed — show table
                $('#wp-care-migrations-table').show();
            }
        },

        /**
         * Close all panels and show table.
         */
        closePanels: function() {
            $('.wp-care-panel').hide();
            $('#wp-care-migrations-table').show();
        },

        // =================================================================
        // Export
        // =================================================================

        /**
         * Start a new migration export.
         */
        startExport: function() {
            if (this.isRunning) {
                return;
            }

            this.isRunning = true;
            this.mode = 'export';
            this.updateUI('running');
            $('#wp-care-progress-title').text(wpCareMigration.strings.export_title || 'Migration Progress');

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
         * Process next export chunk via AJAX.
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
            this.mode = null;
            this.updateUI('idle');
        },

        // =================================================================
        // Restore
        // =================================================================

        /**
         * Show the restore confirmation modal.
         */
        showRestoreModal: function(migrationId) {
            this.migrationId = migrationId;
            $('#wp-care-restore-modal').show();
        },

        /**
         * Start the restore process after confirmation.
         */
        startRestore: function() {
            if (this.isRunning || !this.migrationId) {
                return;
            }

            $('#wp-care-restore-modal').hide();

            this.isRunning = true;
            this.mode = 'restore';
            this.updateUI('running');
            $('#wp-care-progress-title').text(wpCareMigration.strings.restore_title || 'Restore Progress');

            var self = this;
            var options = {
                restore_database: $('#wp-care-restore-database').is(':checked'),
                restore_files: $('#wp-care-restore-files').is(':checked')
            };

            $.ajax({
                url: wpCareMigration.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_care_restore_init',
                    _wpnonce: wpCareMigration.nonce,
                    migration_id: self.migrationId,
                    options: options
                },
                success: function(response) {
                    if (response.success && response.data && response.data.migration_id) {
                        self.processRestoreChunk();
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
         * Process next restore chunk via AJAX.
         */
        processRestoreChunk: function() {
            if (!this.isRunning || !this.migrationId) {
                return;
            }

            var self = this;

            $.ajax({
                url: wpCareMigration.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_care_restore_chunk',
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

                    self.updateRestoreProgress(state);

                    if (state.completed) {
                        self.showRestoreComplete(state);
                    } else {
                        setTimeout(function() {
                            self.processRestoreChunk();
                        }, 100);
                    }
                },
                error: function(xhr) {
                    self.handleError(wpCareMigration.strings.error + ' (HTTP ' + xhr.status + ')');
                }
            });
        },

        // =================================================================
        // UI Helpers
        // =================================================================

        /**
         * Update export progress display.
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
         * Update restore progress display.
         */
        updateRestoreProgress: function(state) {
            var progress = state.progress || 0;
            var phase = state.phase || '';

            $('.wp-care-progress-fill').css('width', progress + '%');

            var phaseLabels = {
                'checkpoint': wpCareMigration.strings.restore_checkpoint,
                'database': wpCareMigration.strings.restore_db,
                'files': wpCareMigration.strings.restore_files,
                'complete': wpCareMigration.strings.restore_complete
            };

            var label = phaseLabels[phase] || phase;
            $('.wp-care-progress-status').text(label + ' (' + progress + '%)');

            var detail = '';
            if (phase === 'files' && state.extracted_files > 0) {
                detail = state.extracted_files + (state.total_entries ? ' / ' + state.total_entries : '') + ' files';
            }
            $('.wp-care-progress-detail').text(detail);
        },

        /**
         * Show download link after export completion.
         */
        showDownloadLink: function(state) {
            this.isRunning = false;
            this.mode = null;

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
            $('#wp-care-migrations-table').show();
        },

        /**
         * Show restore complete message.
         */
        showRestoreComplete: function(state) {
            this.isRunning = false;
            this.mode = null;

            var checkpointMsg = '';
            if (state.checkpoint_id) {
                checkpointMsg = wpCareMigration.strings.restore_checkpoint_note + ' ' + state.checkpoint_id;
            }
            $('#wp-care-restore-checkpoint').text(checkpointMsg);

            $('#wp-care-migration-progress').hide();
            $('#wp-care-restore-complete').show();
            $('#wp-care-migrations-table').show();

            $('.wp-care-migration-checkboxes input').prop('disabled', false);
        },

        /**
         * Handle errors.
         */
        handleError: function(message) {
            this.isRunning = false;
            this.migrationId = null;
            this.mode = null;

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
                // Hide everything except progress
                $('.wp-care-panel').hide();
                $('#wp-care-migrations-table').hide();
                $('#wp-care-migration-download').hide();
                $('#wp-care-restore-complete').hide();
                $('#wp-care-migration-error').hide();

                // Show progress
                $('#wp-care-migration-progress').show();
                $('.wp-care-progress-fill').css('width', '0%');
                $('.wp-care-progress-status').text(wpCareMigration.strings.initializing);
                $('.wp-care-progress-detail').text('');

                // Disable inputs
                $('.wp-care-migration-checkboxes input').prop('disabled', true);
                $('.wp-care-restore-btn').prop('disabled', true);
            } else {
                // Show table (buttons are inside it)
                $('#wp-care-migrations-table').show();

                // Enable inputs
                $('.wp-care-migration-checkboxes input').prop('disabled', false);
                $('.wp-care-restore-btn').prop('disabled', false);
            }
        }
    };

    $(document).ready(function() {
        // Panel toggle buttons
        $('#wp-care-btn-create').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.togglePanel('wp-care-panel-create');
        });

        $('#wp-care-btn-upload').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.togglePanel('wp-care-panel-upload');
        });

        // Panel close buttons — close panel, show table
        $(document).on('click', '.wp-care-panel-close', function(e) {
            e.preventDefault();
            WPCareMigration.closePanels();
        });

        // Export
        $('#wp-care-migration-start').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.startExport();
        });

        $('#wp-care-migration-cancel').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.cancelExport();
        });

        // Restore - open modal
        $(document).on('click', '.wp-care-restore-btn', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            WPCareMigration.showRestoreModal(id);
        });

        // Restore - confirm
        $('#wp-care-restore-confirm').on('click', function(e) {
            e.preventDefault();
            WPCareMigration.startRestore();
        });

        // Restore - cancel modal
        $('#wp-care-restore-cancel-modal').on('click', function(e) {
            e.preventDefault();
            $('#wp-care-restore-modal').hide();
            WPCareMigration.migrationId = null;
        });
    });
})(jQuery);
