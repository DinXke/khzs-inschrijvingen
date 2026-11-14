<?php
/** Kern: rollen, voorkeuren, datums en bedragen — gedeeld door alle modules. */

namespace KHZS;

defined('ABSPATH') || exit;

// TRAINER is de trainer van de competitie (de sleutel 'trainer' bleef, zodat bestaande trainers niets verliezen).
// TRAINER_ZWEMSCHOOL ziet geen wedstrijdzaken, enkel trainingen, uren en de ploegen van de zwemschool.
const ZWEMMER = 'zwemmer', OUDER = 'ouder', TRAINER = 'trainer', TRAINER_ZWEMSCHOOL = 'trainer_zwemschool', SECRETARIS = 'secretaris', BESTUURDER = 'bestuurder', BEHEERDER = 'beheerder';
// Volgorde = van hoog naar laag; de eerste rol die een account heeft, is de hoofdrol.
// Een nieuwe rol: hier toevoegen, en in de groepen hieronder als ze rechten geeft (zelfde als Profiel.ROLLEN in Django).
const ROLLEN = [BEHEERDER => 'Beheerder', SECRETARIS => 'Sportsecretaris', TRAINER => 'Trainer competitie',
    TRAINER_ZWEMSCHOOL => 'Trainer zwemschool', BESTUURDER => 'Bestuurder',
    OUDER => 'Ouder', ZWEMMER => 'Zwemmer'];
const SECRETARIAAT = [SECRETARIS, BEHEERDER];
/** Wedstrijdstaf: nakijken, inschrijven voor anderen, uitslagen. Zonder de trainers van de zwemschool. */
const STAF = [TRAINER, SECRETARIS, BEHEERDER];
/** Beide soorten trainers (module Trainers, ploegen trainen). */
const TRAINERS = [TRAINER, TRAINER_ZWEMSCHOOL];
/** Wie rollen toekent, afneemt en aanvragen behandelt (scherm Gebruikers). */
const ROLBEHEER = [SECRETARIS, BESTUURDER, BEHEERDER];
/** Rollen die een lid zelf krijgt, zonder goedkeuring: ze geven enkel toegang tot de eigen zwemmers. */
const ZONDER_GOEDKEURING = [ZWEMMER, OUDER];
/** Rollen met eigen zwemmers: zij krijgen de meldingen voor zwemmers (nieuwe wedstrijd, deadline). */
const GEZIN = [ZWEMMER, OUDER];
/** Rollen die een lid kan aanvragen (beheerder wordt enkel toegekend). */
const AANVRAAGBAAR = [ZWEMMER, OUDER, TRAINER, TRAINER_ZWEMSCHOOL, SECRETARIS, BESTUURDER];
/** Wie ploegen aanmaakt, trainers aanduidt en leden indeelt. */
const PLOEGBEHEER = [SECRETARIS, BESTUURDER, BEHEERDER];
/** Wie alle ploegen (en later hun kanalen) ziet; gewone leden zien enkel de ploegen van hun zwemmers. Een trainer
 *  van de zwemschool ziet de ploegen van de zwemschool (ploegen.php). */
const ALLE_PLOEGEN = [TRAINER, SECRETARIS, BESTUURDER, BEHEERDER];

/**
 * Pushvoorkeuren per account (user meta): sleutel => [label, standaard, voor wie]. Voor wie: false = iedereen,
 * true = enkel staf, 'rolbeheer' = enkel wie rollen beheert. Modules voegen ze toe via de filter khzs_voorkeuren.
 */
function voorkeuren(): array
{
    return apply_filters('khzs_voorkeuren', [
        'push_beslissing' => ['Mijn aanvraag voor een rol of ploeg is behandeld', true, false],
        'push_rolaanvraag' => ['(bestuur) Nieuwe rolaanvraag om te behandelen', true, 'rolbeheer'],
        'push_ploegaanvraag' => ['(staf) Nieuwe ploegaanvraag om te behandelen', true, 'ploegstaf'],
    ]);
}

function voorkeur_zichtbaar(array $v, $user = null): bool
{
    return match ($v[2] ?? false) {
        'rolbeheer' => mag_rollen_beheren($user),
        'ploegstaf' => mag_ploegen_beheren($user) || (bool) eigen_getrainde_ploegen(gebruiker($user)?->ID ?? 0),
        true => is_staf($user),
        'trainer' => heeft_rol([...TRAINERS, ...SECRETARIAAT], $user),
        default => true,
    };
}

// ---------- rollen ----------

function gebruiker($user = null): ?\WP_User
{
    $user = $user === null ? wp_get_current_user() : (is_numeric($user) ? get_userdata((int) $user) : $user);
    return $user && $user->exists() ? $user : null;
}

/** Rollen in volgorde van ROLLEN, zonder onbekende. */
function gesorteerd(array $rollen): array
{
    return array_values(array_intersect(array_keys(ROLLEN), $rollen));
}

/**
 * De bewaarde rollen (user meta khzs_rollen, komma-gescheiden). Een account van vóór de rollen heeft enkel
 * khzs_rol: dat wordt dan de enige rol, zodat niemand rechten verliest. Een lege khzs_rollen betekent: nog geen rol
 * (nieuw account dat het stappenplan nog niet doorliep).
 */
function bewaarde_rollen(int $uid): array
{
    $r = gesorteerd(array_filter(explode(',', (string) get_user_meta($uid, 'khzs_rollen', true))));
    if (!$r && !metadata_exists('user', $uid, 'khzs_rollen')) {
        $oud = get_user_meta($uid, 'khzs_rol', true);
        $r = [isset(ROLLEN[$oud]) ? $oud : ZWEMMER];
    }
    return $r;
}

/** Alle rollen van een account. WordPress-beheerders zijn altijd ook beheerder. */
function rollen_van($user = null): array
{
    $user = gebruiker($user);
    if (!$user) {
        return [];
    }
    $r = bewaarde_rollen($user->ID);
    if (user_can($user, 'manage_options')) {
        $r = gesorteerd([...$r, BEHEERDER]);
    }
    return $r;
}

/** Heeft het account minstens één van deze rollen? De rechten van alle rollen tellen samen. */
function heeft_rol(array $rollen, $user = null): bool
{
    return (bool) array_intersect(rollen_van($user), $rollen);
}

/** Hoofdrol (de hoogste rol), voor weergave. Gebruik voor rechten heeft_rol(). */
function rol($user = null): ?string
{
    return rollen_van($user)[0] ?? null;
}

/** Geeft het account precies deze rollen (ook geen); khzs_rol houdt de hoofdrol bij (voor oudere code en backups). */
function zet_rollen(int $uid, array $rollen): void
{
    $rollen = gesorteerd($rollen);
    update_user_meta($uid, 'khzs_rollen', implode(',', $rollen));
    update_user_meta($uid, 'khzs_rol', $rollen[0] ?? '');
}

function is_staf($user = null): bool
{
    return heeft_rol(STAF, $user);
}

function is_trainer($user = null): bool
{
    return heeft_rol(TRAINERS, $user);
}

/**
 * Ziet geen wedstrijdzaken (menu Wedstrijden, nakijken, uitslagen): een trainer van de zwemschool die geen andere rol
 * heeft die wedstrijden nodig heeft (zwemmer, ouder of wedstrijdstaf). Wie ook zwemt of kinderen heeft, ziet ze wel.
 */
function zonder_wedstrijden($user = null): bool
{
    return heeft_rol([TRAINER_ZWEMSCHOOL], $user) && !heeft_rol([...STAF, ...GEZIN], $user);
}

function is_secretariaat($user = null): bool
{
    return heeft_rol(SECRETARIAAT, $user);
}

function mag_rollen_beheren($user = null): bool
{
    return heeft_rol(ROLBEHEER, $user);
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
    $user = gebruiker($user);
    if (!$user) {
        return false;
    }
    return heeft_rol([BEHEERDER], $user) || get_user_meta($user->ID, 'khzs_penningmeester', true) === '1';
}

function club(): array
{
    return [
        'naam' => get_option('khzs_club_naam', 'Hasseltse Zwemvereniging Spartacus'),
        'code' => get_option('khzs_club_code', 'KHZS'),
        'nation' => 'BEL',
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
