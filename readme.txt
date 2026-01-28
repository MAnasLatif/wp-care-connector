=== WP Care Connector ===
Contributors: wpcare
Tags: management, remote, maintenance, support, monitoring
Requires at least: 4.7
Tested up to: 6.4
Requires PHP: 5.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure remote WordPress management connector for WP Care Platform.

== Description ==

WP Care Connector enables secure remote management of your WordPress site through the WP Care Platform. Once installed and configured, your site can be monitored, maintained, and supported remotely.

**Features:**

* **Site Health Monitoring** - Automatic collection of WordPress version, PHP version, theme, plugins, and content statistics
* **Secure Command Execution** - HMAC-authenticated remote commands for maintenance tasks
* **Temporary Admin Access** - Generate secure, time-limited admin login links (4-hour expiry)
* **Database Checkpoints** - Create and restore database backups before critical operations
* **Cache Management** - Clear caches from all major caching plugins with one command

**Security:**

* All remote commands require HMAC signature verification
* API keys are encrypted at rest using WordPress salts
* Temporary logins are one-time use and auto-expire
* Critical options are blocked from remote modification

**Supported Caching Plugins:**

* W3 Total Cache
* WP Super Cache
* LiteSpeed Cache
* WP Rocket
* SG Optimizer
* Autoptimize
* WP Fastest Cache
* Breeze (Cloudways)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-care-connector/` or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Get Help > Settings to configure your API connection
4. Your site will automatically register with the WP Care Platform

== Frequently Asked Questions ==

= Is my site data secure? =

Yes. All communication uses HMAC signature verification. Your API key is encrypted at rest and never transmitted in plain text. Remote commands cannot modify critical settings like site URL or admin email.

= What data is collected? =

The plugin collects: WordPress version, PHP version, active theme, installed plugins, page builder detection, and content counts (posts, pages, users). No personal data or content is transmitted.

= Can I use this without the WP Care Platform? =

The plugin is designed to work with the WP Care Platform. Without a connected platform, the plugin provides REST endpoints but no active management features.

= How do temporary logins work? =

When requested through the platform, a temporary administrator account is created with a unique login link. The link works once and the account is automatically deleted after 4 hours.

== Changelog ==

= 1.0.0 =
* Initial release
* Site health monitoring
* HMAC-authenticated command execution
* Temporary admin login system
* Database checkpoint/restore
* Multi-plugin cache clearing

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Care Connector.
