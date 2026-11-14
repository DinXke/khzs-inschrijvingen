<?php
/**
 * Demo van de app op een eigen adres (standaard /leden-demo/).
 *
 * De demo draait op een aparte WordPress-installatie, los van de site van de club (zie wordpress/demo/). Ze staat enkel
 * aan met de constante KHZS_DEMO in wp-config.php; op een gewone site wordt ze nooit vanzelf actief. Met KHZS_DEMO_ENKEL
 * verwijzen de rest van de site en /leden/ door naar de demo. Ook dan blijft de demo afgeschermd zoals hieronder.
 *
 * Hoe de demo gescheiden blijft van de echte gegevens:
 * - Tabellen van de app: t() geeft in de demo wp_khzsdemo_… in plaats van wp_khzs_….
 * - Accounts: in de demo wijzen $wpdb->users en $wpdb->usermeta naar wp_khzsdemo_users en wp_khzsdemo_usermeta.
 *   Echte accounts bestaan in de demo dus niet, en demo-accounts niet op de rest van de site. Demo-id's beginnen bij
 *   DEMO_EERSTE_ID, zodat ze nooit samenvallen met een echt account (bv. in een gedeelde objectcache).
 * - Aanmelden: in de demo stuurt WordPress geen echte aanmeldcookies. De demo heeft een eigen cookie (khzs_demo_sessie)
 *   die enkel binnen /leden-demo/ meegestuurd wordt. Een demo-account komt zo nooit in wp-admin of in de echte app.
 * - Privémap, databaseversie: eigen opties (khzs_demo_…).
 * - Geen PWA (geen manifest, service worker of installatiemelding), geen pushmeldingen en geen mails.
 * - Zelf registreren en een backup terugzetten kunnen niet in de demo (zo komen er geen echte gegevens in).
 *
 * De demodata staan in includes/demo-data.php. Terugzetten naar de begintoestand: elke nacht (khzs_demo_reset),
 * met de knop in de demobalk (beheerders in de demo) en in wp-admin (Instellingen → KHZS Leden).
 */

namespace KHZS;

defined('ABSPATH') || exit;

const DEMO_PREFIX = 'khzsdemo_';
const DEMO_EERSTE_ID = 900000001;
const DEMO_COOKIE = 'khzs_demo_sessie';
/** Cachegroepen met accounts: in de demo enkel in het geheugen (niet in een gedeelde objectcache). */
const DEMO_CACHEGROEPEN = ['users', 'userlogins', 'useremail', 'userslugs', 'user_meta', 'user-queries', 'user_meta_query'];

function is_demo(): bool
{
    return !empty($GLOBALS['khzs_demo']);
}

/** Staat de demo aan? Enkel met define('KHZS_DEMO', true) in wp-config.php (filter khzs_demo_aan: enkel voor tests). */
function demo_aan(): bool
{
    return (bool) apply_filters('khzs_demo_aan', defined('KHZS_DEMO') && KHZS_DEMO);
}

/** Is de hele site een demo (aparte installatie)? Dan verwijst alles door naar de demo. */
function demo_enkel(): bool
{
    return demo_aan() && defined('KHZS_DEMO_ENKEL') && KHZS_DEMO_ENKEL;
}

/** Versie van de code van de demo: verandert ze (update van de plugin), dan wordt de demo opnieuw opgebouwd. */
function demo_code_versie(): string
{
    return VERSIE . '|' . DB_VERSIE . '|' . md5_file(KHZS_PAD . 'includes/demo-data.php');
}

function demo_slug(): string
{
    return trim((string) get_option('khzs_demo_slug', 'leden-demo'), '/') ?: 'leden-demo';
}

/** Tabel voor accounts in de demo (zelfde prefix als wp_users, ook op multisite). */
function demo_tabel(string $naam): string
{
    global $wpdb;
    return $wpdb->base_prefix . DEMO_PREFIX . $naam;
}

function registreer_demo_route(): void
{
    if (demo_aan()) {
        add_rewrite_rule('^' . preg_quote(demo_slug(), '#') . '(?:/(.*))?/?$', 'index.php?khzs_pad=/$matches[1]&khzs_demo=1', 'top');
    }
}

add_filter('query_vars', fn($v) => array_merge($v, ['khzs_demo']));
// Een oude rewrite-regel voor de demo terwijl ze uitstaat: niet gevonden (nooit de echte app op het adres van de demo).
add_filter('request', fn($q) => !empty($q['khzs_demo']) && !is_demo() ? ['error' => '404'] : $q);

/** Is dit verzoek voor de demo? Enkel gewone pagina's, nooit wp-admin, cron, REST of XML-RPC. */
function demo_verzoek(): bool
{
    if (!demo_aan() || is_admin() || wp_doing_cron() || wp_doing_ajax() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        || (defined('WP_CLI') && WP_CLI)) {
        return false;
    }
    $pad = rawurldecode((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $basis = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    if (preg_match('#^' . preg_quote($basis . '/' . demo_slug(), '#') . '(/|$)#', $pad)) {
        return true;
    }
    // Zonder mooie permalinks: ?khzs_pad=…&khzs_demo=1
    return !get_option('permalink_structure') && !empty($_GET['khzs_demo']) && isset($_GET['khzs_pad']);
}

/** Schakelt over naar de demo: eigen tabellen, eigen accounts. */
function demo_start(): void
{
    global $wpdb;
    if (is_demo()) {
        return;
    }
    $GLOBALS['khzs_demo_echt'] = [$wpdb->users, $wpdb->usermeta];
    $GLOBALS['khzs_demo'] = true;
    $wpdb->users = demo_tabel('users');
    $wpdb->usermeta = demo_tabel('usermeta');
    if (!get_option('khzs_demo_prive_map')) {
        // Eigen privémap met een herkenbare naam (demo_map_leeg wist enkel daarin).
        update_option('khzs_demo_prive_map', 'khzs-demo-prive-' . bin2hex(random_bytes(8)), false); // nog geen wp_generate_password zo vroeg
    }
    wp_cache_add_non_persistent_groups(DEMO_CACHEGROEPEN);
    demo_cache_leeg();
    $GLOBALS['current_user'] = null;
}

/** Terug naar de echte gegevens (na het terugzetten vanuit WP-Cron of wp-admin). */
function demo_stop(): void
{
    global $wpdb;
    if (!is_demo()) {
        return;
    }
    [$wpdb->users, $wpdb->usermeta] = $GLOBALS['khzs_demo_echt'];
    $GLOBALS['khzs_demo'] = false;
    demo_cache_leeg();
    $GLOBALS['current_user'] = null;
}

function demo_cache_leeg(): void
{
    if (function_exists('wp_cache_flush_runtime')) {
        wp_cache_flush_runtime();
    } else {
        foreach (DEMO_CACHEGROEPEN as $g) {
            wp_cache_flush_group($g);
        }
    }
}

/** Veiligheidsnet: nooit iets wissen buiten de demotabellen. */
function demo_controleer(): void
{
    if (!is_demo() || !str_contains(db()->users, DEMO_PREFIX) || !str_contains(db()->usermeta, DEMO_PREFIX)
        || !str_contains(t('zwemmers'), DEMO_PREFIX)) {
        throw new \RuntimeException('Niet in de demo: terugzetten geweigerd.');
    }
}

// ---------- opties: in de demo eigen waarden ----------

/** Opties die in de demo een eigen waarde hebben: echte naam => naam in de demo. */
const DEMO_OPTIES = ['khzs_prive_map' => 'khzs_demo_prive_map', 'khzs_db_versie' => 'khzs_demo_db_versie'];

foreach (DEMO_OPTIES as $echt => $demo) {
    // '' in plaats van false, anders valt WordPress terug op de echte waarde.
    add_filter("pre_option_$echt", fn($pre) => is_demo() ? (string) get_option($demo, '') : $pre);
    add_filter("pre_update_option_$echt", function ($nieuw, $oud) use ($demo) {
        if (!is_demo()) {
            return $nieuw;
        }
        update_option($demo, $nieuw, false);
        return $oud; // de echte optie blijft ongewijzigd
    }, 10, 2);
}
add_filter('pre_option_khzs_modules', fn($pre) => is_demo() ? array_keys(modules()) : $pre); // alle modules
add_filter('pre_option_khzs_registratie', fn($pre) => is_demo() ? '0' : $pre);
add_filter('pre_option_khzs_pwa_melding', fn($pre) => is_demo() ? '0' : $pre);

// ---------- aanmelden met een eigen cookie ----------

function demo_cookie_pad(): string
{
    return get_option('permalink_structure') ? (wp_parse_url(home_url('/' . demo_slug() . '/'), PHP_URL_PATH) ?: '/') : (COOKIEPATH ?: '/');
}

/** Echte aanmeldcookies negeren; het demo-cookie doet dienst als 'logged in'-cookie. */
function demo_cookies(): void
{
    foreach (['AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE'] as $c) {
        if (defined($c)) {
            unset($_COOKIE[constant($c)]);
        }
    }
    if (defined('LOGGED_IN_COOKIE') && !empty($_COOKIE[DEMO_COOKIE])) {
        $_COOKIE[LOGGED_IN_COOKIE] = $_COOKIE[DEMO_COOKIE];
    }
    add_filter('send_auth_cookies', '__return_false', 99);
    add_action('set_logged_in_cookie', function ($cookie, $expire) {
        if (!headers_sent()) {
            setcookie(DEMO_COOKIE, $cookie, $expire, demo_cookie_pad(), COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE[DEMO_COOKIE] = $cookie;
    }, 10, 2);
    add_action('clear_auth_cookie', function () {
        if (!headers_sent()) {
            setcookie(DEMO_COOKIE, ' ', time() - YEAR_IN_SECONDS, demo_cookie_pad(), COOKIE_DOMAIN, is_ssl(), true);
        }
        unset($_COOKIE[DEMO_COOKIE]);
    });
    // Geen mails uit de demo (demo-adressen eindigen ook op .invalid).
    add_filter('pre_wp_mail', '__return_false', 99);
}

if (demo_verzoek()) {
    demo_start();
    demo_cookies();
    // Eerste bezoek of na een update van de plugin: tabellen maken en opnieuw vullen, zodat de demodata altijd klopt.
    add_action('plugins_loaded', function () {
        if (!get_option('khzs_demo_gevuld') || get_option('khzs_demo_code') !== demo_code_versie()) {
            demo_terugzetten();
        }
    }, 20);
}

// Aparte demo-installatie: de rest van de site en de gewone app verwijzen door naar de demo.
add_action('template_redirect', function () {
    if (!demo_enkel() || is_demo()) {
        return;
    }
    $pad = get_query_var('khzs_pad');
    if (($pad !== '' && $pad !== null) || is_front_page() || is_home() || is_404()) {
        wp_safe_redirect(home_url('/' . demo_slug() . '/'), 302);
        exit;
    }
}, -1);

// ---------- routes in de demo ----------

add_filter('khzs_routes', function ($routes) {
    if (!is_demo()) {
        return $routes;
    }
    $weg = ['manifest', 'sw', 'offline', 'installeer', 'push_abonneer', 'push_afmelden', 'push_test', 'registreren'];
    $routes = array_values(array_filter($routes, fn($r) => !in_array($r[1], $weg, true)));
    foreach ($routes as &$r) {
        if ($r[1] === 'backups') {
            $r[2] = 'v_demo_backups';
        }
    }
    unset($r);
    $routes[] = ['demo/terugzetten', 'demo_terugzetten', 'v_demo_terugzetten'];
    return $routes;
}, 99);

/** Backup in de demo: maken en downloaden mag, terugzetten niet (zo komen er geen echte gegevens in de demo). */
function v_demo_backups(): void
{
    if (is_post() && post('actie') === 'herstel') {
        vereist_rol([BEHEERDER]);
        flash('Een backup terugzetten kan niet in de demo. Gebruik ‘Demo terugzetten’ om opnieuw te beginnen.', 'error');
        naar(app_url('backup/'));
    }
    v_backups();
}

function v_demo_terugzetten(): void
{
    vereist_rol([BEHEERDER]);
    vereist_post();
    demo_terugzetten();
    // De sessie is gewist: opnieuw aanmelden.
    wp_clear_auth_cookie();
    $GLOBALS['current_user'] = null;
    flash('De demo staat terug in de begintoestand. Meld je opnieuw aan met demo / demo.');
    naar(app_url('aanmelden/'));
}

// ---------- terugzetten ----------

/** Maakt de accounttabellen van de demo als ze nog niet bestaan (zelfde structuur als die van WordPress). */
function demo_accounttabellen(): void
{
    [$users, $usermeta] = $GLOBALS['khzs_demo_echt'];
    foreach ([demo_tabel('users') => $users, demo_tabel('usermeta') => $usermeta] as $demo => $echt) {
        if (db()->get_var(db()->prepare('SHOW TABLES LIKE %s', $demo)) !== $demo) {
            db()->query("CREATE TABLE `$demo` LIKE `$echt`");
        }
    }
}

/** Zet de demo terug naar de begintoestand: alle demotabellen leeg en opnieuw vullen. Werkt ook buiten de demo (cron). */
function demo_terugzetten(): void
{
    $was = is_demo();
    demo_start();
    try {
        if (get_option('khzs_db_versie') !== DB_VERSIE) {
            installeer_db(); // in de demo: enkel de demotabellen
        }
        demo_accounttabellen();
        demo_controleer();
        foreach (alle_tabellen() as $tab) {
            db()->query('TRUNCATE TABLE ' . t($tab));
        }
        db()->query('TRUNCATE TABLE ' . db()->users);
        db()->query('TRUNCATE TABLE ' . db()->usermeta);
        db()->query('ALTER TABLE ' . db()->users . ' AUTO_INCREMENT=' . DEMO_EERSTE_ID);
        demo_map_leeg();
        demo_cache_leeg();
        // Ook vanuit WP-Cron of wp-admin, en als een module op de echte site uitstaat.
        foreach (['inschrijvingen/regels', 'inschrijvingen/data', 'inschrijvingen/lenex', 'trainers/regels'] as $f) {
            require_once KHZS_PAD . "modules/$f.php";
        }
        require_once KHZS_PAD . 'includes/demo-data.php';
        demo_vul();
        update_option('khzs_demo_gevuld', time(), false);
        update_option('khzs_demo_code', demo_code_versie(), false);
    } finally {
        demo_cache_leeg();
        if (!$was) {
            demo_stop();
        }
    }
}

/** Bestanden van de demo (Lenex, bijlagen, backups) wissen. */
function demo_map_leeg(): void
{
    $map = prive_map();
    if (!str_contains(basename($map), 'khzs-demo-prive-')) {
        return; // nooit de echte privémap
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($map, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } elseif (!in_array($f->getFilename(), ['.htaccess', 'index.php'], true) || $f->getPath() !== $map) {
            @unlink($f->getPathname());
        }
    }
}

// ---------- elke nacht terugzetten ----------

add_filter('khzs_taken', fn($t) => demo_aan() ? $t + ['khzs_demo_reset' => '03:30'] : $t);
add_action('khzs_demo_reset', function () {
    if (demo_aan()) {
        demo_terugzetten();
    }
});

// Demo aan- of uitgezet (constante gewijzigd): rewrite-regel en nachtelijke taak bijwerken.
add_action('wp_loaded', function () {
    $regels = get_option('rewrite_rules');
    $heeft = is_array($regels) && isset($regels['^' . preg_quote(demo_slug(), '#') . '(?:/(.*))?/?$']);
    if (is_array($regels) && $heeft !== demo_aan()) {
        delete_option('rewrite_rules'); // WordPress bouwt ze bij het volgende verzoek opnieuw op
    }
    if (!demo_aan() && wp_next_scheduled('khzs_demo_reset')) {
        wp_clear_scheduled_hook('khzs_demo_reset');
    }
});

// ---------- wp-admin ----------

add_action('khzs_admin_onder', function () {
    if (!demo_aan()) {
        return;
    }
    $gevuld = (int) get_option('khzs_demo_gevuld');
    ?>
    <h2>Demo</h2>
    <p>Deze site draait de demo (<code>KHZS_DEMO</code> in wp-config.php), met verzonnen gegevens en een account <b>demo</b> / <b>demo</b> met alle rechten.
      De demo staat elke nacht om 03:30 en na elke update van de plugin terug in de begintoestand.</p>
    <p><a class="button" href="<?= esc_url(home_url('/' . demo_slug() . '/')) ?>" target="_blank">Open de demo ↗</a>
      Aanmelden met <code>demo</code> / <code>demo</code>.
      <?= $gevuld ? 'Laatst teruggezet: ' . esc_html(wp_date('j/m/Y H:i', $gevuld)) . '.' : 'Nog niet aangemaakt.' ?>
      Volgende keer: <?= esc_html(($t = wp_next_scheduled('khzs_demo_reset')) ? wp_date('j/m/Y H:i', $t) : 'niet gepland') ?>.</p>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
      <input type="hidden" name="action" value="khzs_demo_terugzetten"><?php wp_nonce_field('khzs_demo_terugzetten'); ?>
      <?php submit_button('Demo nu terugzetten', 'secondary', 'submit', false); ?>
    </form>
    <?php
});

add_action('admin_post_khzs_demo_terugzetten', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Geen toegang', 403);
    }
    check_admin_referer('khzs_demo_terugzetten');
    demo_terugzetten();
    wp_safe_redirect(admin_url('options-general.php?page=khzs&khzs_demo=terug'));
    exit;
});

add_action('admin_notices', function () {
    if (($_GET['page'] ?? '') === 'khzs' && ($_GET['khzs_demo'] ?? '') === 'terug') {
        echo '<div class="notice notice-success is-dismissible"><p>De demo staat terug in de begintoestand.</p></div>';
    }
});
