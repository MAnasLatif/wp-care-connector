<?php
/**
 * WP Care API Endpoints
 *
 * REST API endpoints for health status and remote command execution.
 * These endpoints are how the central API communicates with the plugin.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

// Security check: prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Care_API_Endpoints
 *
 * Provides REST API endpoints:
 * - GET /wp-care/v1/health - Returns complete site map data (API key auth)
 * - POST /wp-care/v1/command - Executes registered commands (HMAC auth)
 * - GET /wp-care/v1/ping - Public connectivity check
 */
class WP_Care_API_Endpoints {

    /**
     * Central API base URL
     *
     * @var string
     */
    private static $api_base = 'http://localhost:3000/api/v1';

    /**
     * REST API namespace
     *
     * @var string
     */
    private $namespace = 'wp-care/v1';

    /**
     * Site mapper instance
     *
     * @var WP_Care_Site_Mapper
     */
    private $site_mapper;

    /**
     * Registered command handlers
     *
     * @var array
     */
    private $commands = [];

    /**
     * Options allowed for remote modification (SEC-04: allowlist approach)
     *
     * Only these options can be modified via the API. This is more secure
     * than a blocklist because new/unknown options are denied by default.
     *
     * @var array
     */
    private $allowed_write_options = [
        'wp_care_api_url',
        'wp_care_settings',
        'wp_care_debug_mode',
        // Cache-related (safe to clear/modify)
        'wp_care_cache_cleared',
    ];

    /**
     * Options allowed for remote reading (SEC-04: allowlist approach)
     *
     * Slightly more permissive than write, but still restricted.
     * Sensitive options like passwords, keys, emails are excluded.
     *
     * @var array
     */
    private $allowed_read_options = [
        // WP Care plugin options
        'wp_care_api_url',
        'wp_care_settings',
        'wp_care_debug_mode',
        'wp_care_site_id',
        // Site info (safe to read)
        'blogname',
        'blogdescription',
        'siteurl',
        'home',
        'timezone_string',
        'gmt_offset',
        'date_format',
        'time_format',
        'WPLANG',
        // WordPress version/state
        'db_version',
        'initial_db_version',
        // Cache/performance
        'wp_care_cache_cleared',
    ];

    /**
     * DEPRECATED: Use allowed_write_options instead
     * Kept for backwards compatibility with is_option_blocked()
     *
     * @var array
     * @deprecated
     */
    private $blocked_options = [
        'siteurl',
        'home',
        'admin_email',
        'wp_care_api_key_encrypted',
        'wp_care_api_key_hash',
        'active_plugins',
        'current_theme',
        'template',
        'stylesheet',
        'users_can_register',
        'default_role',
    ];

    /**
     * Singleton instance
     *
     * @var WP_Care_API_Endpoints|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return WP_Care_API_Endpoints
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     *
     * Initializes site mapper, registers routes, and sets up built-in commands.
     */
    public function __construct() {
        $this->site_mapper = new WP_Care_Site_Mapper();

        // Register REST routes on rest_api_init
        add_action('rest_api_init', [$this, 'register_routes']);

        // Register built-in commands
        $this->register_command('ping', [$this, 'cmd_ping']);
        $this->register_command('clear_cache', [$this, 'cmd_clear_cache']);
        $this->register_command('get_option', [$this, 'cmd_get_option']);
        $this->register_command('set_option', [$this, 'cmd_set_option']);

        // Register temp login commands
        $this->register_command('create_temp_login', [$this, 'cmd_create_temp_login']);
        $this->register_command('list_temp_users', [$this, 'cmd_list_temp_users']);
        $this->register_command('revoke_temp_login', [$this, 'cmd_revoke_temp_login']);
        $this->register_command('get_temp_login_log', [$this, 'cmd_get_temp_login_log']);

        // Register backup commands
        $this->register_command('create_checkpoint', [$this, 'cmd_create_checkpoint']);
        $this->register_command('restore_checkpoint', [$this, 'cmd_restore_checkpoint']);
        $this->register_command('list_checkpoints', [$this, 'cmd_list_checkpoints']);
        $this->register_command('delete_checkpoint', [$this, 'cmd_delete_checkpoint']);

        // Register migration commands
        $this->register_command('create_migration_backup', [$this, 'cmd_create_migration_backup']);
        $this->register_command('list_migration_backups', [$this, 'cmd_list_migration_backups']);
        $this->register_command('delete_migration_backup', [$this, 'cmd_delete_migration_backup']);
        $this->register_command('get_migration_status', [$this, 'cmd_get_migration_status']);
        $this->register_command('restore_migration_backup', [$this, 'cmd_restore_migration_backup']);

        // Store instance for singleton access
        if (self::$instance === null) {
            self::$instance = $this;
        }
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes() {
        // GET /wp-care/v1/health - Site health/status (API key auth)
        register_rest_route($this->namespace, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health_endpoint'],
            'permission_callback' => function ($request) {
                return WP_Care_Security::verify_api_key($request);
            },
        ]);

        // POST /wp-care/v1/command - Execute registered commands (HMAC auth)
        register_rest_route($this->namespace, '/command', [
            'methods'             => 'POST',
            'callback'            => [$this, 'command_endpoint'],
            'permission_callback' => function ($request) {
                return WP_Care_Security::verify_hmac($request);
            },
        ]);

        // GET /wp-care/v1/ping - Public connectivity check
        register_rest_route($this->namespace, '/ping', [
            'methods'             => 'GET',
            'callback'            => function () {
                return [
                    'status'  => 'ok',
                    'plugin'  => 'wp-care-connector',
                    'version' => WP_CARE_VERSION,
                ];
            },
            'permission_callback' => '__return_true',
        ]);

        // GET /wp-care/v1/api-key - Returns API key for onboarding (requires WP auth with manage_options)
        register_rest_route($this->namespace, '/api-key', [
            'methods'             => 'GET',
            'callback'            => [$this, 'api_key_endpoint'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        // Allow external plugins to register commands
        do_action('wp_care_register_commands', $this);
    }

    /**
     * Health endpoint handler
     *
     * Returns complete site map data including WP version, PHP version,
     * theme, plugins, builders, and content counts.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response Site health data.
     */
    public function health_endpoint($request) {
        return rest_ensure_response([
            'status'    => 'ok',
            'timestamp' => time(),
            'site_map'  => $this->site_mapper->get_site_map(),
        ]);
    }

    /**
     * API Key endpoint handler
     *
     * Returns the site's API key for onboarding. Requires WordPress
     * authentication with manage_options capability (admin).
     * Used by central API during automated plugin installation.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response|WP_Error API key or error.
     */
    public function api_key_endpoint($request) {
        $api_key = WP_Care_Security::get_api_key();

        if (!$api_key) {
            return new WP_Error(
                'no_api_key',
                'API key not configured. Please reactivate the plugin.',
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'apiKey'  => $api_key,
            'domain'  => home_url(),
        ]);
    }

    /**
     * Command endpoint handler
     *
     * Parses JSON body for command and args, validates the command
     * is registered, and executes the handler.
     *
     * @param WP_REST_Request $request The REST API request.
     * @return WP_REST_Response|WP_Error Command result or error.
     */
    public function command_endpoint($request) {
        // Parse JSON body
        $body = $request->get_json_params();

        // Validate command is provided
        if (empty($body['command'])) {
            return new WP_Error(
                'missing_command',
                'Command parameter is required',
                ['status' => 400]
            );
        }

        $command = sanitize_text_field($body['command']);
        $args = isset($body['args']) ? $body['args'] : [];

        // Validate command is registered
        if (!isset($this->commands[$command])) {
            return new WP_Error(
                'unknown_command',
                sprintf('Command "%s" is not registered', $command),
                ['status' => 400]
            );
        }

        // Execute command handler
        try {
            $result = call_user_func($this->commands[$command], $args);
            return rest_ensure_response([
                'status'  => 'ok',
                'command' => $command,
                'result'  => $result,
            ]);
        } catch (Exception $e) {
            return new WP_Error(
                'command_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Register a command handler
     *
     * External plugins can register commands via the wp_care_register_commands action:
     * add_action('wp_care_register_commands', function($api) {
     *     $api->register_command('my_command', 'my_callback');
     * });
     *
     * @param string   $name     Command name.
     * @param callable $callback Command handler callback.
     * @return void
     */
    public function register_command($name, $callback) {
        $this->commands[sanitize_key($name)] = $callback;
    }

    /**
     * Check if a command is registered
     *
     * @param string $name Command name.
     * @return bool True if command is registered.
     */
    public function has_command($name) {
        return isset($this->commands[sanitize_key($name)]);
    }

    /**
     * Get list of registered commands
     *
     * @return array List of command names.
     */
    public function get_registered_commands() {
        return array_keys($this->commands);
    }

    /**
     * Command: ping
     *
     * Simple acknowledgment for connectivity testing.
     *
     * @param array $args Command arguments (unused).
     * @return array Ping response.
     */
    public function cmd_ping($args) {
        return [
            'pong'      => true,
            'timestamp' => time(),
            'site'      => get_site_url(),
        ];
    }

    /**
     * Command: clear_cache
     *
     * Clears caches from common caching plugins and WordPress object cache.
     *
     * @param array $args Command arguments (unused).
     * @return array List of cleared caches.
     */
    public function cmd_clear_cache($args) {
        $cleared = [];

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared[] = 'w3tc';
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared[] = 'wp_super_cache';
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $cleared[] = 'litespeed';
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $cleared[] = 'wp_rocket';
        }

        // Elementor CSS cache
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            $cleared[] = 'elementor';
        }

        // SG Optimizer
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $cleared[] = 'sg_optimizer';
        }

        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
            $cleared[] = 'autoptimize';
        }

        // WP Fastest Cache
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
            $cleared[] = 'wp_fastest_cache';
        }

        // Breeze (Cloudways)
        if (class_exists('Breeze_PurgeCache')) {
            Breeze_PurgeCache::breeze_cache_flush();
            $cleared[] = 'breeze';
        }

        // WordPress object cache
        wp_cache_flush();
        $cleared[] = 'wp_object_cache';

        // Invalidate site map cache
        $this->site_mapper->invalidate_cache();
        $cleared[] = 'wp_care_site_map';

        return [
            'success' => true,
            'cleared' => $cleared,
        ];
    }

    /**
     * Command: get_option
     *
     * Retrieves a WordPress option value.
     * SEC-04: Only allowlisted options can be read.
     *
     * @param array $args Command arguments containing 'key'.
     * @return array Option value and existence status.
     */
    public function cmd_get_option($args) {
        // Validate key is provided
        if (empty($args['key'])) {
            return [
                'success' => false,
                'error'   => 'Option key is required',
            ];
        }

        $key = sanitize_key($args['key']);

        // SEC-04: Check if option is in read allowlist
        if (!in_array($key, $this->allowed_read_options, true)) {
            return [
                'success' => false,
                'error'   => sprintf('Option "%s" is not allowed for remote reading', $key),
                'allowed' => false,
            ];
        }

        // Check if option exists
        $exists = get_option($key, '__WP_CARE_NOT_EXISTS__') !== '__WP_CARE_NOT_EXISTS__';

        if (!$exists) {
            return [
                'success' => false,
                'error'   => sprintf('Option "%s" does not exist', $key),
                'exists'  => false,
            ];
        }

        return [
            'success' => true,
            'key'     => $key,
            'value'   => get_option($key),
            'exists'  => true,
        ];
    }

    /**
     * Command: set_option
     *
     * Updates a WordPress option value.
     * SEC-04: Only allowlisted options can be modified (allowlist, not blocklist).
     *
     * @param array $args Command arguments containing 'key' and 'value'.
     * @return array Success status.
     */
    public function cmd_set_option($args) {
        // Validate key is provided
        if (empty($args['key'])) {
            return [
                'success' => false,
                'error'   => 'Option key is required',
            ];
        }

        // Validate value is provided
        if (!isset($args['value'])) {
            return [
                'success' => false,
                'error'   => 'Option value is required',
            ];
        }

        $key = sanitize_key($args['key']);

        // SEC-04: Check if option is in write allowlist (allowlist approach)
        if (!in_array($key, $this->allowed_write_options, true)) {
            return [
                'success' => false,
                'error'   => sprintf('Option "%s" is not allowed for remote modification', $key),
                'allowed' => false,
            ];
        }

        // Create checkpoint before destructive operation (advisory - failure doesn't block)
        $checkpoint_id = null;
        $backup = new WP_Care_Backup();
        $checkpoint_id = $backup->create_checkpoint('set_option_' . $key);
        if ($checkpoint_id === false) {
            // Log failure but continue - checkpoint is advisory
            error_log('WP Care: Failed to create checkpoint before set_option, proceeding anyway');
        }

        // Update the option
        $updated = update_option($key, $args['value']);

        $response = [
            'success' => true,
            'key'     => $key,
            'updated' => $updated,
        ];

        // Include checkpoint info if created
        if ($checkpoint_id !== false && $checkpoint_id !== null) {
            $response['checkpoint_id'] = $checkpoint_id;
        }

        return $response;
    }

    /**
     * Check if an option is allowed for writing (SEC-04)
     *
     * @param string $key Option key.
     * @return bool True if option is allowed for writing.
     */
    public function is_option_allowed_write($key) {
        return in_array(sanitize_key($key), $this->allowed_write_options, true);
    }

    /**
     * Check if an option is allowed for reading (SEC-04)
     *
     * @param string $key Option key.
     * @return bool True if option is allowed for reading.
     */
    public function is_option_allowed_read($key) {
        return in_array(sanitize_key($key), $this->allowed_read_options, true);
    }

    /**
     * Check if an option is blocked from modification
     *
     * @deprecated Use is_option_allowed_write() instead (SEC-04)
     * @param string $key Option key.
     * @return bool True if option is blocked (not in write allowlist).
     */
    public function is_option_blocked($key) {
        // Invert logic: blocked = not in allowlist
        return !$this->is_option_allowed_write($key);
    }

    /**
     * Command: create_temp_login
     *
     * Creates a temporary admin login with 4-hour expiry.
     *
     * @param array $args Command arguments containing optional 'requester_id'.
     * @return array Login URL and user details, or error.
     */
    public function cmd_create_temp_login($args) {
        $temp_login = new WP_Care_Temp_Login();
        $requester_id = isset($args['requester_id']) ? sanitize_text_field($args['requester_id']) : 'api';

        $result = $temp_login->create_login($requester_id);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error'   => $result->get_error_message(),
            ];
        }

        return [
            'success'   => true,
            'login_url' => $result,
            'expires'   => time() + (4 * HOUR_IN_SECONDS),
            'requester' => $requester_id,
        ];
    }

    /**
     * Command: list_temp_users
     *
     * Lists all active (non-expired) temporary users.
     *
     * @param array $args Command arguments (unused).
     * @return array List of active temp users.
     */
    public function cmd_list_temp_users($args) {
        $temp_login = new WP_Care_Temp_Login();

        return [
            'success' => true,
            'users'   => $temp_login->get_active_temp_users(),
        ];
    }

    /**
     * Command: revoke_temp_login
     *
     * Revokes a temporary login by deleting the temp user.
     *
     * @param array $args Command arguments containing 'user_id'.
     * @return array Success or error status.
     */
    public function cmd_revoke_temp_login($args) {
        if (empty($args['user_id'])) {
            return [
                'success' => false,
                'error'   => 'User ID is required',
            ];
        }

        $temp_login = new WP_Care_Temp_Login();
        $revoked = $temp_login->revoke_login(absint($args['user_id']));

        if (!$revoked) {
            return [
                'success' => false,
                'error'   => 'User is not a temporary user or does not exist',
            ];
        }

        return [
            'success' => true,
            'user_id' => absint($args['user_id']),
            'message' => 'Temporary login revoked',
        ];
    }

    /**
     * Command: get_temp_login_log
     *
     * Retrieves the temp login audit log.
     *
     * @param array $args Command arguments containing optional 'limit'.
     * @return array Log entries.
     */
    public function cmd_get_temp_login_log($args) {
        $temp_login = new WP_Care_Temp_Login();
        $log = $temp_login->get_login_log();

        // Apply limit if specified
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        if ($limit > 0 && count($log) > $limit) {
            $log = array_slice($log, 0, $limit);
        }

        return [
            'success' => true,
            'log'     => $log,
            'count'   => count($log),
        ];
    }

    /**
     * Get list of options allowed for writing (SEC-04)
     *
     * @return array List of allowed option keys for writing.
     */
    public function get_allowed_write_options() {
        return $this->allowed_write_options;
    }

    /**
     * Get list of options allowed for reading (SEC-04)
     *
     * @return array List of allowed option keys for reading.
     */
    public function get_allowed_read_options() {
        return $this->allowed_read_options;
    }

    /**
     * Get list of blocked options
     *
     * @deprecated Use get_allowed_write_options() instead (SEC-04)
     * @return array List of blocked option keys.
     */
    public function get_blocked_options() {
        return $this->blocked_options;
    }

    /**
     * Command: create_checkpoint
     *
     * Creates a database checkpoint before destructive operations.
     *
     * @param array $args Command arguments containing optional 'operation_type'.
     * @return array Checkpoint ID and details, or error.
     */
    public function cmd_create_checkpoint($args) {
        $backup = new WP_Care_Backup();
        $operation_type = isset($args['operation_type']) ? sanitize_text_field($args['operation_type']) : 'manual';

        $checkpoint_id = $backup->create_checkpoint($operation_type);

        if ($checkpoint_id === false) {
            return [
                'success' => false,
                'error'   => 'Failed to create checkpoint - check error logs for details',
            ];
        }

        return [
            'success'       => true,
            'checkpoint_id' => $checkpoint_id,
            'operation'     => $operation_type,
            'created_at'    => gmdate('c'),
        ];
    }

    /**
     * Command: restore_checkpoint
     *
     * Restores database from a checkpoint.
     *
     * @param array $args Command arguments containing 'checkpoint_id'.
     * @return array Success or error status.
     */
    public function cmd_restore_checkpoint($args) {
        if (empty($args['checkpoint_id'])) {
            return [
                'success' => false,
                'error'   => 'Checkpoint ID is required',
            ];
        }

        $backup = new WP_Care_Backup();
        $checkpoint_id = sanitize_file_name($args['checkpoint_id']);

        $result = $backup->restore_checkpoint($checkpoint_id);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error'   => $result->get_error_message(),
            ];
        }

        return [
            'success'       => true,
            'checkpoint_id' => $checkpoint_id,
            'restored_at'   => gmdate('c'),
            'message'       => 'Database restored successfully',
        ];
    }

    /**
     * Command: list_checkpoints
     *
     * Lists all available checkpoints.
     *
     * @param array $args Command arguments (unused).
     * @return array List of checkpoints.
     */
    public function cmd_list_checkpoints($args) {
        $backup = new WP_Care_Backup();
        $checkpoints = $backup->list_checkpoints();

        return [
            'success'     => true,
            'checkpoints' => $checkpoints,
            'count'       => count($checkpoints),
        ];
    }

    /**
     * Command: delete_checkpoint
     *
     * Deletes a specific checkpoint.
     *
     * @param array $args Command arguments containing 'checkpoint_id'.
     * @return array Success or error status.
     */
    public function cmd_delete_checkpoint($args) {
        if (empty($args['checkpoint_id'])) {
            return [
                'success' => false,
                'error'   => 'Checkpoint ID is required',
            ];
        }

        $backup = new WP_Care_Backup();
        $checkpoint_id = sanitize_file_name($args['checkpoint_id']);

        $deleted = $backup->delete_checkpoint($checkpoint_id);

        if (!$deleted) {
            return [
                'success' => false,
                'error'   => 'Checkpoint not found or could not be deleted',
            ];
        }

        return [
            'success'       => true,
            'checkpoint_id' => $checkpoint_id,
            'message'       => 'Checkpoint deleted',
        ];
    }

    /**
     * Command: create_migration_backup
     *
     * Creates a full site migration backup (database + files).
     * Runs all phases in a single request. May take several minutes for large sites.
     *
     * @param array $args Command arguments with optional 'options' array.
     * @return array Migration details.
     */
    public function cmd_create_migration_backup($args) {
        $migration = new WP_Care_Migration();
        $options = isset($args['options']) ? $args['options'] : [];
        $result = $migration->run_full_export($options);

        if (isset($result['error']) && $result['error']) {
            return ['success' => false, 'error' => $result['error']];
        }

        WP_Care_Activity_Log::log('migration_created', [
            'migration_id' => $result['migration_id'],
            'size'         => isset($result['archive_size_human']) ? $result['archive_size_human'] : '',
        ]);

        return [
            'success'            => true,
            'migration_id'       => $result['migration_id'],
            'archive_size'       => isset($result['archive_size']) ? $result['archive_size'] : 0,
            'archive_size_human' => isset($result['archive_size_human']) ? $result['archive_size_human'] : '',
            'created_at'         => $result['created_at'],
        ];
    }

    /**
     * Command: list_migration_backups
     *
     * Lists all available migration backups.
     *
     * @param array $args Command arguments (unused).
     * @return array List of migrations.
     */
    public function cmd_list_migration_backups($args) {
        $migration  = new WP_Care_Migration();
        $migrations = $migration->list_migrations();

        return [
            'success'    => true,
            'migrations' => $migrations,
            'count'      => count($migrations),
        ];
    }

    /**
     * Command: delete_migration_backup
     *
     * Deletes a migration backup.
     *
     * @param array $args Command arguments containing 'migration_id'.
     * @return array Success status.
     */
    public function cmd_delete_migration_backup($args) {
        if (empty($args['migration_id'])) {
            return ['success' => false, 'error' => 'Migration ID is required'];
        }

        $migration = new WP_Care_Migration();
        $deleted = $migration->delete_migration(sanitize_file_name($args['migration_id']));

        if ($deleted) {
            WP_Care_Activity_Log::log('migration_deleted', [
                'migration_id' => $args['migration_id'],
            ]);
        }

        return [
            'success' => $deleted,
            'message' => $deleted ? 'Migration backup deleted' : 'Migration not found',
        ];
    }

    /**
     * Command: get_migration_status
     *
     * Returns the current state/metadata of a migration.
     *
     * @param array $args Command arguments containing 'migration_id'.
     * @return array Migration info.
     */
    public function cmd_get_migration_status($args) {
        if (empty($args['migration_id'])) {
            return ['success' => false, 'error' => 'Migration ID is required'];
        }

        $migration = new WP_Care_Migration();
        $info = $migration->get_migration_info(sanitize_file_name($args['migration_id']));

        if (!$info) {
            return ['success' => false, 'error' => 'Migration not found'];
        }

        return ['success' => true, 'migration' => $info];
    }

    /**
     * Command: restore_migration_backup
     *
     * Restores a site from a migration backup (database + files).
     * Runs all phases in a single request. Creates a DB checkpoint first.
     *
     * @param array $args Command arguments containing 'migration_id' and optional 'options'.
     * @return array Restore result.
     */
    public function cmd_restore_migration_backup($args) {
        if (empty($args['migration_id'])) {
            return ['success' => false, 'error' => 'Migration ID is required'];
        }

        $migration = new WP_Care_Migration();
        $migration_id = sanitize_file_name($args['migration_id']);
        $options = isset($args['options']) ? $args['options'] : [];

        $result = $migration->run_full_restore($migration_id, $options);

        if (isset($result['error']) && $result['error']) {
            return ['success' => false, 'error' => $result['error']];
        }

        WP_Care_Activity_Log::log('migration_restored', [
            'migration_id'  => $migration_id,
            'checkpoint_id' => isset($result['checkpoint_id']) ? $result['checkpoint_id'] : '',
        ]);

        return [
            'success'       => true,
            'migration_id'  => $migration_id,
            'checkpoint_id' => isset($result['checkpoint_id']) ? $result['checkpoint_id'] : null,
            'message'       => 'Site restored successfully from migration backup',
        ];
    }

    /**
     * Set the Central API base URL
     *
     * @param string $url The API base URL.
     * @return void
     */
    public static function set_api_base($url) {
        self::$api_base = rtrim($url, '/');
    }

    /**
     * Get the Central API base URL
     *
     * @return string The API base URL.
     */
    public static function get_api_base() {
        // Priority: constant > option > default
        if (defined('WP_CARE_API_URL')) {
            return rtrim(WP_CARE_API_URL, '/');
        }
        $option_url = get_option('wp_care_api_url', '');
        if (!empty($option_url)) {
            return rtrim($option_url, '/');
        }
        return self::$api_base;
    }

    /**
     * Register this site with the central API
     *
     * Called on plugin activation to register the site with the Central API.
     * The API returns a site ID which is stored for future requests.
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function register_with_central_api() {
        $api_key = WP_Care_Security::get_api_key();
        if (!$api_key) {
            return new WP_Error('no_api_key', 'API key not configured');
        }

        $domain = home_url();
        $name = get_bloginfo('name');

        $response = wp_remote_post(
            self::get_api_base() . '/sites',
            array(
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode(array(
                    'domain' => $domain,
                    'apiKey' => $api_key,
                    'name'   => $name,
                )),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 201 && isset($body['success']) && $body['success']) {
            // Store site ID for future requests
            update_option('wp_care_site_id', $body['data']['id']);
            // Submit initial health data immediately
            self::submit_health_to_api();
            return true;
        }

        if ($code === 409) {
            // Already registered - this is acceptable for re-activations
            // Try to get existing site ID by querying the API
            return true;
        }

        return new WP_Error(
            'registration_failed',
            isset($body['error']['message']) ? $body['error']['message'] : 'Registration failed'
        );
    }

    /**
     * Submit current site health to central API
     *
     * Sends the current site health data to the Central API.
     * Uses HMAC authentication with the stored API key.
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function submit_health_to_api() {
        $site_id = get_option('wp_care_site_id');
        if (!$site_id) {
            return new WP_Error('not_registered', 'Site not registered with API');
        }

        $api_key = WP_Care_Security::get_api_key();
        if (!$api_key) {
            return new WP_Error('no_api_key', 'API key not configured');
        }

        // Get site health data from the mapper
        $site_mapper = new WP_Care_Site_Mapper();
        $site_map = $site_mapper->get_site_map();

        // Transform site map to health data format expected by API
        // Note: site map keys differ from API keys (builders vs builder, content vs contentCounts)
        $builders = isset($site_map['builders']) ? $site_map['builders'] : array();
        $builder_name = !empty($builders) ? implode(', ', array_column($builders, 'name')) : null;

        $health = array(
            'wpVersion'     => $site_map['wp_version'],
            'phpVersion'    => $site_map['php_version'],
            'theme'         => $site_map['theme'],
            'plugins'       => $site_map['plugins'],
            'builder'       => $builder_name,
            'contentCounts' => isset($site_map['content']) ? $site_map['content'] : null,
        );

        $timestamp = (string) time();
        $body = wp_json_encode($health);

        // Generate HMAC signature: sha256(timestamp + "\n" + body, api_key)
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $api_key);

        $response = wp_remote_post(
            self::get_api_base() . '/sites/' . $site_id . '/health',
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Timestamp'  => $timestamp,
                    'X-Signature'  => $signature,
                    'X-Site-Id'    => $site_id,
                ),
                'body'    => $body,
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 201) {
            return true;
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        return new WP_Error(
            'health_submission_failed',
            isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Health submission failed'
        );
    }

    /**
     * Get the stored site ID from Central API registration
     *
     * @return string|false The site ID or false if not registered
     */
    public static function get_site_id() {
        return get_option('wp_care_site_id', false);
    }

    /**
     * Check if site is registered with Central API
     *
     * @return bool True if registered
     */
    public static function is_registered() {
        return !empty(get_option('wp_care_site_id'));
    }
}
