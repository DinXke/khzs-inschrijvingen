<?php
/** Kern: rollen, voorkeuren, datums en bedragen — gedeeld door alle modules. */

namespace KHZS;

defined('ABSPATH') || exit;

const ZWEMMER = 'zwemmer', TRAINER = 'trainer', SECRETARIS = 'secretaris', BEHEERDER = 'beheerder';
const ROLLEN = [ZWEMMER => 'Zwemmer', TRAINER => 'Trainer', SECRETARIS => 'Sportsecretaris', BEHEERDER => 'Beheerder'];
const SECRETARIAAT = [SECRETARIS, BEHEERDER];
const STAF = [TRAINER, SECRETARIS, BEHEERDER];

/** Pushvoorkeuren per account (user meta): sleutel => [label, standaard, enkel_staf]. Modules voegen ze toe via de filter khzs_voorkeuren. */
function voorkeuren(): array
{
    return apply_filters('khzs_voorkeuren', []);
}

// ---------- rollen ----------

/** Rol in de app. WordPress-beheerders zijn altijd beheerder; anderen volgens user meta khzs_rol. */
function rol($user = null): ?string
{
    $user = $user === null ? wp_get_current_user() : (is_numeric($user) ? get_userdata((int) $user) : $user);
    if (!$user || !$user->exists()) {
        return null;
    }
    if (user_can($user, 'manage_options')) {
        return BEHEERDER;
    }
    $r = get_user_meta($user->ID, 'khzs_rol', true);
    return isset(ROLLEN[$r]) ? $r : ZWEMMER;
}

function is_staf($user = null): bool
{
    return in_array(rol($user), STAF, true);
}

function is_secretariaat($user = null): bool
{
    return in_array(rol($user), SECRETARIAAT, true);
}

function voorkeur(int $user_id, string $sleutel): bool
{
    $v = get_user_meta($user_id, 'khzs_' . $sleutel, true);
    return $v === '' ? (bool) (voorkeuren()[$sleutel][1] ?? false) : (bool) (int) $v;
}

// ---------- datums ----------

function vandaag(): \DateTimeImmutable
{
    // Filter khzs_vandaag: enkel voor tests (een andere datum simuleren).
    $d = apply_filters('khzs_vandaag', null);
    return $d ? new \DateTimeImmutable($d, wp_timezone()) : new \DateTimeImmutable('today', wp_timezone());
}

function dag(?string $s): ?\DateTimeImmutable
{
    if (!$s || str_starts_with($s, '0000')) {
        return null;
    }
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10), wp_timezone());
    return $d ?: null;
}

const DAGEN_KORT = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
const DAGEN = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
const MAANDEN_KORT = ['', 'Jan', 'Feb', 'Mrt', 'Apr', 'Mei', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
const MAANDEN = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

/** Datum in het Nederlands met Django-achtige codes: D l j M F Y y m d H i. */
function datum($d, string $fmt): string
{
    if (is_string($d)) {
        $d = strlen($d) > 10 ? date_create_immutable($d, wp_timezone()) : dag($d);
    }
    if (!$d) {
        return '';
    }
    $uit = '';
    foreach (str_split($fmt) as $c) {
        $uit .= match ($c) {
            'D' => DAGEN_KORT[(int) $d->format('w')],
            'l' => DAGEN[(int) $d->format('w')],
            'M' => MAANDEN_KORT[(int) $d->format('n')],
            'F' => MAANDEN[(int) $d->format('n')],
            'j', 'Y', 'y', 'm', 'd', 'H', 'i', 'n' => $d->format($c),
            default => $c,
        };
    }
    return $uit;
}


function euro($cent): string
{
    return '€ ' . number_format(((int) $cent) / 100, 2, ',', '');
}

// ---------- modules ----------

/** Beschikbare modules: id => [naam, beschrijving]. */
function modules(): array
{
    return [
        'inschrijvingen' => ['Inschrijvingen', 'Wedstrijdinschrijvingen: Lenex-uitnodigingen, inschrijven, nakijken, export naar Lenex en Excel.'],
        'trainers' => ['Trainers', 'Trainingsrooster, wie geeft welke training, afmelden en vervangen, gepresteerde uren en verloning.'],
    ];
}

function module_actief(string $id): bool
{
    $aan = get_option('khzs_modules', null);
    return $aan === null ? isset(modules()[$id]) : in_array($id, (array) $aan, true);
}

// ---------- penningmeester ----------

/** Penningmeester (los van de app-rol, bv. een ouder): ziet tarieven en verloning. Beheerders altijd. */
function is_penningmeester($user = null): bool
{
    $user = $user === null ? wp_get_current_user() : (is_numeric($user) ? get_userdata((int) $user) : $user);
    if (!$user || !$user->exists()) {
        return false;
    }
    return rol($user) === BEHEERDER || get_user_meta($user->ID, 'khzs_penningmeester', true) === '1';
}

function club(): array
{
    return [
        'naam' => get_option('khzs_club_naam', 'Hasseltse Zwemvereniging Spartacus'),
        'code' => get_option('khzs_club_code', 'KHZS'),
        'nation' => 'BEL',
        'webshop' => (string) get_option('khzs_webshop', 'https://www.robsport.nl/nl/796-hzs-hasselt'),
    ];
}

function volle_naam(\WP_User $u): string
{
    return trim($u->first_name . ' ' . $u->last_name);
}

function rij(string $tabel, int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t($tabel) . " WHERE id=%d", $id)) ?: null;
}

// ---------- naam van de app ----------

/** Naam op het beginscherm en in de titel van het tabblad (instelbaar in wp-admin). */
function app_naam(): string
{
    return get_option('khzs_app_naam', '') ?: 'KHZS Leden';
}

function app_kort(): string
{
    return get_option('khzs_app_kort', '') ?: 'KHZS Leden';
}

function app_titel(): string
{
    return get_option('khzs_app_titel', '') ?: 'KHZS Leden';
}
