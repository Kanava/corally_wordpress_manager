<?php
/** Minimal connection and update status screen. */
if (!defined('ABSPATH')) {
    exit;
}

function corally_wpm_legacy_notice() {
    if (current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Corally WordPress Manager: remove the legacy corally-wp-developer.php from mu-plugins. The old bridge is currently handling REST requests.', 'corally-wordpress-manager') . '</p></div>';
    }
}

function corally_wpm_register_admin($updater) {
    $page = function () use ($updater) {
        corally_wpm_admin_page($updater);
    };
    add_action('admin_menu', function () use ($page) {
        add_management_page('Corally WordPress Manager', 'Corally Manager', 'manage_options', 'corally-wordpress-manager', $page);
    });
    add_action('network_admin_menu', function () use ($page) {
        add_submenu_page('settings.php', 'Corally WordPress Manager', 'Corally Manager', 'manage_network_options', 'corally-wordpress-manager', $page);
    });
    add_action('admin_post_corally_wpm_check_updates', function () use ($updater) {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You cannot update plugins.', 'corally-wordpress-manager'), '', ['response' => 403]);
        }
        check_admin_referer('corally_wpm_check_updates');
        $updater::clear_cache();
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $url = is_multisite() ? network_admin_url('settings.php?page=corally-wordpress-manager') : admin_url('tools.php?page=corally-wordpress-manager');
        wp_safe_redirect($url);
        exit;
    });
    add_filter('plugin_action_links_' . plugin_basename(CORALLY_WPM_FILE), function ($links) {
        $url = admin_url('tools.php?page=corally-wordpress-manager');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Status', 'corally-wordpress-manager') . '</a>';
        return $links;
    });
}

function corally_wpm_admin_page($updater) {
    if (!current_user_can(is_network_admin() ? 'manage_network_options' : 'manage_options')) {
        return;
    }
    $release = $updater->release();
    ?>
    <div class="wrap">
        <h1>Corally WordPress Manager</h1>
        <p>Installed version: <strong><?php echo esc_html(CORALLY_WPM_VERSION); ?></strong></p>
        <h2>Worker connection</h2>
        <p>REST base URL: <code><?php echo esc_html(rest_url('corally/v1/')); ?></code></p>
        <p>Use HTTPS and a WordPress Application Password for a dedicated administrator account. Create or revoke passwords in that user’s WordPress profile. Existing worker credentials continue to work.</p>
        <p>All routes require <code>manage_options</code>. Plugin updates also require <code>update_plugins</code>; CSS writes require <code>edit_css</code>.</p>
        <h2>GitHub updates</h2>
        <p><a href="<?php echo esc_url(Corally_WPM_GitHub_Updater::REPOSITORY . '/releases'); ?>">View releases</a></p>
        <?php if (isset($release['error'])) : ?>
            <p><?php echo esc_html($release['error']); ?></p>
        <?php else : ?>
            <p>Latest stable version: <strong><?php echo esc_html($release['version']); ?></strong></p>
        <?php endif; ?>
        <p>Install available updates from the Plugins screen. To install future releases automatically, enable auto-updates for this plugin there. GitHub results are cached for six hours.</p>
        <?php if (current_user_can('update_plugins')) : ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="corally_wpm_check_updates">
                <?php wp_nonce_field('corally_wpm_check_updates'); ?>
                <?php submit_button('Check for updates now', 'secondary'); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
