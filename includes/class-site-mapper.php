<?php
/**
 * WP Care Site Mapper
 *
 * Captures complete WordPress site context for diagnostics and support.
 * This class is the source of truth for site diagnostics - WP version, PHP version,
 * theme, plugins, builders, and content counts.
 *
 * @package WP_Care_Connector
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_Care_Site_Mapper
 *
 * Provides comprehensive site mapping functionality for diagnostics.
 */
class WP_Care_Site_Mapper {

    /**
     * Cache key for site map transient.
     *
     * @var string
     */
    private $cache_key = 'wp_care_site_map';

    /**
     * Cache expiry time in seconds (5 minutes).
     *
     * @var int
     */
    private $cache_expiry = 300;

    /**
     * Get complete site map with all diagnostic information.
     *
     * Uses transient caching to prevent repeated expensive queries.
     *
     * @param bool $force_refresh Force refresh of cached data.
     * @return array Complete site diagnostic data.
     */
    public function get_site_map( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( $this->cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $map = $this->build_site_map();
        set_transient( $this->cache_key, $map, $this->cache_expiry );

        return $map;
    }

    /**
     * Build the complete site map data.
     *
     * @return array Complete site diagnostic data.
     */
    private function build_site_map() {
        global $wp_version;

        return array(
            'wp_version'  => $wp_version,
            'php_version' => PHP_VERSION,
            'site_url'    => get_site_url(),
            'home_url'    => get_home_url(),
            'admin_email' => get_option( 'admin_email' ),
            'theme'       => $this->get_theme_info(),
            'plugins'     => $this->get_plugin_list(),
            'builders'    => $this->detect_page_builder(),
            'content'     => $this->get_content_counts(),
            'environment' => $this->get_environment_info(),
            'captured_at' => time(),
        );
    }

    /**
     * Invalidate the site map cache.
     *
     * Should be called when plugins/themes change.
     *
     * @return bool True if cache was deleted, false otherwise.
     */
    public function invalidate_cache() {
        return delete_transient( $this->cache_key );
    }

    /**
     * Register cache invalidation hooks.
     *
     * Called during plugin initialization to set up automatic cache clearing.
     *
     * @return void
     */
    public function register_cache_hooks() {
        add_action( 'switch_theme', array( $this, 'invalidate_cache' ) );
        add_action( 'activated_plugin', array( $this, 'invalidate_cache' ) );
        add_action( 'deactivated_plugin', array( $this, 'invalidate_cache' ) );
        add_action( 'upgrader_process_complete', array( $this, 'invalidate_cache' ) );
    }

    /**
     * Detect active page builders on the site.
     *
     * Checks for common page builders by their constants, classes, or functions.
     *
     * @return array Associative array of detected builders with versions.
     */
    public function detect_page_builder() {
        $builders = array();

        // Elementor
        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            $builders['elementor'] = array(
                'name'    => 'Elementor',
                'version' => ELEMENTOR_VERSION,
                'pro'     => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : false,
            );
        }

        // Bricks Builder
        if ( defined( 'BRICKS_VERSION' ) ) {
            $builders['bricks'] = array(
                'name'    => 'Bricks Builder',
                'version' => BRICKS_VERSION,
            );
        }

        // Beaver Builder
        if ( class_exists( 'FLBuilder' ) ) {
            $builders['beaver_builder'] = array(
                'name'    => 'Beaver Builder',
                'version' => defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : 'unknown',
            );
        }

        // Divi
        if ( function_exists( 'et_get_theme_version' ) ) {
            $builders['divi'] = array(
                'name'    => 'Divi',
                'version' => et_get_theme_version(),
            );
        }

        // WPBakery Visual Composer
        if ( defined( 'WPB_VC_VERSION' ) ) {
            $builders['wpbakery'] = array(
                'name'    => 'WPBakery Page Builder',
                'version' => WPB_VC_VERSION,
            );
        }

        // Oxygen Builder
        if ( defined( 'CT_VERSION' ) ) {
            $builders['oxygen'] = array(
                'name'    => 'Oxygen Builder',
                'version' => CT_VERSION,
            );
        }

        // Breakdance
        if ( class_exists( 'Breakdance\Plugin' ) ) {
            $builders['breakdance'] = array(
                'name'    => 'Breakdance',
                'version' => defined( 'BREAKDANCE_VERSION' ) ? BREAKDANCE_VERSION : 'unknown',
            );
        }

        // Gutenberg / Block Editor (native in WP 5.0+)
        if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
            $builders['gutenberg'] = array(
                'name'       => 'Gutenberg (Block Theme)',
                'version'    => 'native',
                'block_theme' => true,
            );
        }

        return $builders;
    }

    /**
     * Get current theme information.
     *
     * @return array Theme details including parent theme if applicable.
     */
    private function get_theme_info() {
        $theme = wp_get_theme();

        $info = array(
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'parent'  => null,
        );

        // Check for parent theme
        if ( $theme->parent() ) {
            $parent_theme   = $theme->parent();
            $info['parent'] = array(
                'name'    => $parent_theme->get( 'Name' ),
                'version' => $parent_theme->get( 'Version' ),
            );
        }

        // Check if block theme (WP 5.9+)
        if ( function_exists( 'wp_is_block_theme' ) ) {
            $info['is_block_theme'] = wp_is_block_theme();
        } else {
            $info['is_block_theme'] = false;
        }

        return $info;
    }

    /**
     * Get list of all plugins with their status.
     *
     * @return array List of plugins with name, version, and active status.
     */
    private function get_plugin_list() {
        // Ensure get_plugins() function is available
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $update_plugins = get_site_transient( 'update_plugins' );
        $plugins        = array();

        foreach ( $all_plugins as $plugin_path => $plugin_data ) {
            $slug = dirname( $plugin_path );
            if ( '.' === $slug ) {
                // Single-file plugin, use filename without .php
                $slug = basename( $plugin_path, '.php' );
            }

            $has_update = false;
            if ( $update_plugins && isset( $update_plugins->response[ $plugin_path ] ) ) {
                $has_update = $update_plugins->response[ $plugin_path ]->new_version ?? false;
            }

            $plugins[] = array(
                'slug'             => $slug,
                'name'             => $plugin_data['Name'],
                'version'          => $plugin_data['Version'],
                'active'           => in_array( $plugin_path, $active_plugins, true ),
                'update_available' => $has_update,
            );
        }

        // Handle must-use plugins
        $mu_plugins = get_mu_plugins();
        if ( ! empty( $mu_plugins ) ) {
            foreach ( $mu_plugins as $plugin_path => $plugin_data ) {
                $plugins[] = array(
                    'slug'             => basename( $plugin_path, '.php' ),
                    'name'             => $plugin_data['Name'],
                    'version'          => $plugin_data['Version'],
                    'active'           => true, // MU plugins are always active
                    'update_available' => false,
                    'must_use'         => true,
                );
            }
        }

        return $plugins;
    }

    /**
     * Get content counts for posts, pages, and other post types.
     *
     * @return array Content counts by type.
     */
    private function get_content_counts() {
        $counts = array(
            'posts'  => $this->get_post_count( 'post' ),
            'pages'  => $this->get_post_count( 'page' ),
            'media'  => $this->get_post_count( 'attachment', 'inherit' ),
        );

        // Check for WooCommerce products
        if ( post_type_exists( 'product' ) ) {
            $counts['products'] = $this->get_post_count( 'product' );
        }

        // Get user count
        $user_counts       = count_users();
        $counts['users']   = $user_counts['total_users'];

        return $counts;
    }

    /**
     * Get count for a specific post type.
     *
     * @param string $post_type Post type to count.
     * @param string $status    Post status to count. Default 'publish'.
     * @return int Post count.
     */
    private function get_post_count( $post_type, $status = 'publish' ) {
        $counts = wp_count_posts( $post_type );
        return isset( $counts->$status ) ? (int) $counts->$status : 0;
    }

    /**
     * Get environment information.
     *
     * @return array Environment configuration details.
     */
    private function get_environment_info() {
        return array(
            'memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ),
            'max_upload_size' => size_format( wp_max_upload_size() ),
            'debug_mode'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'debug_log'       => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
            'multisite'       => is_multisite(),
            'ssl'             => is_ssl(),
            'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
            'wp_cli_available' => defined( 'WP_CLI' ) && WP_CLI,
        );
    }
}
