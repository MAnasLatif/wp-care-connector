=== WP Care Connector ===
Contributors: wpcare
Tags: management, cache, backup, temporary-login, site-health
Requires at least: 4.7
Tested up to: 6.4
Requires PHP: 5.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site management toolkit with cache clearing, database backups, temporary admin logins, and site health monitoring.

== Description ==

WP Care Connector is a comprehensive site management toolkit that helps you maintain your WordPress site. Use it standalone or connect to the WP Care Platform for remote management.

**Standalone Features (No Account Required):**

* **One-Click Cache Clearing** - Clear all caches from WordPress object cache, page builders (Elementor), and 8+ popular caching plugins including W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Rocket, and more.
* **Database Backups** - Create checkpoint backups of your database before making changes. Restore if something goes wrong.
* **Temporary Admin Logins** - Generate secure, time-limited admin login links (4-hour expiry). Perfect for giving support access without sharing your password.
* **Site Health Dashboard** - View your WordPress version, PHP version, theme, plugins, content counts, and environment information at a glance.
* **Site Info Export** - Export your site configuration for support requests or documentation.

**Connected Features (With WP Care Platform):**

When connected to the WP Care Platform, you also get:

* Remote site monitoring
* Support ticket submission with automatic site context capture
* Remote command execution for maintenance tasks
* Centralized management of multiple sites

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-care-connector/` or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the standalone tools immediately via Get Help > Tools
4. Optionally, go to Get Help > Settings to connect to the WP Care Platform

== Frequently Asked Questions ==

= Do I need an account to use this plugin? =

No! The core tools (cache clearing, backups, temporary logins, site health) work immediately without any account or configuration.

= What caching plugins are supported? =

The plugin can clear caches from: W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Rocket, SG Optimizer, Autoptimize, WP Fastest Cache, Breeze (Cloudways), Elementor, and WordPress object cache.

= How do temporary logins work? =

Click "Generate Login Link" to create a unique URL. Share this URL with whoever needs temporary admin access. The link:
- Works only once (one-time use)
- Expires after 4 hours
- Creates a temporary admin user that's automatically deleted
- Logs all access for your records

= Are database backups full backups? =

The plugin creates checkpoint backups of your database (SQL exports). These are meant for quick rollback after changes, not as a replacement for full site backups. We recommend also using a comprehensive backup solution.

= Is my site data secure? =

Yes. When connected to the WP Care Platform:
- All communication uses HMAC signature verification
- Your API key is encrypted at rest using WordPress salts
- Temporary logins are one-time use and auto-expire
- Critical options are blocked from remote modification

= What data is transmitted to the WP Care Platform? =

Only when you choose to connect: WordPress version, PHP version, active theme, installed plugins, page builder detection, and content counts. No personal data, content, or credentials are transmitted.

== External Services ==

This plugin can optionally connect to the WP Care Platform (wpcare.io) for remote management features. This connection is entirely optional - all standalone features work without it.

When connected, the following data may be transmitted:
- Site URL and name
- WordPress and PHP versions
- Active theme and plugins
- Content statistics (post/page/user counts)

No personal data, passwords, or content is ever transmitted. See our Privacy Policy: https://wpcare.io/privacy

== Screenshots ==

1. Tools page with cache clearing, backup, and temporary login features
2. Site health dashboard showing WordPress environment
3. Settings page for API connection

== Changelog ==

= 1.0.0 =
* Initial release
* Standalone tools: cache clearing, database backups, temporary admin logins
* Site health monitoring and export
* Optional WP Care Platform connection
* Support for 8+ caching plugins

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Care Connector.
