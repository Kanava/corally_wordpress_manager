<?php
/**
 * Plugin Name: Corally WordPress Manager
 * Plugin URI: https://github.com/Kanava/corally_wordpress_manager
 * Description: Authenticated REST access for AI workers: site scans, logs, cache management, custom CSS and plugin updates.
 * Version: 1.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Ville Bergman
 * Update URI: https://github.com/Kanava/corally_wordpress_manager
 * Text Domain: corally-wordpress-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CORALLY_WPM_VERSION', '1.5.0');
define('CORALLY_WPM_FILE', __FILE__);

require_once __DIR__ . '/includes/class-github-updater.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook(__FILE__, function () {
    if (is_file(WPMU_PLUGIN_DIR . '/corally-wp-developer.php') || defined('CORALLY_WPM_LEGACY_LOADED')) {
        wp_die(esc_html__('Remove the old corally-wp-developer.php from wp-content/mu-plugins before activating Corally WordPress Manager. Existing options and Application Passwords will be retained.', 'corally-wordpress-manager'));
    }
    Corally_WPM_GitHub_Updater::clear_cache();
    delete_site_transient('update_plugins');
});

register_deactivation_hook(__FILE__, ['Corally_WPM_GitHub_Updater', 'clear_cache']);

// MU plugins load first. Avoid redeclaring their legacy bridge functions.
if (function_exists('corally_wpdev_perm')) {
    define('CORALLY_WPM_LEGACY_LOADED', true);
    add_action('admin_notices', 'corally_wpm_legacy_notice');
    add_action('network_admin_notices', 'corally_wpm_legacy_notice');
} else {
    require_once __DIR__ . '/includes/rest-api.php';
}

$corally_wpm_updater = new Corally_WPM_GitHub_Updater(__FILE__);
$corally_wpm_updater->register();
corally_wpm_register_admin($corally_wpm_updater);
