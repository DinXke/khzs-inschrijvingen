<?php
/**
 * De app draait op een eigen adres (standaard /inschrijvingen/) met een eigen opmaak, los van het thema,
 * zodat ze ook als PWA op de gsm werkt. Hier: routes, URL's, weergave, meldingen en toegangscontrole.
 */

namespace KHZS;

defined('ABSPATH') || exit;

/** Adres van de app; in de demo het adres van de demo. */
function slug(): string
{
    return is_demo() ? demo_slug() : echte_slug();
}

function echte_slug(): string
{
    return trim(get_option('khzs_slug', 'leden'), '/') ?: 'leden';
}

function registreer_routes(): void
{
    add_rewrite_rule('^' . preg_quote(echte_slug(), '#') . '(?:/(.*))?/?$', 'index.php?khzs_pad=/$matches[1]', 'top');
    registreer_demo_route();
    // Oude adressen (/inschrijvingen/, /club/) blijven werken (doorverwijzing).
    foreach (array_diff(['inschrijvingen', 'club'], [echte_slug()]) as $oud) {
        add_rewrite_rule('^' . $oud . '(?:/(.*))?/?$', 'index.php?khzs_oud=/$matches[1]', 'top');
    }
}

add_filter('query_vars', fn($v) => array_merge($v, ['khzs_oud']));
add_action('template_redirect', function () {
    $oud = get_query_var('khzs_oud');
    if ($oud !== '' && $oud !== null) {
        $query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . wp_unslash($_SERVER['QUERY_STRING']) : '';
        $pad = trim((string) $oud, '/');
        $pad = $pad === '' || str_contains(basename($pad), '.') ? $pad : "$pad/";
        wp_safe_redirect(app_url($pad) . $query, 301);
        exit;
    }
}, 0);

/** URL binnen de app, bv. app_url('wedstrijd/5/'). Werkt ook zonder mooie permalinks. */
function app_url(string $pad = '', array $query = []): string
{
    $pad = ltrim($pad, '/');
    if (get_option('permalink_structure')) {
        $url = home_url('/' . ltrim(slug() . '/' . $pad, '/')); // slug kan leeg zijn (demo op een aparte site)
    } else {
        $url = add_query_arg(['khzs_pad' => '/' . $pad] + (is_demo() ? ['khzs_demo' => 1] : []), home_url('/'));
    }
    return $query ? add_query_arg(array_map('rawurlencode', $query), $url) : $url;
}

function asset(string $pad): string
{
    if (is_demo() && str_starts_with($pad, 'img/') && is_file(KHZS_PAD . 'assets/img/demo/' . substr($pad, 4))) {
        $pad = 'img/demo/' . substr($pad, 4); // iconen met 'DEMO' (tools/logo/maak_demo_iconen.py)
    }
    $v = @filemtime(KHZS_PAD . "assets/$pad") ?: VERSIE;
    return KHZS_URL . "assets/$pad?v=$v";
}

/** Huidig pad + query (voor 'terug'-velden), relatief binnen de app. */
function huidige_url(): string
{
    // REQUEST_URI bevat al het pad van WordPress (ook in een submap): enkel schema, host en poort van de site ervoor.
    $h = wp_parse_url(home_url('/'));
    return $h['scheme'] . '://' . $h['host'] . (isset($h['port']) ? ':' . $h['port'] : '') . wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
}

// ---------- invoer ----------

function post(string $k, string $standaard = ''): string
{
    return isset($_POST[$k]) && !is_array($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : $standaard;
}

function post_ruw(string $k): ?string
{
    return isset($_POST[$k]) && !is_array($_POST[$k]) ? (string) wp_unslash($_POST[$k]) : null;
}

function post_lijst(string $k): array
{
    return isset($_POST[$k]) && is_array($_POST[$k]) ? array_map(fn($v) => (string) wp_unslash($v), $_POST[$k]) : [];
}

function get(string $k, string $standaard = ''): string
{
    return isset($_GET[$k]) && !is_array($_GET[$k]) ? trim((string) wp_unslash($_GET[$k])) : $standaard;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// ---------- uitvoer ----------

function e($s): string
{
    return esc_html((string) $s);
}

function nonce_veld(): string
{
    return '<input type="hidden" name="_khzs" value="' . esc_attr(wp_create_nonce('khzs')) . '">';
}

/** Kort bericht voor de volgende pagina (zoals Django messages). */
function flash(string $tekst, string $soort = 'success'): void
{
    $l = flash_lezen(false);
    $l[] = [$soort, $tekst];
    flash_schrijven($l);
}

function flash_sleutel(): string
{
    if (is_user_logged_in()) {
        return 'khzs_flash_u' . get_current_user_id();
    }
    $id = $_COOKIE['khzs_f'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = md5(wp_generate_uuid4());
        if (!headers_sent()) {
            setcookie('khzs_f', $id, 0, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE['khzs_f'] = $id;
    }
    return "khzs_flash_a$id";
}

function flash_lezen(bool $wissen = true): array
{
    $l = get_transient(flash_sleutel()) ?: [];
    if ($wissen && $l) {
        delete_transient(flash_sleutel());
    }
    return $l;
}

function flash_schrijven(array $l): void
{
    set_transient(flash_sleutel(), $l, 10 * MINUTE_IN_SECONDS);
}

function naar(string $url): never
{
    wp_safe_redirect($url);
    exit;
}

/** Toont een pagina binnen de opmaak van de app. */
function toon(string $sjabloon, array $ctx = [], string $titel = 'KHZS Leden', string $mainklasse = '', int $status = 200): never
{
    status_header($status);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    $ingelogd = is_user_logged_in();
    $ctx += ['rol' => rol(), 'is_staf' => is_staf(), 'is_secretaris' => is_secretariaat(), 'is_beheerder' => heeft_rol([BEHEERDER]),
        'is_rolbeheerder' => mag_rollen_beheren(), 'pagina' => $GLOBALS['khzs_route'] ?? '', 'meldingen_flash' => flash_lezen(),
        // Melding in de app: beslissingen over eigen rolaanvragen die het lid nog niet gezien heeft.
        'rolbeslissingen' => $ingelogd ? ongelezen_rolbeslissingen(get_current_user_id()) : [],
        'ploegbeslissingen' => $ingelogd ? ongelezen_ploegbeslissingen(get_current_user_id()) : []];
    extract($ctx, EXTR_SKIP);
    $inhoud_sjabloon = sjabloon($sjabloon);
    include KHZS_PAD . 'views/_layout.php';
    exit;
}

/** 'module/naam' → modules/module/views/naam.php, anders views/naam.php. */
function sjabloon(string $naam): string
{
    if (str_contains($naam, '/')) {
        [$module, $rest] = explode('/', $naam, 2);
        return KHZS_PAD . "modules/$module/views/$rest.php";
    }
    return KHZS_PAD . "views/$naam.php";
}

function geen_toegang(string $tekst = 'Je hebt geen toegang tot deze pagina.'): never
{
    toon('fout', ['titel' => 'Geen toegang', 'tekst' => $tekst], 'Geen toegang', 'smal', 403);
}

function niet_gevonden(): never
{
    toon('fout', ['titel' => 'Niet gevonden', 'tekst' => 'Deze pagina bestaat niet (meer).'], 'Niet gevonden', 'smal', 404);
}

function vereist_login(): void
{
    if (!is_user_logged_in()) {
        naar(app_url('aanmelden/', ['next' => huidige_url()]));
    }
}

function vereist_rol(array $rollen): void
{
    vereist_login();
    if (!heeft_rol($rollen)) {
        geen_toegang();
    }
}

function vereist_post(): void
{
    if (!is_post()) {
        status_header(405);
        header('Allow: POST');
        exit('Methode niet toegelaten');
    }
}

/** Downloadt bytes als bestand. */
function download(string $bytes, string $type, string $naam, bool $inline = false): never
{
    nocache_headers();
    header("Content-Type: $type");
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $naam) . '"; filename*=UTF-8\'\'' . rawurlencode($naam));
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

// ---------- routes ----------

/** [regex, naam, functie]. De naam bepaalt welk menu-item actief is. Modules voegen routes toe via khzs_routes. */
function routes(): array
{
    return apply_filters('khzs_routes', [
        ['', 'start', 'v_start'],
        ['aanmelden', 'login', 'v_aanmelden'],
        ['afmelden', 'logout', 'v_afmelden'],
        ['gebruikers', 'gebruikers', 'v_gebruikers'],
        ['gebruikers/aanvraag/(\\d+)', 'gebruikers', 'v_rolaanvraag_behandelen'],
        ['rollen', 'mijn_rollen', 'v_mijn_rollen'],
        ['ploegen', 'ploegen', 'v_ploegen'],
        ['ploegen/(\\d+)', 'ploeg', 'v_ploeg'],
        ['ploegen/lid/(\\d+)', 'ploeg', 'v_ploeglid'],
        ['meldingen', 'meldingen', 'v_meldingen'],
        ['meldingen/abonneer', 'push_abonneer', 'v_push_abonneer'],
        ['meldingen/afmelden', 'push_afmelden', 'v_push_afmelden'],
        ['meldingen/test', 'push_test', 'v_push_test'],
        ['backup', 'backups', 'v_backups'],
        ['backup/([^/]+)', 'backup_download', 'v_backup_download'],
        ['manifest\\.webmanifest', 'manifest', 'v_manifest'],
        ['sw\\.js', 'sw', 'v_sw'],
        ['offline', 'offline', 'v_offline'],
        ['installeer', 'installeer', 'v_installeer'],
    ]);
}

/**
 * Menu: [label, pad, actief-bij (routenamen), icoon (svg-pad), zichtbaar (callable)]. Modules voegen items toe via
 * khzs_menu (met een volgorde-sleutel).
 */
function menu(): array
{
    $items = apply_filters('khzs_menu', [
        50 => ['Gebruikers', 'gebruikers/', ['gebruikers'], '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3 3-5 7-5s7 2 7 5M16 14c3 0 6 1.5 6 4.5"/>', fn() => mag_rollen_beheren()],
        45 => ['Ploegen', 'ploegen/', ['ploegen', 'ploeg'], '<circle cx="7" cy="9" r="2.5"/><circle cx="17" cy="9" r="2.5"/><circle cx="12" cy="7" r="2.5"/><path d="M2 19c0-3 2-5 5-5M22 19c0-3-2-5-5-5M7 20c0-3 2-5 5-5s5 2 5 5"/>', fn() => ziet_alle_ploegen()],
        80 => ['Meldingen', 'meldingen/', ['meldingen'], '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>', fn() => true],
    ]);
    ksort($items);
    return array_values(array_filter($items, fn($i) => ($i[4])()));
}

function verwerk_verzoek(): void
{
    $pad = get_query_var('khzs_pad');
    if ($pad === '' || $pad === null) {
        return;
    }
    $pad = trim(rawurldecode((string) $pad), '/');
    foreach (routes() as [$regex, $naam, $functie]) {
        if (preg_match('#^' . $regex . '$#', $pad, $m)) {
            $GLOBALS['khzs_route'] = $naam;
            // Routes zonder nonce: JSON (nonce in de header) en routes van modules met een eigen beveiliging (filter khzs_zonder_nonce).
            if (is_post() && !in_array($naam, apply_filters('khzs_zonder_nonce', ['push_abonneer', 'push_afmelden', 'push_test']), true)) {
                $nonce = $_POST['_khzs'] ?? '';
                // Aanmelden/registreren mag ook zonder geldige nonce van een (verlopen) anonieme sessie.
                if (!wp_verify_nonce($nonce, 'khzs') && !in_array($naam, ['login', 'registreren'], true)) {
                    geen_toegang('Je sessie is verlopen. Ga terug, vernieuw de pagina en probeer opnieuw.');
                }
            }
            call_user_func(__NAMESPACE__ . '\\' . $functie, ...array_slice($m, 1));
            exit;
        }
    }
    niet_gevonden();
}

/** JSON-verzoeken (push): nonce in de header X-KHZS-Nonce. */
function json_verzoek(): array
{
    vereist_login();
    vereist_post();
    if (!wp_verify_nonce($_SERVER['HTTP_X_KHZS_NONCE'] ?? '', 'khzs')) {
        wp_send_json(['ok' => false], 403);
    }
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/** Verborgen veld voor GET-formulieren als de site geen mooie permalinks gebruikt. */
function pad_veld(string $pad): string
{
    return get_option('permalink_structure') ? '' : '<input type="hidden" name="khzs_pad" value="/' . esc_attr(ltrim($pad, '/')) . '">';
}
