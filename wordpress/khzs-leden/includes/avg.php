<?php
/**
 * AVG (GDPR): privacyverklaring, akkoord, toestemming van de ouder, mijn gegevens downloaden, account verwijderen
 * en de automatische opkuis. Zelfde werking als inschrijvingen/avg.py in Django; de tekst staat in views/privacy.php.
 *
 * Modules haken in met:
 * - filter khzs_mijn_zwemmer ($gegevens, $zwemmer) en khzs_mijn_gegevens ($gegevens, $uid): hun gegevens in de download;
 * - filter khzs_bewaarplicht ($tot, $uid): datum (Y-m-d) tot wanneer een account bewaard moet blijven (boekhouding);
 * - action khzs_bewaartermijn_voorbij ($uid): de bewaarde gegevens van een afgesloten account wissen;
 * - filter khzs_opkuis_aantallen ($aantallen): eigen opkuis, met het aantal gewiste rijen.
 */

namespace KHZS;

defined('ABSPATH') || exit;

/** Standaardversie van de privacyverklaring; de club kan een nieuwere instellen in wp-admin. */
const PRIVACY_VERSIE = '2026-11-14';
// Bewaartermijnen voor de opkuis (zelfde als in Django). De rest blijft zolang het account of de zwemmer bestaat.
const MELDINGEN_DAGEN = 365;        // wijzigingen door de club aan een inschrijving
const AANVRAGEN_DAGEN = 365;        // behandelde rolaanvragen en afgekeurde ploegaanvragen
const PUSH_ONGEBRUIKT_DAGEN = 365;  // pushabonnementen van accounts die zo lang niet aanmeldden
const HERINNERINGEN_DAGEN = 365;    // logboek van verstuurde deadline-herinneringen
const BOEKHOUDING_JAREN = 10;       // bewaarplicht voor de boekhouding (trainersvergoedingen), na het boekjaar
const MEERDERJARIG = 18;
const TOESTEMMING_FOUT = 'Voor een zwemmer jonger dan 18 jaar is de toestemming van een ouder of voogd nodig.';

/** Gegevens van de club voor de privacyverklaring (Instellingen → KHZS Leden). */
function privacy(): array
{
    return [
        'verantwoordelijke' => get_option('khzs_privacy_verantwoordelijke', '') ?: club()['naam'],
        'adres' => get_option('khzs_privacy_adres', ''),
        'ondernemingsnummer' => get_option('khzs_privacy_ondernemingsnummer', ''),
        'email' => get_option('khzs_privacy_email', ''),
        'versie' => privacy_versie(),
        'extra' => get_option('khzs_privacy_extra', ''),
    ];
}

function privacy_versie(): string
{
    return get_option('khzs_privacy_versie', '') ?: PRIVACY_VERSIE;
}

function minderjarig(?string $geboortedatum): bool
{
    $d = dag($geboortedatum);
    return $d && $d->diff(vandaag())->y < MEERDERJARIG && $d <= vandaag();
}

// ---------- akkoord en toestemming ----------

function privacy_akkoord(int $uid): void
{
    update_user_meta($uid, 'khzs_privacy_akkoord', nu_sql());
    update_user_meta($uid, 'khzs_privacy_versie', privacy_versie());
}

/** Moet het aangemelde account de (nieuwe versie van de) privacyverklaring nog lezen? */
function privacy_lezen(): bool
{
    return is_user_logged_in() && get_user_meta(get_current_user_id(), 'khzs_privacy_versie', true) !== privacy_versie();
}

/** Vinkje voor de toestemming van de ouder, onder een zwemmerformulier. */
function toestemming_veld(array $f): string
{
    return '<div class="veld"><label><input type="checkbox" name="toestemming" value="1"' . (isset($_POST['toestemming']) ? ' checked' : '') . '> '
        . 'Ik ben ouder of voogd van deze zwemmer (of heb hun toestemming) en ga akkoord dat de club de gegevens van deze minderjarige zwemmer verwerkt zoals beschreven in de '
        . '<a href="' . esc_url(app_url('privacy/')) . '" target="_blank">privacyverklaring</a>.</label>'
        . '<div class="help">Enkel nodig voor zwemmers jonger dan 18 jaar.</div>'
        . (isset($f['toestemming']) ? '<ul class="errorlist"><li>' . e($f['toestemming']) . '</li></ul>' : '') . '</div>';
}

/** Controleert de toestemming bij een nieuwe zwemmer; vult $f aan en zet $v['toestemming']. */
function controleer_toestemming(array &$v, array &$f): void
{
    $v['toestemming'] = isset($_POST['toestemming']);
    if (!isset($f['geboortedatum']) && minderjarig($v['geboortedatum'] ?? '') && !$v['toestemming']) {
        $f['toestemming'] = TOESTEMMING_FOUT;
    }
}

function geef_toestemming(int $zwemmer_id, int $uid): void
{
    db()->update(t('zwemmers'), ['toestemming_op' => nu_sql(), 'toestemming_door' => $uid], ['id' => $zwemmer_id]);
}

// ---------- inzage en overdraagbaarheid ----------

/** Alle persoonsgegevens van dit account en zijn zwemmers (recht op inzage en overdraagbaarheid). */
function mijn_gegevens(int $uid): array
{
    $u = get_userdata($uid);
    $zwemmers = [];
    $rijen = db()->get_results(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE account_id=%d OR id IN (SELECT zwemmer_id FROM "
        . t('zwemmer_accounts') . " WHERE user_id=%d) ORDER BY naam, voornaam", $uid, $uid));
    foreach ($rijen as $z) {
        $d = ['voornaam' => $z->voornaam, 'naam' => $z->naam, 'geboortedatum' => $z->geboortedatum,
            'geslacht' => ['M' => 'Man', 'F' => 'Vrouw'][$z->geslacht] ?? $z->geslacht, 'licentienummer' => $z->licentie,
            'swimrankings_id' => $z->swimrankings_id,
            'accounts' => array_values(array_map(fn($a) => ($x = get_userdata($a)) ? (volle_naam($x) ?: $x->user_login) : '', zwemmer_accounts($z))),
            'toestemming_ouder_op' => $z->toestemming_op ?? null,
            'ploegen' => array_map(fn($l) => ['ploeg' => $l->ploegnaam, 'status' => PL_STATUS[$l->status] ?? $l->status,
                'aangevraagd' => $l->aangemaakt, 'reden' => $l->reden], ploegen_van_zwemmer((int) $z->id))];
        $zwemmers[] = apply_filters('khzs_mijn_zwemmer', $d, $z);
    }
    $voorkeuren = [];
    foreach (voorkeuren() as $k => $v) {
        $voorkeuren[$v[0]] = voorkeur($uid, $k);
    }
    $data = [
        'uitgevoerd_op' => wp_date('c'),
        'verwerkingsverantwoordelijke' => privacy()['verantwoordelijke'],
        'account' => ['gebruikersnaam' => $u->user_login, 'e-mail' => $u->user_email, 'voornaam' => $u->first_name, 'naam' => $u->last_name,
            'aangemaakt' => $u->user_registered, 'laatst_aangemeld' => get_user_meta($uid, 'khzs_laatst_aangemeld', true) ?: null],
        'privacyverklaring' => ['aanvaard_op' => get_user_meta($uid, 'khzs_privacy_akkoord', true) ?: null,
            'versie' => get_user_meta($uid, 'khzs_privacy_versie', true)],
        'rollen' => array_map(fn($r) => ROLLEN[$r], rollen_van($u)),
        'penningmeester' => get_user_meta($uid, 'khzs_penningmeester', true) === '1',
        'rolaanvragen' => array_map(fn($a) => ['rol' => ROLLEN[$a->rol] ?? $a->rol, 'motivatie' => $a->motivatie,
            'status' => RA_STATUS[$a->status] ?? $a->status, 'reden' => $a->reden, 'aangemaakt' => $a->aangemaakt],
            db()->get_results(db()->prepare("SELECT * FROM " . t('rolaanvragen') . " WHERE user_id=%d ORDER BY id", $uid))),
        'meldingsvoorkeuren' => $voorkeuren,
        'toestellen_met_pushmeldingen' => array_map(fn($p) => ['toestel' => $p->toestel, 'aangemaakt' => $p->aangemaakt],
            db()->get_results(db()->prepare("SELECT toestel, aangemaakt FROM " . t('push') . " WHERE user_id=%d ORDER BY id", $uid))),
        'trainer_van_ploegen' => db()->get_col(db()->prepare("SELECT p.naam FROM " . t('ploegen') . " p JOIN " . t('ploeg_trainers')
            . " pt ON pt.ploeg_id=p.id WHERE pt.user_id=%d ORDER BY p.naam", $uid)),
        'zwemmers' => $zwemmers,
    ];
    return apply_filters('khzs_mijn_gegevens', $data, $uid);
}

// ---------- account verwijderen ----------

/** Reden waarom dit account zichzelf niet kan verwijderen, of null. */
function kan_niet_verwijderen(int $uid): ?string
{
    if (is_demo()) {
        return 'In de demo kan je je account niet verwijderen. Op de echte app kan dat wel.';
    }
    if (user_can($uid, 'manage_options') || is_multisite()) {
        return 'Een beheerder van de website verwijder je niet zelf. Vraag het aan een andere beheerder.';
    }
    if (db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . db()->posts . " WHERE post_author=%d AND post_status<>'auto-draft'
        AND post_type NOT IN ('revision', 'customize_changeset', 'oembed_cache', 'user_request')", $uid))) {
        return 'Je schreef berichten of pagina’s op de website. Vraag de club om je account te verwijderen.';
    }
    return null;
}

/** Datum (Y-m-d) tot wanneer gegevens van dit account bewaard moeten blijven (boekhouding), of null. */
function bewaarplicht_tot(int $uid): ?string
{
    $tot = apply_filters('khzs_bewaarplicht', null, $uid);
    return $tot ?: null;
}

/** [zwemmers die mee verdwijnen, zwemmers die bij een ander account blijven]. */
function gevolgen(int $uid): array
{
    $weg = $blijft = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE account_id=%d OR id IN (SELECT zwemmer_id FROM "
        . t('zwemmer_accounts') . " WHERE user_id=%d) ORDER BY naam, voornaam", $uid, $uid)) as $z) {
        if (count(zwemmer_accounts($z)) > 1) {
            $blijft[] = $z;
        } else {
            $weg[] = $z;
        }
    }
    return [$weg, $blijft];
}

/**
 * Verwijdert het account met alles wat eraan hangt; gedeelde zwemmers blijven bij het andere account
 * (verwijder_gebruiker_data). Moet een deel bewaard blijven voor de boekhouding, dan wordt het account afgesloten:
 * geen aanmelden meer, geen e-mail, enkel de naam bij de bewaarde gegevens. Geeft 'verwijderd' of 'afgesloten'.
 */
function verwijder_account(int $uid): string
{
    if ($reden = kan_niet_verwijderen($uid)) {
        throw new \RuntimeException($reden);
    }
    $tot = bewaarplicht_tot($uid);
    if (!$tot) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($uid);
        return 'verwijderd';
    }
    sluit_account($uid, $tot);
    return 'afgesloten';
}

function sluit_account(int $uid, string $tot): void
{
    $u = get_userdata($uid);
    $naam = volle_naam($u) ?: $u->display_name;
    verwijder_gebruiker_data($uid); // zwemmers, push, aanvragen, ploegen; modules stoppen hun toekomstige planning
    \WP_Session_Tokens::get_instance($uid)->destroy_all();
    $u->set_role('');
    foreach (array_keys(get_user_meta($uid)) as $k) {
        if (!in_array($k, ['first_name', 'last_name'], true)) {
            delete_user_meta($uid, $k);
        }
    }
    db()->update(db()->users, ['user_login' => "afgesloten-$uid", 'user_nicename' => "afgesloten-$uid", 'user_email' => "afgesloten-$uid@verwijderd.invalid",
        'user_url' => '', 'user_pass' => wp_hash_password(wp_generate_password(32, true, true)), 'display_name' => $naam], ['ID' => $uid]);
    update_user_meta($uid, 'khzs_afgesloten', nu_sql());
    update_user_meta($uid, 'khzs_bewaren_tot', $tot);
    zet_rollen($uid, []);
    clean_user_cache($uid);
}

function is_afgesloten(int $uid): bool
{
    return (bool) get_user_meta($uid, 'khzs_afgesloten', true);
}

// Een afgesloten account kan nooit meer aanmelden (ook niet met een oud cookie).
add_filter('authenticate', fn($u) => $u instanceof \WP_User && is_afgesloten($u->ID) ? new \WP_Error('khzs_afgesloten', 'Dit account is verwijderd.') : $u, 99);
add_filter('determine_current_user', fn($id) => $id && is_afgesloten((int) $id) ? 0 : $id, 99);
// Laatste aanmelding (voor de opkuis van pushabonnementen; WordPress houdt dat zelf niet bij).
add_action('wp_login', fn($login, $u) => update_user_meta($u->ID, 'khzs_laatst_aangemeld', nu_sql()), 10, 2);

// ---------- opkuis ----------

add_filter('khzs_taken', fn($t) => $t + ['khzs_opkuis' => '03:00']);
add_action('khzs_opkuis', __NAMESPACE__ . '\\avg_opkuis');

/** Wist gegevens waarvan de bewaartermijn voorbij is. Geeft per soort het aantal gewiste rijen. */
function avg_opkuis(): array
{
    $nu = nu_sql();
    $grens = fn(int $dagen) => wp_date('Y-m-d H:i:s', time() - $dagen * DAY_IN_SECONDS);
    $n = [];
    $n['koppelcodes'] = (int) db()->query(db()->prepare("UPDATE " . t('zwemmers') . " SET koppelcode='', koppelcode_tot=NULL WHERE koppelcode<>'' AND koppelcode_tot<%s", $nu));
    $g = $grens(AANVRAGEN_DAGEN);
    $n['rolaanvragen'] = (int) db()->query(db()->prepare("DELETE FROM " . t('rolaanvragen') . " WHERE status<>%s AND COALESCE(behandeld_op, aangemaakt)<%s", RA_OPEN, $g));
    $n['ploegaanvragen'] = (int) db()->query(db()->prepare("DELETE FROM " . t('ploegleden') . " WHERE status=%s AND COALESCE(behandeld_op, aangemaakt)<%s", PL_AFGEKEURD, $g));
    // Pushabonnementen van accounts die een jaar niet aanmeldden (zonder gekende aanmelding: volgens de datum van het abonnement).
    $g = $grens(PUSH_ONGEBRUIKT_DAGEN);
    $n['pushabonnementen'] = (int) db()->query(db()->prepare("DELETE p FROM " . t('push') . " p LEFT JOIN " . db()->usermeta
        . " m ON m.user_id=p.user_id AND m.meta_key='khzs_laatst_aangemeld' WHERE COALESCE(m.meta_value, p.aangemaakt)<%s", $g));
    // Afgesloten accounts waarvan de bewaarplicht voorbij is: nu helemaal weg.
    $n['afgesloten_accounts'] = 0;
    foreach (db()->get_col(db()->prepare("SELECT user_id FROM " . db()->usermeta . " WHERE meta_key='khzs_bewaren_tot' AND meta_value<%s", vandaag()->format('Y-m-d'))) as $uid) {
        do_action('khzs_bewaartermijn_voorbij', (int) $uid);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $uid);
        $n['afgesloten_accounts']++;
    }
    return apply_filters('khzs_opkuis_aantallen', $n);
}

// ---------- pagina's ----------

function v_privacy(): void
{
    if (is_post() && is_user_logged_in()) {
        privacy_akkoord(get_current_user_id());
        flash('Bedankt. Je hebt de privacyverklaring gelezen en aanvaard.');
        naar(app_url('privacy/mijn-gegevens/'));
    }
    toon('privacy', ['gegevens' => privacy(), 'moet_lezen' => privacy_lezen()], 'Privacy', 'smal');
}

function v_mijn_gegevens(): void
{
    vereist_login();
    $uid = get_current_user_id();
    [$weg, $blijft] = gevolgen($uid);
    toon('mijn_gegevens', ['akkoord' => get_user_meta($uid, 'khzs_privacy_akkoord', true), 'akkoord_versie' => get_user_meta($uid, 'khzs_privacy_versie', true),
        'versie' => privacy_versie(), 'weg' => $weg, 'blijft' => $blijft, 'geblokkeerd' => kan_niet_verwijderen($uid),
        'bewaren_tot' => bewaarplicht_tot($uid)], 'Mijn gegevens', 'smal');
}

function v_mijn_gegevens_download(): void
{
    vereist_login();
    download(wp_json_encode(mijn_gegevens(get_current_user_id()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'application/json; charset=utf-8', 'khzs-mijn-gegevens-' . wp_date('Ymd') . '.json');
}

function v_account_verwijderen(): void
{
    vereist_login();
    vereist_post();
    $u = wp_get_current_user();
    if (post('bevestig') !== 'VERWIJDER' || !wp_check_password(post_ruw('wachtwoord') ?? '', $u->user_pass, $u->ID)) {
        flash('Typ VERWIJDER en je wachtwoord om te bevestigen.', 'error');
        naar(app_url('privacy/mijn-gegevens/'));
    }
    try {
        $hoe = verwijder_account($u->ID);
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
        naar(app_url('privacy/mijn-gegevens/'));
    }
    wp_clear_auth_cookie();
    wp_set_current_user(0);
    flash($hoe === 'afgesloten' ? 'Je account is afgesloten en je gegevens zijn verwijderd. Je naam, uren en vergoedingen blijven bewaard voor de boekhouding zolang de wet dat vraagt.'
        : 'Je account en je gegevens zijn verwijderd.');
    naar(app_url('aanmelden/'));
}

add_filter('khzs_routes', fn($r) => array_merge($r, [
    ['privacy', 'privacy', 'v_privacy'],
    ['privacy/mijn-gegevens', 'mijn_gegevens', 'v_mijn_gegevens'],
    ['privacy/mijn-gegevens\\.json', 'mijn_gegevens', 'v_mijn_gegevens_download'],
    ['privacy/verwijderen', 'mijn_gegevens', 'v_account_verwijderen'],
]));

// De gegevens van de app ook in de exporttool van WordPress (Extra → Persoonsgegevens exporteren).
add_filter('wp_privacy_personal_data_exporters', fn($e) => $e + ['khzs-leden' => [
    'exporter_friendly_name' => 'KHZS Leden', 'callback' => __NAMESPACE__ . '\\wp_exporteur']]);

function wp_exporteur(string $email, int $pagina = 1): array
{
    $u = get_user_by('email', $email);
    if (!$u) {
        return ['data' => [], 'done' => true];
    }
    $items = [];
    foreach (mijn_gegevens($u->ID) as $sleutel => $waarde) {
        $items[] = ['name' => $sleutel, 'value' => is_scalar($waarde) || $waarde === null ? (string) $waarde
            : wp_json_encode($waarde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }
    return ['data' => [['group_id' => 'khzs-leden', 'group_label' => 'KHZS Leden', 'item_id' => "khzs-leden-{$u->ID}", 'data' => $items]], 'done' => true];
}
