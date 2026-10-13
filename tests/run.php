<?php
/** Dependency-free regression tests with WordPress API doubles. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$test_root = sys_get_temp_dir() . '/corally-tests-' . bin2hex(random_bytes(6)) . '/';
mkdir($test_root . 'wp-admin/includes', 0700, true);
foreach (['file.php', 'misc.php', 'class-wp-upgrader.php', 'update.php'] as $file) {
    file_put_contents($test_root . 'wp-admin/includes/' . $file, '<?php');
}
register_shutdown_function(function () use ($test_root) {
    foreach (glob($test_root . 'wp-admin/includes/*') as $file) { unlink($file); }
    rmdir($test_root . 'wp-admin/includes');
    rmdir($test_root . 'wp-admin');
    rmdir($test_root);
});
define('ABSPATH', $test_root);
define('WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/corally-nonexistent-mu-directory');
define('WP_CONTENT_DIR', sys_get_temp_dir());
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['hooks'] = [];
$GLOBALS['cache'] = [];
$GLOBALS['http'] = [];
$GLOBALS['requests'] = [];
$GLOBALS['caps'] = [];
$GLOBALS['routes'] = [];
$GLOBALS['options'] = [];
class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function plugin_basename($file) { return 'corally-wordpress-manager/' . basename($file); }
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function register_activation_hook($file, $callback) { $GLOBALS['activate'] = $callback; }
function register_deactivation_hook($file, $callback) { $GLOBALS['deactivate'] = $callback; }
function get_site_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient($key, $data, $ttl) { $GLOBALS['cache'][$key] = $data; $GLOBALS['ttl'] = $ttl; }
function delete_site_transient($key) { unset($GLOBALS['cache'][$key]); }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests'][] = $url;
    if (!array_key_exists($url, $GLOBALS['http'])) {
        throw new RuntimeException('Unexpected HTTP request: ' . $url);
    }
    return $GLOBALS['http'][$url];
}
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html__($value, $domain) { return esc_html($value); }
function wp_die($message) { throw new RuntimeException($message); }
function current_user_can($cap) { return in_array($cap, $GLOBALS['caps'], true); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['routes'][$args['methods'] . ' ' . $namespace . $route] = $args; }
function get_plugins() { return $GLOBALS['plugins'] ?? []; }
function is_plugin_active($plugin) { return $GLOBALS['active'] ?? false; }
function is_plugin_active_for_network($plugin) { return $GLOBALS['network_active'] ?? false; }
function activate_plugin($plugin, $redirect = '', $network = false, $silent = false) {
    $GLOBALS['reactivation_network'] = $network;
    $GLOBALS['active'] = true;
    return $GLOBALS['activation_result'] ?? null;
}
function wp_clean_plugins_cache($updates) {}
function wp_update_plugins() {}
class Automatic_Upgrader_Skin {
    public function get_upgrade_messages() { return []; }
}
class Plugin_Upgrader {
    public function __construct($skin) {}
    public function bulk_upgrade($plugins) {
        if (!empty($GLOBALS['deactivate_during_update'])) { $GLOBALS['active'] = false; }
        return $GLOBALS['bulk_result'];
    }
}
function get_plugin_updates() { return []; }
function get_theme_updates() { return []; }
function get_core_updates() { return []; }
function wp_get_theme() { return false; }
function wp_get_custom_css() { return 'body { color: red; }'; }
function get_stylesheet() { return 'test-theme'; }

$legacy = isset($argv[1]) && $argv[1] === '--legacy';
if ($legacy) {
    function corally_wpdev_perm() { return true; }
}
require dirname(__DIR__) . '/corally-wp-developer.php';
$checks = 0;
function check($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
if ($legacy) {
    check(defined('CORALLY_WPM_LEGACY_LOADED'), 'legacy bridge detected');
    check(!function_exists('corally_wpdev_site_info'), 'legacy functions not redeclared');
    try {
        ($GLOBALS['activate'])();
        throw new Exception('Activation should have been rejected');
    } catch (RuntimeException $error) {
        check(strpos($error->getMessage(), 'Remove the old') !== false, 'activation explains migration');
    }
    echo "Legacy migration: $checks checks passed.\n";
    exit;
}

$updater = $corally_wpm_updater;
$api = Corally_WPM_GitHub_Updater::API;
$repo = Corally_WPM_GitHub_Updater::REPOSITORY;
$plugin = 'corally-wordpress-manager/corally-wp-developer.php';
function fixture($changes = [], $manifest_changes = []) {
    global $api, $repo;
    Corally_WPM_GitHub_Updater::clear_cache();
    $GLOBALS['requests'] = [];
    $manifest = $repo . '/releases/download/v1.6.0/corally-wordpress-manager.json';
    $release = array_replace([
        'tag_name' => 'v1.6.0', 'draft' => false, 'prerelease' => false,
        'body' => '<script>alert(1)</script>',
        'assets' => [
            ['name' => 'corally-wordpress-manager.zip', 'state' => 'uploaded', 'browser_download_url' => $repo . '/releases/download/v1.6.0/corally-wordpress-manager.zip'],
            ['name' => 'corally-wordpress-manager.json', 'state' => 'uploaded', 'browser_download_url' => $manifest],
        ],
    ], $changes);
    $GLOBALS['http'] = [
        $api => ['code' => 200, 'body' => json_encode($release)],
        $manifest => ['code' => 200, 'body' => json_encode(array_replace(['version' => '1.6.0', 'requires' => '6.0', 'requires_php' => '7.4'], $manifest_changes))],
    ];
    return $release;
}

fixture();
check(isset($GLOBALS['hooks']['update_plugins_github.com']), 'native external updater registered');
check($updater->update('unchanged', [], 'another/plugin.php', []) === 'unchanged', 'other GitHub plugins untouched');
check(count($GLOBALS['requests']) === 0, 'no requests for unrelated plugins');
$data = $updater->update(false, ['Version' => '1.5.0'], $plugin, []);
check($data['version'] === '1.6.0' && substr($data['package'], -4) === '.zip', 'stable package offered');
check($data['requires'] === '6.0' && $data['requires_php'] === '7.4', 'compatibility metadata propagated');
check($GLOBALS['ttl'] === 21600, 'successful lookup cached six hours');
$updater->release();
check(count($GLOBALS['requests']) === 2, 'release and manifest only fetched once');
$details = $updater->plugin_info(false, 'plugin_information', (object) ['slug' => 'corally-wordpress-manager']);
check(strpos($details->sections['changelog'], '<script>') === false, 'release notes escaped');
check($updater->plugin_info('original', 'plugin_information', (object) ['slug' => 'other']) === 'original', 'unrelated plugin details untouched');
$updater->upgrade_complete(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => [$plugin]]);
check(get_site_transient(Corally_WPM_GitHub_Updater::CACHE) === false, 'own upgrade invalidates cache');

foreach ([['draft' => true], ['prerelease' => true], ['tag_name' => 'v2.0.0-beta.1'], ['tag_name' => 'main'], ['assets' => []]] as $change) {
    fixture($change);
    check($updater->update(false, [], $plugin, []) === false, 'invalid/unpublished release ignored');
    check($GLOBALS['ttl'] === 300, 'invalid release cached five minutes');
}
$release = fixture();
$release['assets'][0]['browser_download_url'] = 'https://evil.example/plugin.zip';
fixture($release);
check(isset($updater->release()['error']), 'foreign ZIP URL rejected');
fixture([], ['version' => '9.9.9']);
check(isset($updater->release()['error']), 'manifest/tag mismatch rejected');
fixture([], ['requires_php' => []]);
check(isset($updater->release()['error']), 'malformed compatibility field rejected');
foreach ([404, 403, 429, 500] as $status) {
    fixture();
    $GLOBALS['http'][$api] = ['code' => $status, 'body' => '{}'];
    check($updater->update(false, [], $plugin, []) === false, 'HTTP failure leaves updates alone');
    $updater->release();
    check(count($GLOBALS['requests']) === 1, 'HTTP failure cached');
}
fixture();
$GLOBALS['http'][$api] = ['code' => 200, 'body' => '{invalid'];
check(isset($updater->release()['error']), 'invalid JSON handled');
fixture();
$GLOBALS['http'][$api] = new WP_Error('timeout', 'timeout');
check(isset($updater->release()['error']), 'transport failure handled');
fixture();
$manifest_url = $repo . '/releases/download/v1.6.0/corally-wordpress-manager.json';
$GLOBALS['http'][$manifest_url] = ['code' => 404, 'body' => ''];
check(isset($updater->release()['error']), 'manifest fetch failure handled');

foreach ($GLOBALS['hooks']['rest_api_init'] as $callback) { $callback(); }
check(count($GLOBALS['routes']) === 8, 'all eight legacy method/route pairs retained');
foreach ($GLOBALS['routes'] as $route) {
    check(call_user_func($route['permission_callback']) === false, 'anonymous access denied');
}
$GLOBALS['caps'] = ['manage_options'];
check(corally_wpdev_perm(), 'administrator can read bridge');
check(!corally_wpdev_update_perm(), 'manage_options alone cannot update plugins');
check(!corally_wpdev_css_perm(), 'manage_options alone cannot write CSS');
$GLOBALS['caps'] = ['manage_options', 'update_plugins', 'edit_css'];
check(corally_wpdev_update_perm() && corally_wpdev_css_perm(), 'operation capabilities honored');
$GLOBALS['wp_version'] = '6.0';
$info = corally_wpdev_site_info();
check($info->data['mu_plugin_version'] === CORALLY_WPM_VERSION && $info->data['plugin_version'] === CORALLY_WPM_VERSION, 'old/new version fields retained');
check(corally_wpdev_get_custom_css()->data['css'] === 'body { color: red; }', 'existing CSS remains readable');
$request = new class {
    public function get_json_params() { return ['suppress_log_deprecations' => true]; }
};
corally_wpdev_set_flags($request);
check(corally_wpdev_get_flags()->data['suppress_log_deprecations'] === 1, 'existing option name retained');
fixture();
$updater->release();
($GLOBALS['activate'])();
check(get_site_transient(Corally_WPM_GitHub_Updater::CACHE) === false, 'activation clears release cache');
check(get_option('corally_suppress_deprecations') === 1, 'activation preserves settings');

$GLOBALS['plugins'] = ['sample/sample.php' => ['Version' => '1.0.0']];
$update_request = new class {
    public function get_json_params() { return ['plugin' => 'sample/sample.php']; }
};
foreach ([false, null, [], ['other/plugin.php' => []]] as $bulk) {
    $GLOBALS['bulk_result'] = $bulk;
    check(corally_wpdev_plugin_update($update_request)->status === 500, 'incomplete upgrade cannot report success');
}
$GLOBALS['bulk_result'] = ['sample/sample.php' => new WP_Error('failed', 'Download failed')];
check(corally_wpdev_plugin_update($update_request)->data['error'] === 'Download failed', 'upgrade error retained');
$GLOBALS['bulk_result'] = ['sample/sample.php' => true];
check(corally_wpdev_plugin_update($update_request)->data['ok'], 'already-current plugin remains successful');
$GLOBALS['bulk_result'] = ['sample/sample.php' => ['destination' => '/plugins/sample']];
$GLOBALS['active'] = true;
$GLOBALS['network_active'] = true;
$GLOBALS['deactivate_during_update'] = true;
$response = corally_wpdev_plugin_update($update_request);
check($response->data['reactivated'] && $GLOBALS['reactivation_network'], 'network activation restored at network scope');
$GLOBALS['active'] = true;
$GLOBALS['activation_result'] = new WP_Error('activation_failed', 'Activation failed');
check(corally_wpdev_plugin_update($update_request)->status === 500, 'failed reactivation is reported');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --legacy', $code);
check($code === 0, 'legacy migration subprocess passed');
echo "$checks checks passed.\n";
