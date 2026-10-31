<?php
/**
 * AI crawler counter (1.6.0). Counts requests from AI crawlers and AI
 * assistants fetching pages on a user's behalf (GPTBot, ChatGPT-User,
 * ClaudeBot, Claude-User, PerplexityBot, Perplexity-User …) per day, bot, path
 * and HTTP status. These clients do not run JavaScript, so page-tag analytics
 * never sees them. Corally reads the counts via GET /corally/v1/ai-bot-hits.
 *
 * Human visitors cost one stripos loop and no database queries. Pages served
 * from a full-page cache never reach PHP and are not counted.
 * Kill switch: POST /corally/v1/flags {"ai_bot_counter": false}.
 * Data: table {prefix}corally_aibot_hits, rows older than 60 days are pruned.
 * Corally side: MarketingCore AiBotRegistry — keep the token lists in sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// v1.6.0 — TEKOALYROBOTTIEN LASKURI (AI crawler counter)
//
// Tekoalyrobotit (GPTBot, ClaudeBot, PerplexityBot …) ja kayttajan puolesta
// tehdyt haut (ChatGPT-User, Claude-User, Perplexity-User …) eivat aja
// JavaScriptia, joten Corallyn kavijaseuranta ei nae niita. Tama laskee ne
// palvelimella: yksi rivi per (paiva, robotti, polku, HTTP-status), hits-laskuri.
// Ihmiskavijoille kustannus on yksi stripos-silmukka — ei kantakutsuja.
//
// Corally hakee rivit GET /corally/v1/ai-bot-hits (bin/cake mkt_ai_bots_sync).
// Robottien luokittelu (operaattori, koulutus/haku/kayttaja) tehdaan Corallyssa
// (MarketingCore\Service\Tracking\AiBotRegistry) — taalla vain tunniste.
// Rajoitus: valimuistista (WP Rocket, nginx, Cloudflare) tarjoiltu sivu ei
// kaynnista PHP:ta, joten luvut ovat valimuistisivustoilla alaraja.
// Kill switch: optio corally_aibot_disabled = 1 (flags: ai_bot_counter).
// Taulu {prefix}corally_aibot_hits, siivous > 60 pv.
// ═════════════════════════════════════════════════════════════════════════

/** UA-tunnisteet. Tarkempi ennen yleisempaa. Paivita yhdessa AiBotRegistryn kanssa. */
function corally_aibot_tokens() {
    return [
        'ChatGPT-User', 'OAI-SearchBot', 'GPTBot',
        'Claude-User', 'Claude-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai',
        'Perplexity-User', 'PerplexityBot',
        'MistralAI-User', 'MistralAI-Index',
        'Meta-ExternalFetcher', 'Meta-ExternalAgent', 'FacebookBot',
        'Google-CloudVertexBot', 'Gemini-Deep-Research',
        'DuckAssistBot', 'Applebot', 'Amazonbot', 'Bytespider', 'CCBot',
        'cohere-ai', 'cohere-training-data-crawler', 'YouBot', 'PhindBot',
        'Diffbot', 'AI2Bot', 'Timpibot', 'ImagesiftBot', 'Omgilibot', 'PanguBot',
        'DeepSeekBot', 'xAI-Bot',
    ];
}

/** Palauta robotin tunniste tai '' (ihminen / muu). */
function corally_aibot_match() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    // Allekirjoitetut agenttiselaimet (Web Bot Auth): UA nayttaa Chromelta.
    $sig = isset($_SERVER['HTTP_SIGNATURE_AGENT']) ? strtolower((string) $_SERVER['HTTP_SIGNATURE_AGENT']) : '';
    if ($sig !== '' && strpos($sig, 'chatgpt.com') !== false) {
        return 'ChatGPT-Agent';
    }
    if ($ua === '' || stripos($ua, 'Corally') !== false) {
        return '';
    }
    foreach (corally_aibot_tokens() as $t) {
        if (stripos($ua, $t) !== false) {
            return $t;
        }
    }
    return '';
}

function corally_aibot_table() {
    global $wpdb;
    return $wpdb->prefix . 'corally_aibot_hits';
}

function corally_aibot_ensure_table() {
    if (get_option('corally_aibot_db', '') === '1') {
        return true;
    }
    global $wpdb;
    $t = corally_aibot_table();
    $charset = $wpdb->get_charset_collate();
    $ok = $wpdb->query("CREATE TABLE IF NOT EXISTS `{$t}` (
        `day` DATE NOT NULL,
        `bot` VARCHAR(40) NOT NULL,
        `path` VARCHAR(191) NOT NULL,
        `status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `hits` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_ip` VARCHAR(45) NOT NULL DEFAULT '',
        `last_seen` DATETIME NOT NULL,
        PRIMARY KEY (`day`, `bot`, `path`, `status`),
        KEY `idx_last_seen` (`last_seen`)
    ) {$charset}");
    if ($ok === false) {
        return false;
    }
    update_option('corally_aibot_db', '1', true);
    return true;
}

/** Kirjaa osuma vasta shutdownissa, jotta HTTP-status on tiedossa. */
function corally_aibot_record($bot) {
    if (!corally_aibot_ensure_table()) {
        return;
    }
    global $wpdb;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) strtok($uri, '?#');
    if ($path === '') {
        $path = '/';
    }
    if (function_exists('mb_substr')) {
        $path = mb_substr($path, 0, 191);
    } else {
        $path = substr($path, 0, 191);
    }
    $status = (int) http_response_code();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
    $now = gmdate('Y-m-d H:i:s');
    $t = corally_aibot_table();
    $wpdb->query($wpdb->prepare(
        "INSERT INTO `{$t}` (`day`, `bot`, `path`, `status`, `hits`, `last_ip`, `last_seen`)
         VALUES (%s, %s, %s, %d, 1, %s, %s)
         ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `last_ip` = VALUES(`last_ip`), `last_seen` = VALUES(`last_seen`)",
        gmdate('Y-m-d'), $bot, $path, $status, $ip, $now
    ));
    // Siivous noin joka 500. osumalla — ei omaa cronia.
    if (mt_rand(1, 500) === 1) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$t}` WHERE `day` < %s", gmdate('Y-m-d', time() - 60 * 86400)));
    }
}

/** Rekisteroi laskuri tahan pyyntoon (kutsutaan bootstrapista). */
function corally_aibot_boot() {
    if ((int) get_option('corally_aibot_disabled', 0) === 1) {
        return '';
    }
    $bot = corally_aibot_match();
    if ($bot !== '') {
        add_action('shutdown', function () use ($bot) {
            corally_aibot_record($bot);
        }, 1);
    }
    return $bot;
}

/**
 * GET /wp-json/corally/v1/ai-bot-hits?since=YYYY-MM-DD&offset=0&limit=5000
 * Paivarivit since-paivasta alkaen (UTC). Corally upserttaa — hits on paivan
 * kumulatiivinen luku, joten saman paivan voi hakea monta kertaa.
 */
function corally_aibot_rest_hits($request) {
    global $wpdb;
    $since = (string) $request->get_param('since');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
        $since = gmdate('Y-m-d', time() - 7 * 86400);
    }
    $limit = max(1, min(5000, (int) ($request->get_param('limit') ?: 5000)));
    $offset = max(0, (int) $request->get_param('offset'));
    $enabled = (int) get_option('corally_aibot_disabled', 0) !== 1;
    $base = [
        'version' => CORALLY_WPM_VERSION,
        'enabled' => $enabled,
        'since'   => $since,
        'tokens'  => corally_aibot_tokens(),
    ];
    if (get_option('corally_aibot_db', '') !== '1') {
        return new WP_REST_Response($base + ['rows' => [], 'has_more' => false, 'table' => false], 200);
    }
    $t = corally_aibot_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT `day`, `bot`, `path`, `status`, `hits`, `last_ip`, `last_seen`
           FROM `{$t}` WHERE `day` >= %s
          ORDER BY `day`, `bot`, `path`, `status` LIMIT %d OFFSET %d",
        $since, $limit + 1, $offset
    ), ARRAY_A);
    $rows = is_array($rows) ? $rows : [];
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    return new WP_REST_Response($base + ['rows' => $rows, 'has_more' => $hasMore, 'table' => true], 200);
}
