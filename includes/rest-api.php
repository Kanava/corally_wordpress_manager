<?php
/** REST bridge; loaded by the regular plugin bootstrap. */

if (!defined('ABSPATH')) {
    exit;
}

// v1.1.0: jos deprecated-vaimennus on paalla, laske error_reporting-tasoa niin
// ettei E_DEPRECATED / E_USER_DEPRECATED enaa kirjaudu debug.logiin. mu-pluginit
// ladataan wp_debug_mode():n JALKEEN, joten tama voittaa WP_DEBUG:n E_ALL:n taman
// pyynnon osalta. Taysin palautettava (optio 0 -> ei muutosta).
if ((int) get_option('corally_suppress_deprecations', 0) === 1) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

add_action('rest_api_init', function () {
    register_rest_route('corally/v1', '/site-info', [
        'methods'             => 'GET',
        'callback'            => 'corally_wpdev_site_info',
        'permission_callback' => 'corally_wpdev_perm',
    ]);
    register_rest_route('corally/v1', '/debug-log', [
        'methods'             => 'GET',
        'callback'            => 'corally_wpdev_debug_log',
        'permission_callback' => 'corally_wpdev_perm',
    ]);
    // v1.1.0 — rajatut KIRJOITTAVAT endpointit (auto-safe-operaatioita varten).
    register_rest_route('corally/v1', '/cache-flush', [
        'methods'             => 'POST',
        'callback'            => 'corally_wpdev_cache_flush',
        'permission_callback' => 'corally_wpdev_perm',
    ]);
    register_rest_route('corally/v1', '/flags', [
        'methods'             => 'GET',
        'callback'            => 'corally_wpdev_get_flags',
        'permission_callback' => 'corally_wpdev_perm',
    ]);
    register_rest_route('corally/v1', '/flags', [
        'methods'             => 'POST',
        'callback'            => 'corally_wpdev_set_flags',
        'permission_callback' => 'corally_wpdev_perm',
    ]);
    // v1.2.0 — mukautettu CSS (Customizerin Lisa-CSS). Vain luku + turvallinen kirjoitus.
    register_rest_route('corally/v1', '/custom-css', [
        'methods'             => 'GET',
        'callback'            => 'corally_wpdev_get_custom_css',
        'permission_callback' => 'corally_wpdev_perm',
    ]);
    register_rest_route('corally/v1', '/custom-css', [
        'methods'             => 'POST',
        'callback'            => 'corally_wpdev_set_custom_css',
        'permission_callback' => 'corally_wpdev_css_perm',
    ]);
    // v1.3.0 — plugin-paivitys (WP core REST ei osaa paivittaa -> Plugin_Upgrader).
    register_rest_route('corally/v1', '/plugin-update', [
        'methods'             => 'POST',
        'callback'            => 'corally_wpdev_plugin_update',
        'permission_callback' => 'corally_wpdev_update_perm',
    ]);
});

/** Vain yllapitajille (Application Password -kayttajalla oltava manage_options). */
function corally_wpdev_perm() {
    return current_user_can('manage_options');
}

function corally_wpdev_update_perm() {
    return corally_wpdev_perm() && current_user_can('update_plugins');
}

function corally_wpdev_css_perm() {
    return corally_wpdev_perm() && current_user_can('edit_css');
}

/** GET /wp-json/corally/v1/site-info — WP/PHP-versio, teema, saatavilla olevat paivitykset. */
function corally_wpdev_site_info() {
    global $wp_version;

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!function_exists('get_plugin_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    $core = [];
    if (function_exists('get_core_updates')) {
        $cu = get_core_updates();
        if (is_array($cu)) {
            foreach ($cu as $u) {
                if (isset($u->response) && $u->response === 'upgrade') {
                    $core[] = ['version' => isset($u->current) ? $u->current : ''];
                }
            }
        }
    }

    $pluginUpd = [];
    if (function_exists('get_plugin_updates')) {
        foreach ((array) get_plugin_updates() as $file => $p) {
            $pluginUpd[] = [
                'plugin'      => (string) $file,
                'name'        => isset($p->Name) ? $p->Name : '',
                'current'     => isset($p->Version) ? $p->Version : '',
                'new_version' => isset($p->update->new_version) ? $p->update->new_version : '',
            ];
        }
    }

    $themeUpd = [];
    if (function_exists('get_theme_updates')) {
        foreach ((array) get_theme_updates() as $stylesheet => $t) {
            $nv = '';
            if (isset($t->update) && is_array($t->update) && isset($t->update['new_version'])) {
                $nv = $t->update['new_version'];
            }
            $themeUpd[] = ['theme' => (string) $stylesheet, 'new_version' => $nv];
        }
    }

    $theme = wp_get_theme();

    return new WP_REST_Response([
        'mu_plugin_version' => CORALLY_WPM_VERSION, // Legacy field retained for existing workers.
        'plugin_version'    => CORALLY_WPM_VERSION,
        'wp_version'        => (string) $wp_version,
        'php_version'       => PHP_VERSION,
        'active_theme'      => [
            'name'    => $theme ? $theme->get('Name') : '',
            'version' => $theme ? $theme->get('Version') : '',
        ],
        'updates' => [
            'core'    => $core,
            'plugins' => $pluginUpd,
            'themes'  => $themeUpd,
            'counts'  => [
                'core'    => count($core),
                'plugins' => count($pluginUpd),
                'themes'  => count($themeUpd),
            ],
        ],
    ], 200);
}

/** GET /wp-json/corally/v1/debug-log?lines=N — debug.login viimeiset rivit (max 1000). */
function corally_wpdev_debug_log($request) {
    $lines = (int) $request->get_param('lines');
    if ($lines <= 0) {
        $lines = 200;
    }
    if ($lines > 1000) {
        $lines = 1000;
    }

    $path = '';
    if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
        $path = WP_DEBUG_LOG;
    } else {
        $path = WP_CONTENT_DIR . '/debug.log';
    }

    if (!@is_readable($path)) {
        return new WP_REST_Response([
            'exists' => false,
            'path'   => $path,
            'note'   => 'debug.log ei ole luettavissa. Varmista etta WP_DEBUG_LOG on kaytossa wp-config.php:ssa.',
            'lines'  => [],
        ], 200);
    }

    $size = (int) @filesize($path);
    $maxBytes = 262144; // lue enintaan viimeiset 256 KB
    $data = '';
    $fh = @fopen($path, 'rb');
    if ($fh) {
        if ($size > $maxBytes) {
            @fseek($fh, -$maxBytes, SEEK_END);
        }
        $data = (string) @stream_get_contents($fh, $maxBytes);
        @fclose($fh);
    }

    $allLines = preg_split('/\r\n|\n|\r/', $data);
    if (!is_array($allLines)) {
        $allLines = [];
    }
    $tail = array_slice($allLines, -$lines);
    $tail = array_values(array_filter($tail, function ($l) {
        return $l !== '';
    }));

    return new WP_REST_Response([
        'exists'    => true,
        'path'      => $path,
        'size'      => $size,
        'truncated' => ($size > $maxBytes),
        'returned'  => count($tail),
        'lines'     => $tail,
    ], 200);
}

/**
 * POST /wp-json/corally/v1/cache-flush — tyhjenna objekti-/sivuvalimuistit
 * best-effort. Kutsuu vain funktioita/luokkia jotka ovat olemassa (tunnetut
 * cache-pluginit). Palauttaa listan siita mika tyhjennettiin.
 */
function corally_wpdev_cache_flush() {
    $done = [];
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $done[] = 'object_cache';
    }
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $done[] = 'wp_rocket';
    }
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $done[] = 'w3_total_cache';
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $done[] = 'wp_super_cache';
    }
    if (function_exists('wpfc_clear_all_cache')) {
        wpfc_clear_all_cache(true);
        $done[] = 'wp_fastest_cache';
    }
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
        $done[] = 'litespeed';
    }
    if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
        autoptimizeCache::clearall();
        $done[] = 'autoptimize';
    }
    return new WP_REST_Response(['flushed' => $done, 'count' => count($done)], 200);
}

/** GET /wp-json/corally/v1/flags — palauta Corallyn hallitsemat liput. */
function corally_wpdev_get_flags() {
    return new WP_REST_Response([
        'suppress_log_deprecations' => (int) get_option('corally_suppress_deprecations', 0),
    ], 200);
}

/** POST /wp-json/corally/v1/flags — aseta lippu (nyt: suppress_log_deprecations). */
function corally_wpdev_set_flags($request) {
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = $request->get_params();
    }
    $out = [];
    if (array_key_exists('suppress_log_deprecations', $params)) {
        $v = $params['suppress_log_deprecations'] ? 1 : 0;
        update_option('corally_suppress_deprecations', $v);
        $out['suppress_log_deprecations'] = $v;
    }
    return new WP_REST_Response(['updated' => $out], 200);
}


/** GET /wp-json/corally/v1/custom-css — nykyinen Customizerin Lisa-CSS. */
function corally_wpdev_get_custom_css() {
    $css = function_exists('wp_get_custom_css') ? (string) wp_get_custom_css() : '';
    return new WP_REST_Response([
        'css'    => $css,
        'length' => strlen($css),
        'theme'  => get_stylesheet(),
    ], 200);
}

/** POST /wp-json/corally/v1/custom-css — aseta Lisa-CSS (mode: replace|append). */
function corally_wpdev_set_custom_css($request) {
    if (!function_exists('wp_update_custom_css_post')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'wp_update_custom_css_post puuttuu'], 500);
    }
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = $request->get_params();
    }
    $css  = isset($params['css']) ? (string) $params['css'] : '';
    $mode = (isset($params['mode']) && $params['mode'] === 'append') ? 'append' : 'replace';
    if ($mode === 'append') {
        $current = function_exists('wp_get_custom_css') ? (string) wp_get_custom_css() : '';
        $css = trim($current . "\n" . $css);
    }
    $post = wp_update_custom_css_post($css);
    if (is_wp_error($post)) {
        return new WP_REST_Response(['ok' => false, 'error' => $post->get_error_message()], 500);
    }
    return new WP_REST_Response(['ok' => true, 'length' => strlen($css), 'mode' => $mode], 200);
}

/**
 * POST /wp-json/corally/v1/plugin-update — paivita YKSI plugin uusimpaan versioon.
 *
 * Body: { "plugin": "akismet/akismet.php" }. WP core REST ei osaa paivittaa pluginia,
 * joten tama ajaa WP:n oman Plugin_Upgraderin. Palauttaa version ennen/jalkeen +
 * paivitys-skinnin viestit. Aktiivinen plugin palautetaan aktiiviseksi paivityksen
 * jalkeen. Edellyttaa tiedostojarjestelman kirjoitusoikeutta (FS_METHOD direct);
 * jos ei -> ok=false selkealla virheella.
 */
function corally_wpdev_plugin_update($request) {
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = $request->get_params();
    }
    $plugin = isset($params['plugin']) ? trim((string) $params['plugin']) : '';
    if ($plugin === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'plugin (pluginin tiedosto) puuttuu'], 400);
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all = get_plugins();
    if (!isset($all[$plugin])) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Pluginia ei loydy: ' . $plugin], 404);
    }
    $version_before = isset($all[$plugin]['Version']) ? (string) $all[$plugin]['Version'] : '';
    $was_active = is_plugin_active($plugin);
    $was_network_active = is_plugin_active_for_network($plugin);

    // v1.4.0 (29.9.2026): paivitys ei saa katketa kesken. Jaettu hosting katkaisee
    // PHP:n tyypillisesti 30-60 s kohdalla -> plugin jai deaktivoiduksi / sivusto
    // huoltotilaan. Nosta aikaraja ja jatka vaikka Corally sulkisi yhteyden.
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    ignore_user_abort(true);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';

    // Paivita saatavilla olevien paivitysten transientti ennen ajoa.
    wp_clean_plugins_cache(true);
    if (function_exists('wp_update_plugins')) {
        wp_update_plugins();
    }

    if (!class_exists('Plugin_Upgrader') || !class_exists('Automatic_Upgrader_Skin')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Plugin_Upgrader ei ole kaytettavissa taman WP-version paalla.'], 500);
    }

    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);

    // Turvaverkko: jos PHP kuolee kesken (fatal / aikaraja), poista huoltotila ja
    // palauta plugin aktiiviseksi jos se oli aktiivinen ja tiedosto on olemassa.
    $corally_done = false;
    register_shutdown_function(function () use (&$corally_done, $upgrader, $plugin, $was_active, $was_network_active) {
        if ($corally_done) {
            return;
        }
        if (method_exists($upgrader, 'maintenance_mode')) {
            $upgrader->maintenance_mode(false);
        }
        if ($was_active && file_exists(WP_PLUGIN_DIR . '/' . $plugin) && !is_plugin_active($plugin)) {
            activate_plugin($plugin, '', $was_network_active, true);
        }
    });

    // v1.4.0: bulk_upgrade() EI deaktivoi pluginia paivityksen ajaksi (kuten WP:n
    // oma "Paivita nyt" -nappi). upgrade() deaktivoi sen ensin ja luotti siihen etta
    // reaktivointi ehtii tapahtua -> katkennut pyynto jatti pluginin pois paalta.
    $bulk   = $upgrader->bulk_upgrade([$plugin]);
    $result = is_array($bulk) ? ($bulk[$plugin] ?? null) : $bulk;
    $corally_done = true;

    // Palauta plugin aktiiviseksi jos se oli (upgrade voi deaktivoida sen).
    $reactivated = false;
    $activation_error = null;
    if ($was_active && !is_plugin_active($plugin)) {
        $act = activate_plugin($plugin, '', $was_network_active);
        $reactivated = !is_wp_error($act);
        if (is_wp_error($act)) {
            $activation_error = $act;
        }
    }

    wp_clean_plugins_cache(true);
    $all2 = get_plugins();
    $version_after = isset($all2[$plugin]['Version']) ? (string) $all2[$plugin]['Version'] : $version_before;
    $messages = method_exists($skin, 'get_upgrade_messages') ? $skin->get_upgrade_messages() : [];

    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'ok'      => false,
            'error'   => $result->get_error_message(),
            'plugin'  => $plugin,
            'version' => $version_after,
            'messages' => $messages,
        ], 500);
    }
    // WordPress returns true when already current, an array on installation.
    if ($result !== true && !is_array($result)) {
        return new WP_REST_Response([
            'ok'       => false,
            'error'    => 'Paivitys ei onnistunut (todennakoisesti tiedostojarjestelman kirjoitusoikeudet / FS_METHOD ei ole direct).',
            'plugin'   => $plugin,
            'version'  => $version_after,
            'messages' => $messages,
        ], 500);
    }

    if ($activation_error) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => $activation_error->get_error_message(),
            'plugin' => $plugin,
            'version' => $version_after,
            'messages' => $messages,
        ], 500);
    }

    return new WP_REST_Response([
        'ok'             => true,
        'plugin'         => $plugin,
        'version_before' => $version_before,
        'version'        => $version_after,
        'was_active'     => $was_active,
        'reactivated'    => $reactivated,
        'messages'       => $messages,
    ], 200);
}
