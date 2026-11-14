<?php
/**
 * Rollen: een account kan meerdere rollen hebben (bv. ouder én trainer). Een lid vraagt een rol aan; een bestuurder,
 * sportsecretaris of beheerder keurt goed, keurt af (met reden) of geeft een andere rol. Zelfde werking als
 * inschrijvingen/rollen.py in de Django-versie. De rollen zelf staan in kern.php.
 */

namespace KHZS;

defined('ABSPATH') || exit;

const RA_OPEN = 'open', RA_GOEDGEKEURD = 'goedgekeurd', RA_AFGEKEURD = 'afgekeurd', RA_ANDERE_ROL = 'andere_rol', RA_INGETROKKEN = 'ingetrokken';
const RA_STATUS = [RA_OPEN => 'Wacht op goedkeuring', RA_GOEDGEKEURD => 'Goedgekeurd', RA_AFGEKEURD => 'Afgekeurd',
    RA_ANDERE_ROL => 'Andere rol gekregen', RA_INGETROKKEN => 'Ingetrokken'];

function rol_naam(string $rol): string
{
    return mb_strtolower(ROLLEN[$rol] ?? $rol);
}

function geef_rol(int $uid, string $rol): void
{
    zet_rollen($uid, [...bewaarde_rollen($uid), $rol]);
}

/** Waarom $door de rollen van $uid (of deze rol) niet mag wijzigen, of null als het mag. */
function fout_bij_rolbeheer(int $uid, ?string $rol = null, $door = null): ?string
{
    if (!mag_rollen_beheren($door)) {
        return 'Je mag geen rollen beheren.';
    }
    if ($rol !== null && !isset(ROLLEN[$rol])) {
        return 'Onbekende rol.';
    }
    if (!heeft_rol([BEHEERDER], $door) && ($rol === BEHEERDER || heeft_rol([BEHEERDER], $uid))) {
        return 'Enkel een beheerder kan beheerders aanstellen of wijzigen.';
    }
    return null;
}

function rolaanvraag(int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('rolaanvragen') . " WHERE id=%d", $id)) ?: null;
}

function open_rolaanvraag(int $uid, string $rol): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('rolaanvragen') . " WHERE user_id=%d AND rol=%s AND status=%s", $uid, $rol, RA_OPEN)) ?: null;
}

/** Rol rechtstreeks toekennen. Een openstaande aanvraag voor die rol wordt meteen goedgekeurd. */
function ken_rol_toe(int $uid, string $rol): void
{
    if ($fout = fout_bij_rolbeheer($uid, $rol)) {
        throw new \RuntimeException($fout);
    }
    $open = open_rolaanvraag($uid, $rol);
    if ($open) {
        behandel_rolaanvraag($open, 'goedkeuren');
    } else {
        geef_rol($uid, $rol);
    }
}

function neem_rol_af(int $uid, string $rol): void
{
    if ($fout = fout_bij_rolbeheer($uid, $rol)) {
        throw new \RuntimeException($fout);
    }
    if ($rol === BEHEERDER && user_can($uid, 'manage_options')) {
        throw new \RuntimeException('WordPress-beheerders zijn altijd beheerder in de app.');
    }
    zet_rollen($uid, array_diff(bewaarde_rollen($uid), [$rol]));
}

/** Zwemmer en ouder krijg je meteen (geeft null terug); de andere rollen worden een aanvraag. */
function vraag_rol_aan(int $uid, string $rol, string $motivatie = ''): ?int
{
    if (!in_array($rol, AANVRAAGBAAR, true)) {
        throw new \RuntimeException('Deze rol kan je niet aanvragen.');
    }
    if (heeft_rol([$rol], $uid)) {
        throw new \RuntimeException('Je hebt de rol ' . rol_naam($rol) . ' al.');
    }
    if (in_array($rol, ZONDER_GOEDKEURING, true)) {
        geef_rol($uid, $rol);
        return null;
    }
    if (open_rolaanvraag($uid, $rol)) {
        throw new \RuntimeException('Je hebt de rol ' . rol_naam($rol) . ' al aangevraagd.');
    }
    db()->insert(t('rolaanvragen'), ['user_id' => $uid, 'rol' => $rol, 'motivatie' => mb_substr(trim($motivatie), 0, 300),
        'status' => RA_OPEN, 'aangemaakt' => nu_sql()]);
    $id = (int) db()->insert_id;
    $u = get_userdata($uid);
    $ontvangers = array_values(array_diff(users_met('push_rolaanvraag', ROLBEHEER), [$uid]));
    stuur($ontvangers, 'Nieuwe rolaanvraag', (volle_naam($u) ?: $u->user_login) . ' vraagt de rol ' . rol_naam($rol) . '.', app_url('gebruikers/'));
    return $id;
}

function trek_rolaanvraag_in(int $uid, int $id): void
{
    $a = rolaanvraag($id);
    if (!$a || (int) $a->user_id !== $uid || $a->status !== RA_OPEN) {
        throw new \RuntimeException('Deze aanvraag kan je niet meer intrekken.');
    }
    db()->update(t('rolaanvragen'), ['status' => RA_INGETROKKEN, 'gelezen' => 1], ['id' => $id]);
}

/** $actie: 'goedkeuren', 'afkeuren' (met reden) of 'andere' (een andere rol in de plaats). */
function behandel_rolaanvraag(object $a, string $actie, ?string $rol = null, string $reden = ''): object
{
    $reden = mb_substr(trim($reden), 0, 300);
    if ($a->status !== RA_OPEN) {
        throw new \RuntimeException('Deze aanvraag is al behandeld.');
    }
    if ($actie === 'goedkeuren') {
        [$status, $toegekend] = [RA_GOEDGEKEURD, $a->rol];
    } elseif ($actie === 'afkeuren') {
        if ($reden === '') {
            throw new \RuntimeException('Geef een reden waarom je de aanvraag afkeurt.');
        }
        [$status, $toegekend] = [RA_AFGEKEURD, ''];
    } elseif ($actie === 'andere') {
        if (!$rol) {
            throw new \RuntimeException('Kies welke rol je in de plaats geeft.');
        }
        [$status, $toegekend] = [$rol === $a->rol ? RA_GOEDGEKEURD : RA_ANDERE_ROL, $rol];
    } else {
        throw new \RuntimeException('Onbekende actie.');
    }
    if ($fout = fout_bij_rolbeheer((int) $a->user_id, $toegekend ?: $a->rol)) {
        throw new \RuntimeException($fout);
    }
    if ($toegekend) {
        geef_rol((int) $a->user_id, $toegekend);
    }
    $wijziging = ['status' => $status, 'toegekende_rol' => $toegekend, 'reden' => $reden, 'behandeld_door' => get_current_user_id(),
        'behandeld_op' => nu_sql(), 'gelezen' => 0];
    db()->update(t('rolaanvragen'), $wijziging, ['id' => $a->id]);
    $a = (object) ($wijziging + (array) $a);
    stuur([(int) $a->user_id], 'Rolaanvraag ' . mb_strtolower(RA_STATUS[$status]), rol_beslissing($a), app_url('rollen/'));
    return $a;
}

/** De tekst van de melding voor het lid. */
function rol_beslissing(object $a): string
{
    $tekst = match ($a->status) {
        RA_GOEDGEKEURD => 'Je aanvraag voor de rol ' . rol_naam($a->rol) . ' is goedgekeurd.',
        RA_AFGEKEURD => 'Je aanvraag voor de rol ' . rol_naam($a->rol) . ' is afgekeurd.',
        RA_ANDERE_ROL => 'Je vroeg de rol ' . rol_naam($a->rol) . ' en kreeg de rol ' . rol_naam($a->toegekende_rol) . '.',
        default => RA_STATUS[$a->status] ?? '',
    };
    return $a->reden !== '' ? "$tekst {$a->reden}" : $tekst;
}

/** Beslissingen over eigen aanvragen die het lid nog niet gezien heeft (melding bovenaan in de app). */
function ongelezen_rolbeslissingen(int $uid): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('rolaanvragen') . " WHERE user_id=%d AND status NOT IN (%s,%s) AND gelezen=0 ORDER BY id",
        $uid, RA_OPEN, RA_INGETROKKEN));
}

function aantal_open_rolaanvragen(): int
{
    return (int) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('rolaanvragen') . " WHERE status=%s", RA_OPEN));
}

// ---------- pagina's ----------

function v_mijn_rollen(): void
{
    vereist_login();
    $uid = get_current_user_id();
    if (is_post()) {
        try {
            if (post('actie') === 'intrekken') {
                trek_rolaanvraag_in($uid, (int) post('aanvraag'));
                flash('Aanvraag ingetrokken.');
            } else {
                $rol = post('rol');
                $id = vraag_rol_aan($uid, $rol, post('motivatie'));
                flash($id === null ? 'Je bent nu ook ' . rol_naam($rol) . '.'
                    : 'Je aanvraag voor de rol ' . rol_naam($rol) . ' is verstuurd. Je krijgt een melding zodra ze behandeld is.');
            }
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        naar(app_url('rollen/'));
    }
    $aanvragen = db()->get_results(db()->prepare("SELECT * FROM " . t('rolaanvragen') . " WHERE user_id=%d ORDER BY id DESC LIMIT 20", $uid));
    $eigen = rollen_van($uid);
    $open = array_column(array_filter($aanvragen, fn($a) => $a->status === RA_OPEN), 'rol');
    $aanvraagbaar = array_diff(gesorteerd(AANVRAAGBAAR), $eigen, $open);
    // De beslissingen zijn nu gezien: de melding bovenaan verdwijnt.
    db()->query(db()->prepare("UPDATE " . t('rolaanvragen') . " SET gelezen=1 WHERE user_id=%d AND status<>%s", $uid, RA_OPEN));
    toon('rollen', ['eigen' => $eigen, 'aanvragen' => $aanvragen, 'aanvraagbaar' => $aanvraagbaar], 'Mijn rollen', 'smal');
}

function v_rolaanvraag_behandelen(string $id): void
{
    vereist_rol(ROLBEHEER);
    vereist_post();
    $a = rolaanvraag((int) $id) ?: niet_gevonden();
    $u = get_userdata((int) $a->user_id);
    try {
        $a = behandel_rolaanvraag($a, post('actie'), post('rol') ?: null, post('reden'));
        flash('Aanvraag van ' . ($u ? (volle_naam($u) ?: $u->user_login) : '?') . ': ' . mb_strtolower(RA_STATUS[$a->status]) . '.');
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    naar(app_url('gebruikers/'));
}
