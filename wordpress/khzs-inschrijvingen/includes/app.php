<?php
/**
 * De app draait op een eigen adres (standaard /inschrijvingen/) met een eigen opmaak, los van het thema,
 * zodat ze ook als PWA op de gsm werkt. Hier: routes, URL's, weergave, meldingen en toegangscontrole.
 */

namespace KHZS;

defined('ABSPATH') || exit;

function slug(): string
{
    return trim(get_option('khzs_slug', 'inschrijvingen'), '/') ?: 'inschrijvingen';
}

function registreer_routes(): void
{
    add_rewrite_rule('^' . preg_quote(slug(), '#') . '(?:/(.*))?/?$', 'index.php?khzs_pad=/$matches[1]', 'top');
}

/** URL binnen de app, bv. app_url('wedstrijd/5/'). Werkt ook zonder mooie permalinks. */
function app_url(string $pad = '', array $query = []): string
{
    $pad = ltrim($pad, '/');
    if (get_option('permalink_structure')) {
        $url = home_url('/' . slug() . '/' . $pad);
    } else {
        $url = add_query_arg('khzs_pad', '/' . $pad, home_url('/'));
    }
    return $query ? add_query_arg(array_map('rawurlencode', $query), $url) : $url;
}

function asset(string $pad): string
{
    $v = @filemtime(KHZS_PAD . "assets/$pad") ?: VERSIE;
    return KHZS_URL . "assets/$pad?v=$v";
}

/** Huidig pad + query (voor 'terug'-velden), relatief binnen de app. */
function huidige_url(): string
{
    return home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
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
function toon(string $sjabloon, array $ctx = [], string $titel = 'Inschrijvingen', string $mainklasse = '', int $status = 200): never
{
    status_header($status);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    $rol = rol();
    $ctx += ['rol' => $rol, 'is_staf' => in_array($rol, STAF, true), 'is_secretaris' => in_array($rol, SECRETARIAAT, true),
        'is_beheerder' => $rol === BEHEERDER, 'pagina' => $GLOBALS['khzs_route'] ?? '', 'meldingen_flash' => flash_lezen()];
    extract($ctx, EXTR_SKIP);
    $inhoud_sjabloon = KHZS_PAD . "views/$sjabloon.php";
    include KHZS_PAD . 'views/_layout.php';
    exit;
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
    if (!in_array(rol(), $rollen, true)) {
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

/** [regex, naam, functie]. De naam bepaalt welk menu-item actief is. */
function routes(): array
{
    return [
        ['', 'start', 'v_start'],
        ['aanmelden', 'login', 'v_aanmelden'],
        ['afmelden', 'logout', 'v_afmelden'],
        ['registreren', 'registreren', 'v_registreren'],
        ['wedstrijden', 'wedstrijden', 'v_wedstrijden'],
        ['wedstrijd/nieuw', 'upload', 'v_upload'],
        ['wedstrijd/(\d+)', 'wedstrijd', 'v_wedstrijd'],
        ['wedstrijd/(\d+)/inschrijven', 'inschrijven', 'v_inschrijven'],
        ['wedstrijd/(\d+)/nakijken', 'nakijken', 'v_nakijken'],
        ['wedstrijd/(\d+)/overzicht', 'overzicht', 'v_overzicht'],
        ['wedstrijd/(\d+)/finaliseren', 'finaliseren', 'v_finaliseren'],
        ['wedstrijd/(\d+)/bijlage', 'bijlage_toevoegen', 'v_bijlage_toevoegen'],
        ['bijlage/(\d+)', 'bijlage', 'v_bijlage'],
        ['bijlage/(\d+)/verwijderen', 'bijlage_verwijderen', 'v_bijlage_verwijderen'],
        ['wedstrijd/(\d+)/deelname', 'deelname', 'v_deelname'],
        ['wedstrijd/(\d+)/deelname/beoordelen', 'deelname_beoordelen', 'v_deelname_beoordelen'],
        ['wedstrijd/(\d+)/beoordelen', 'beoordelen', 'v_beoordelen'],
        ['wedstrijd/(\d+)/instellingen', 'instellingen', 'v_instellingen'],
        ['wedstrijd/(\d+)/opnieuw', 'upload', 'v_upload'],
        ['wedstrijd/(\d+)/verwijderen', 'verwijderen', 'v_verwijderen'],
        ['wedstrijd/(\d+)/export\.lxf', 'export_lxf', 'v_export_lxf'],
        ['wedstrijd/(\d+)/export\.xlsx', 'export_xlsx', 'v_export_xlsx'],
        ['zwemmers', 'zwemmers', 'v_zwemmers'],
        ['zwemmers/uitslagen', 'uitslagen', 'v_uitslagen'],
        ['zwemmers/nieuw', 'zwemmer_nieuw', 'v_zwemmer_nieuw'],
        ['zwemmers/(\d+)', 'zwemmer', 'v_zwemmer'],
        ['tijd/(\d+)/verwijderen', 'tijd_verwijderen', 'v_tijd_verwijderen'],
        ['gebruikers', 'gebruikers', 'v_gebruikers'],
        ['meldingen', 'meldingen', 'v_meldingen'],
        ['meldingen/abonneer', 'push_abonneer', 'v_push_abonneer'],
        ['meldingen/afmelden', 'push_afmelden', 'v_push_afmelden'],
        ['meldingen/test', 'push_test', 'v_push_test'],
        ['uitleg', 'uitleg', 'v_uitleg'],
        ['backup', 'backups', 'v_backups'],
        ['backup/([^/]+)', 'backup_download', 'v_backup_download'],
        ['manifest\.webmanifest', 'manifest', 'v_manifest'],
        ['sw\.js', 'sw', 'v_sw'],
        ['offline', 'offline', 'v_offline'],
    ];
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
            if (is_post() && !in_array($naam, ['push_abonneer', 'push_afmelden', 'push_test'], true)) {
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
