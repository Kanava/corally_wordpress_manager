<?php
/** GitHub Releases adapter for WordPress's native plugin updater. */
if (!defined('ABSPATH')) {
    exit;
}

class Corally_WPM_GitHub_Updater {
    const REPOSITORY = 'https://github.com/Kanava/corally_wordpress_manager';
    const API = 'https://api.github.com/repos/Kanava/corally_wordpress_manager/releases/latest';
    const SLUG = 'corally-wordpress-manager';
    const CACHE = 'corally_wpm_release_v1';

    private $plugin_file;

    public function __construct($file) {
        $this->plugin_file = plugin_basename($file);
    }

    public function register() {
        add_filter('update_plugins_github.com', [$this, 'update'], 10, 4);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'upgrade_complete'], 10, 2);
    }

    public static function clear_cache() {
        delete_site_transient(self::CACHE);
    }

    /** Cache failures briefly too, so an empty repository never breaks a site. */
    private function remember_error($message) {
        $result = ['error' => $message];
        set_site_transient(self::CACHE, $result, 5 * MINUTE_IN_SECONDS);
        return $result;
    }

    private function json_request($url) {
        $response = wp_safe_remote_get($url, [
            'timeout' => 10,
            'limit_response_size' => 1048576,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Corally-WordPress-Manager/' . CORALLY_WPM_VERSION,
            ],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('corally_release_request', 'Could not contact GitHub. Retry later.');
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error('corally_release_http', sprintf('GitHub returned HTTP %d. The repository may not have a published release yet, or the API rate limit may have been reached.', $status));
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : new WP_Error('corally_release_json', 'GitHub returned invalid release data.');
    }

    /** Only accept assets uploaded to this exact repository and release tag. */
    private function asset_url($assets, $name, $tag) {
        $expected = self::REPOSITORY . '/releases/download/' . rawurlencode($tag) . '/' . $name;
        foreach ($assets as $asset) {
            if (is_array($asset) && isset($asset['name'], $asset['state'], $asset['browser_download_url'])
                && $asset['name'] === $name && $asset['state'] === 'uploaded'
                && $asset['browser_download_url'] === $expected) {
                return $expected;
            }
        }
        return '';
    }

    public function release() {
        $cached = get_site_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached;
        }
        $release = $this->json_request(self::API);
        if (is_wp_error($release)) {
            return $this->remember_error($release->get_error_message());
        }
        if (!isset($release['draft'], $release['prerelease'], $release['tag_name'], $release['assets'])
            || $release['draft'] || $release['prerelease'] || !is_string($release['tag_name'])
            || !is_array($release['assets'])
            || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $release['tag_name'], $matches)) {
            return $this->remember_error('No valid stable release found. Tags must use vMAJOR.MINOR.PATCH.');
        }
        $version = $matches[1];
        $package = $this->asset_url($release['assets'], self::SLUG . '.zip', $release['tag_name']);
        $manifest_url = $this->asset_url($release['assets'], self::SLUG . '.json', $release['tag_name']);
        if (!$package || !$manifest_url) {
            return $this->remember_error('The latest release is missing its plugin ZIP or JSON manifest. Run the release workflow.');
        }
        $manifest = $this->json_request($manifest_url);
        if (is_wp_error($manifest)) {
            return $this->remember_error($manifest->get_error_message());
        }
        if (!isset($manifest['version'], $manifest['requires'], $manifest['requires_php'])
            || $manifest['version'] !== $version
            || !is_string($manifest['requires']) || !preg_match('/^\d+\.\d+(\.\d+)?$/D', $manifest['requires'])
            || !is_string($manifest['requires_php']) || !preg_match('/^\d+\.\d+(\.\d+)?$/D', $manifest['requires_php'])) {
            return $this->remember_error('The release manifest is invalid or does not match the release tag.');
        }
        $result = [
            'version' => $version,
            'package' => $package,
            'url' => self::REPOSITORY . '/releases/tag/' . rawurlencode($release['tag_name']),
            'requires' => $manifest['requires'],
            'requires_php' => $manifest['requires_php'],
            'body' => isset($release['body']) && is_string($release['body']) ? $release['body'] : '',
        ];
        set_site_transient(self::CACHE, $result, 6 * HOUR_IN_SECONDS);
        return $result;
    }

    public function update($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== $this->plugin_file) {
            return $update;
        }
        $release = $this->release();
        if (isset($release['error'])) {
            return $update;
        }
        // WordPress performs version comparison, compatibility checks and scheduling.
        return [
            'id' => self::REPOSITORY,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
        ];
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }
        $release = $this->release();
        if (isset($release['error'])) {
            return new WP_Error('corally_release_unavailable', $release['error']);
        }
        return (object) [
            'name' => 'Corally WordPress Manager',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => 'Kanava.to / Corally',
            'homepage' => self::REPOSITORY,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Authenticated REST access for AI workers, with updates from GitHub Releases.',
                'changelog' => '<pre style="white-space:pre-wrap">' . esc_html($release['body']) . '</pre>',
            ],
        ];
    }

    public function upgrade_complete($upgrader, $options) {
        if (isset($options['type'], $options['action']) && $options['type'] === 'plugin'
            && $options['action'] === 'update'
            && (in_array($this->plugin_file, (array) ($options['plugins'] ?? []), true)
                || ($options['plugin'] ?? '') === $this->plugin_file)) {
            self::clear_cache();
        }
    }
}
