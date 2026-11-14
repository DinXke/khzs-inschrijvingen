<?php
/**
 * Ploegen (A-groep, B-groep, masters…). Een zwemmer zit in één of meer ploegen. De zwemmer of ouder vraagt een ploeg
 * aan; een trainer van die ploeg, een bestuurder, sportsecretaris of beheerder keurt goed of af. Zelfde werking als
 * inschrijvingen/ploegen.py in de Django-versie.
 *
 * ploeg_accounts() en ploeg_lezers() bepalen wie bij een ploeg hoort, zodat een latere communicatiemodule iedereen
 * enkel de berichten van zijn eigen ploegen kan tonen. Trainers van de competitie, bestuur, secretariaat en beheer zien
 * alle ploegen; een trainer van de zwemschool ziet de ploegen van de zwemschool (soort) en de ploegen die hij of zij traint.
 */

namespace KHZS;

defined('ABSPATH') || exit;

const PL_OPEN = 'open', PL_GOEDGEKEURD = 'goedgekeurd', PL_AFGEKEURD = 'afgekeurd';
const PL_STATUS = [PL_OPEN => 'Wacht op goedkeuring', PL_GOEDGEKEURD => 'Lid', PL_AFGEKEURD => 'Afgekeurd'];
const PL_SOORT = ['competitie' => 'Competitie', 'zwemschool' => 'Zwemschool'];

function mag_ploegen_beheren($user = null): bool
{
    return heeft_rol(PLOEGBEHEER, $user);
}

function ziet_alle_ploegen($user = null): bool
{
    return heeft_rol(ALLE_PLOEGEN, $user);
}

/** Trainers van de zwemschool zien alle ploegen van de zwemschool. */
function ziet_zwemschoolploegen($user = null): bool
{
    return heeft_rol([TRAINER_ZWEMSCHOOL], $user);
}

/** Het menu Ploegen: wie meer ploegen ziet dan die van de eigen zwemmers. */
function ziet_ploegenmenu($user = null): bool
{
    return ziet_alle_ploegen($user) || ziet_zwemschoolploegen($user);
}

function ploeg(int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('ploegen') . " WHERE id=%d", $id)) ?: null;
}

/** Zwemmer opzoeken zonder de module Inschrijvingen (ploegen horen bij de kern). */
function pl_zwemmer(int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE id=%d", $id)) ?: null;
}

function ploeg_trainers(int $ploeg_id): array
{
    return array_map('intval', db()->get_col(db()->prepare("SELECT user_id FROM " . t('ploeg_trainers') . " WHERE ploeg_id=%d", $ploeg_id)));
}

/** Ploeg-id's die dit account traint. */
function eigen_getrainde_ploegen(int $uid): array
{
    return array_map('intval', db()->get_col(db()->prepare("SELECT ploeg_id FROM " . t('ploeg_trainers') . " WHERE user_id=%d", $uid)));
}

/** Aanvragen voor deze ploeg behandelen, en leden toevoegen of verwijderen. */
function mag_ploeg_behandelen(int $ploeg_id, $user = null): bool
{
    $u = gebruiker($user);
    return $u && (mag_ploegen_beheren($u) || in_array($ploeg_id, eigen_getrainde_ploegen($u->ID), true));
}

/** Zwemmer-id's van het account (eigen en gekoppelde), als SQL-lijst. */
function sql_zwemmers_van(int $uid): string
{
    return db()->prepare("SELECT id FROM " . t('zwemmers') . " WHERE account_id=%d UNION SELECT zwemmer_id FROM " . t('zwemmer_accounts') . " WHERE user_id=%d", $uid, $uid);
}

// ---------- wie hoort waarbij ----------

function ploeg_zwemmers(int $ploeg_id): array
{
    return db()->get_results(db()->prepare("SELECT z.* FROM " . t('zwemmers') . " z JOIN " . t('ploegleden') . " l ON l.zwemmer_id=z.id
        WHERE l.ploeg_id=%d AND l.status=%s ORDER BY z.naam, z.voornaam", $ploeg_id, PL_GOEDGEKEURD));
}

/** De accounts van de zwemmers van deze ploeg (ook gekoppelde ouders) en de trainers van de ploeg. */
function ploeg_accounts(int $ploeg_id): array
{
    $zids = db()->prepare("SELECT zwemmer_id FROM " . t('ploegleden') . " WHERE ploeg_id=%d AND status=%s", $ploeg_id, PL_GOEDGEKEURD);
    $ids = db()->get_col("SELECT account_id FROM " . t('zwemmers') . " WHERE id IN ($zids) UNION SELECT user_id FROM " . t('zwemmer_accounts')
        . " WHERE zwemmer_id IN ($zids)");
    return array_values(array_unique(array_map('intval', [...$ids, ...ploeg_trainers($ploeg_id)])));
}

/** Iedereen die deze ploeg (en later haar kanaal) mag zien: de accounts van de ploeg plus wie alle ploegen ziet. */
function ploeg_lezers(int $ploeg_id): array
{
    $zwemschool = (ploeg($ploeg_id)->soort ?? '') === 'zwemschool';
    $alle = array_filter(get_users(['fields' => 'ID']), fn($id) => ziet_alle_ploegen((int) $id) || ($zwemschool && ziet_zwemschoolploegen((int) $id)));
    return array_values(array_unique([...ploeg_accounts($ploeg_id), ...array_map('intval', $alle)]));
}

/** Ploegen waar een zwemmer van dit account lid van is, of die dit account traint. */
function eigen_ploegen(int $uid): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('ploegen') . " WHERE id IN (SELECT ploeg_id FROM " . t('ploegleden')
        . " WHERE status=%s AND zwemmer_id IN (" . sql_zwemmers_van($uid) . ")) OR id IN (SELECT ploeg_id FROM " . t('ploeg_trainers')
        . " WHERE user_id=%d) ORDER BY naam", PL_GOEDGEKEURD, $uid));
}

function zichtbare_ploegen(int $uid): array
{
    if (ziet_alle_ploegen($uid)) {
        return db()->get_results("SELECT * FROM " . t('ploegen') . " ORDER BY naam");
    }
    $lijst = eigen_ploegen($uid);
    if (ziet_zwemschoolploegen($uid)) {
        $ids = array_column($lijst, 'id');
        foreach (db()->get_results("SELECT * FROM " . t('ploegen') . " WHERE soort='zwemschool' ORDER BY naam") as $p) {
            if (!in_array($p->id, $ids, false)) {
                $lijst[] = $p;
            }
        }
        usort($lijst, fn($a, $b) => strcasecmp($a->naam, $b->naam));
    }
    return $lijst;
}

function mag_ploeg_zien(int $ploeg_id, int $uid): bool
{
    return ziet_alle_ploegen($uid) || (ziet_zwemschoolploegen($uid) && (ploeg($ploeg_id)->soort ?? '') === 'zwemschool')
        || in_array($ploeg_id, array_map('intval', array_column(eigen_ploegen($uid), 'id')), true);
}

/** Open aanvragen die dit account mag behandelen (met zwemmer- en ploegnaam). */
function te_behandelen_ploeg(?int $uid = null): array
{
    $uid = $uid ?? get_current_user_id();
    if (!$uid || (!mag_ploegen_beheren($uid) && !eigen_getrainde_ploegen($uid))) {
        return [];
    }
    $sql = "SELECT l.*, z.voornaam, z.naam, p.naam AS ploegnaam FROM " . t('ploegleden') . " l JOIN " . t('zwemmers') . " z ON z.id=l.zwemmer_id
        JOIN " . t('ploegen') . " p ON p.id=l.ploeg_id WHERE l.status=%s";
    if (!mag_ploegen_beheren($uid)) {
        $sql .= " AND l.ploeg_id IN (" . in_lijst(eigen_getrainde_ploegen($uid)) . ")";
    }
    return db()->get_results(db()->prepare("$sql ORDER BY l.id", PL_OPEN));
}

// ---------- aanvragen en beslissen ----------

function ploeglid(int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT l.*, z.voornaam, z.naam, p.naam AS ploegnaam FROM " . t('ploegleden') . " l
        JOIN " . t('zwemmers') . " z ON z.id=l.zwemmer_id JOIN " . t('ploegen') . " p ON p.id=l.ploeg_id WHERE l.id=%d", $id)) ?: null;
}

/** Vraagt een ploeg aan voor deze zwemmer. Wie de ploeg mag behandelen (staf), zet de zwemmer er meteen in. */
function vraag_ploeg_aan(int $uid, object $z, object $p): object
{
    $eigen = heeft_account($z, $uid);
    $staf = mag_ploeg_behandelen((int) $p->id, $uid);
    if (!$eigen && !$staf) {
        throw new \RuntimeException('Je kan enkel voor je eigen zwemmers een ploeg kiezen.');
    }
    if (!(int) $p->actief) {
        throw new \RuntimeException('Deze ploeg is gearchiveerd.');
    }
    $lid = db()->get_row(db()->prepare("SELECT * FROM " . t('ploegleden') . " WHERE zwemmer_id=%d AND ploeg_id=%d", $z->id, $p->id));
    if ($lid && $lid->status === PL_GOEDGEKEURD) {
        throw new \RuntimeException("{$z->voornaam} zit al in {$p->naam}.");
    }
    if ($lid && $lid->status === PL_OPEN) {
        throw new \RuntimeException("{$z->voornaam} wacht al op goedkeuring voor {$p->naam}.");
    }
    $rij = ['status' => $staf ? PL_GOEDGEKEURD : PL_OPEN, 'aangevraagd_door' => $uid, 'reden' => '',
        'behandeld_door' => $staf ? $uid : null, 'behandeld_op' => $staf ? nu_sql() : null, 'gelezen' => $staf && $eigen ? 1 : 0];
    if ($lid) {
        db()->update(t('ploegleden'), $rij, ['id' => $lid->id]);
        $id = (int) $lid->id;
    } else {
        db()->insert(t('ploegleden'), $rij + ['zwemmer_id' => $z->id, 'ploeg_id' => $p->id, 'aangemaakt' => nu_sql()]);
        $id = (int) db()->insert_id;
    }
    if (!$staf) {
        $trainers = ploeg_trainers((int) $p->id);
        $ontvangers = array_values(array_filter(users_met('push_ploegaanvraag'),
            fn($o) => $o !== $uid && (mag_ploegen_beheren($o) || in_array($o, $trainers, true))));
        $u = get_userdata($uid);
        stuur($ontvangers, 'Nieuwe ploegaanvraag', (volle_naam($u) ?: $u->user_login) . " vraagt {$p->naam} voor {$z->voornaam} {$z->naam}.",
            app_url("ploegen/{$p->id}/"));
    }
    return ploeglid($id);
}

/** $actie: 'goedkeuren' of 'afkeuren' (met reden). */
function behandel_ploeglid(object $lid, string $actie, string $reden = ''): object
{
    $reden = mb_substr(trim($reden), 0, 300);
    if (!mag_ploeg_behandelen((int) $lid->ploeg_id)) {
        throw new \RuntimeException('Je mag aanvragen voor deze ploeg niet behandelen.');
    }
    if ($lid->status !== PL_OPEN) {
        throw new \RuntimeException('Deze aanvraag is al behandeld.');
    }
    if ($actie === 'goedkeuren') {
        $status = PL_GOEDGEKEURD;
    } elseif ($actie === 'afkeuren') {
        if ($reden === '') {
            throw new \RuntimeException('Geef een reden waarom je de aanvraag afkeurt.');
        }
        $status = PL_AFGEKEURD;
    } else {
        throw new \RuntimeException('Onbekende actie.');
    }
    db()->update(t('ploegleden'), ['status' => $status, 'reden' => $reden, 'behandeld_door' => get_current_user_id(),
        'behandeld_op' => nu_sql(), 'gelezen' => 0], ['id' => $lid->id]);
    $lid = ploeglid((int) $lid->id);
    $z = pl_zwemmer((int) $lid->zwemmer_id);
    $ontvangers = array_values(array_filter(zwemmer_accounts($z), fn($u) => voorkeur((int) $u, 'push_beslissing')));
    stuur($ontvangers, "Ploeg {$lid->ploegnaam}", ploeg_beslissing($lid), app_url("zwemmers/{$z->id}/"));
    return $lid;
}

/** Uit de ploeg halen of een aanvraag intrekken: door een account van de zwemmer of door wie de ploeg behandelt. */
function verwijder_ploeglid(object $lid): void
{
    $z = pl_zwemmer((int) $lid->zwemmer_id);
    if (!heeft_account($z, get_current_user_id()) && !mag_ploeg_behandelen((int) $lid->ploeg_id)) {
        throw new \RuntimeException('Je mag dit lidmaatschap niet wijzigen.');
    }
    db()->delete(t('ploegleden'), ['id' => $lid->id]);
}

/** De tekst van de melding voor de accounts van de zwemmer. */
function ploeg_beslissing(object $lid): string
{
    return match ($lid->status) {
        PL_GOEDGEKEURD => "{$lid->voornaam} zit nu in de ploeg {$lid->ploegnaam}.",
        PL_AFGEKEURD => trim("De aanvraag van {$lid->voornaam} voor de ploeg {$lid->ploegnaam} is afgekeurd. {$lid->reden}"),
        default => "{$lid->voornaam} wacht op goedkeuring voor de ploeg {$lid->ploegnaam}.",
    };
}

/** Beslissingen over ploegen van de eigen zwemmers die nog niet gezien zijn (melding bovenaan in de app). */
function ongelezen_ploegbeslissingen(int $uid): array
{
    return db()->get_results(db()->prepare("SELECT l.*, z.voornaam, z.naam, p.naam AS ploegnaam FROM " . t('ploegleden') . " l
        JOIN " . t('zwemmers') . " z ON z.id=l.zwemmer_id JOIN " . t('ploegen') . " p ON p.id=l.ploeg_id
        WHERE l.gelezen=0 AND l.status<>%s AND l.zwemmer_id IN (" . sql_zwemmers_van($uid) . ")", PL_OPEN));
}

/** De ploegen van een zwemmer (alle statussen), voor de zwemmerpagina. */
function ploegen_van_zwemmer(int $zid): array
{
    return db()->get_results(db()->prepare("SELECT l.*, p.naam AS ploegnaam, p.actief FROM " . t('ploegleden') . " l JOIN " . t('ploegen') . " p ON p.id=l.ploeg_id
        WHERE l.zwemmer_id=%d ORDER BY p.naam", $zid));
}

function actieve_ploegen(): array
{
    return db()->get_results("SELECT * FROM " . t('ploegen') . " WHERE actief=1 ORDER BY naam");
}

// ---------- pagina's ----------

/** Leest naam, beschrijving, trainers en actief uit het formulier. Geeft [rij, trainers, fouten]. */
function lees_ploeg(?int $id = null): array
{
    $rij = ['naam' => mb_substr(post('naam'), 0, 60), 'beschrijving' => mb_substr(post('beschrijving'), 0, 200),
        'soort' => isset(PL_SOORT[post('soort')]) ? post('soort') : 'competitie', 'actief' => isset($_POST['actief']) ? 1 : 0];
    $f = [];
    if ($rij['naam'] === '') {
        $f['naam'] = 'Dit veld is verplicht.';
    } elseif (db()->get_var(db()->prepare("SELECT id FROM " . t('ploegen') . " WHERE naam=%s AND id<>%d", $rij['naam'], $id ?? 0))) {
        $f['naam'] = 'Er bestaat al een ploeg met deze naam.';
    }
    $trainers = array_values(array_filter(array_map('intval', (array) ($_POST['trainers'] ?? [])), fn($t) => is_trainer($t)));
    return [$rij, $trainers, $f];
}

function bewaar_ploeg_trainers(int $ploeg_id, array $trainers): void
{
    db()->delete(t('ploeg_trainers'), ['ploeg_id' => $ploeg_id]);
    foreach (array_unique($trainers) as $t) {
        db()->insert(t('ploeg_trainers'), ['ploeg_id' => $ploeg_id, 'user_id' => $t]);
    }
}

/** Accounts met een rol trainer (competitie of zwemschool), om aan een ploeg te koppelen. */
function alle_trainers(): array
{
    return array_values(array_filter(get_users(['orderby' => 'display_name']), fn($u) => is_trainer($u)));
}

function v_ploegen(): void
{
    vereist_login();
    $uid = get_current_user_id();
    $beheer = mag_ploegen_beheren();
    $v = ['naam' => '', 'beschrijving' => '', 'soort' => 'competitie', 'actief' => 1];
    $f = [];
    $trainers = [];
    if (is_post()) {
        if (!$beheer) {
            geen_toegang();
        }
        [$v, $trainers, $f] = lees_ploeg();
        if (!$f) {
            db()->insert(t('ploegen'), $v + ['aangemaakt' => nu_sql()]);
            $id = (int) db()->insert_id;
            bewaar_ploeg_trainers($id, $trainers);
            flash("Ploeg {$v['naam']} aangemaakt.");
            naar(app_url("ploegen/$id/"));
        }
    }
    $lijst = zichtbare_ploegen($uid);
    foreach ($lijst as $p) {
        $p->leden = (int) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('ploegleden') . " WHERE ploeg_id=%d AND status=%s", $p->id, PL_GOEDGEKEURD));
        $p->open = (int) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('ploegleden') . " WHERE ploeg_id=%d AND status=%s", $p->id, PL_OPEN));
        $p->trainers = array_filter(array_map('get_userdata', ploeg_trainers((int) $p->id)));
    }
    toon('ploegen', ['ploegen' => $lijst, 'beheer' => $beheer, 'aanvragen' => te_behandelen_ploeg($uid), 'v' => $v, 'f' => $f,
        'gekozen' => $trainers, 'alle_trainers' => $beheer ? alle_trainers() : []], 'Ploegen');
}

function v_ploeg(string $id): void
{
    vereist_login();
    $p = ploeg((int) $id) ?: niet_gevonden();
    $uid = get_current_user_id();
    if (!mag_ploeg_zien((int) $p->id, $uid)) {
        geen_toegang();
    }
    $beheer = mag_ploegen_beheren();
    $behandelen = mag_ploeg_behandelen((int) $p->id);
    $v = (array) $p;
    $f = [];
    $gekozen = ploeg_trainers((int) $p->id);
    if (is_post()) {
        $actie = post('actie');
        if ($actie === 'bewaar' && $beheer) {
            [$v, $gekozen, $f] = lees_ploeg((int) $p->id);
            if (!$f) {
                db()->update(t('ploegen'), $v, ['id' => $p->id]);
                bewaar_ploeg_trainers((int) $p->id, $gekozen);
                flash('Ploeg bewaard.');
                naar(app_url("ploegen/{$p->id}/"));
            }
        } elseif ($actie === 'verwijder_ploeg' && $beheer) {
            db()->delete(t('ploegleden'), ['ploeg_id' => $p->id]);
            db()->delete(t('ploeg_trainers'), ['ploeg_id' => $p->id]);
            db()->delete(t('ploegen'), ['id' => $p->id]);
            do_action('khzs_ploeg_verwijderd', (int) $p->id);
            flash("Ploeg {$p->naam} verwijderd.");
            naar(app_url('ploegen/'));
        } elseif ($actie === 'toevoegen' && $behandelen) {
            $z = pl_zwemmer((int) post('zwemmer')) ?: niet_gevonden();
            try {
                vraag_ploeg_aan($uid, $z, $p);
                flash("{$z->voornaam} {$z->naam} zit nu in {$p->naam}.");
            } catch (\RuntimeException $e) {
                flash($e->getMessage(), 'error');
            }
            naar(app_url("ploegen/{$p->id}/"));
        } else {
            geen_toegang();
        }
    }
    $leden = db()->get_results(db()->prepare("SELECT l.*, z.voornaam, z.naam, %s AS ploegnaam FROM " . t('ploegleden') . " l JOIN " . t('zwemmers')
        . " z ON z.id=l.zwemmer_id WHERE l.ploeg_id=%d ORDER BY z.naam, z.voornaam", $p->naam, $p->id));
    $toe = $behandelen ? db()->get_results(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE id NOT IN (SELECT zwemmer_id FROM " . t('ploegleden')
        . " WHERE ploeg_id=%d) ORDER BY naam, voornaam", $p->id)) : [];
    toon('ploeg', ['p' => $p, 'v' => $v, 'f' => $f, 'gekozen' => $gekozen, 'beheer' => $beheer, 'behandelen' => $behandelen,
        'leden' => array_values(array_filter($leden, fn($l) => $l->status === PL_GOEDGEKEURD)),
        'aanvragen' => $behandelen ? array_values(array_filter($leden, fn($l) => $l->status === PL_OPEN)) : [],
        'toe_te_voegen' => $toe, 'trainers' => array_filter(array_map('get_userdata', $gekozen)),
        'alle_trainers' => $beheer ? alle_trainers() : []], $p->naam);
}

function v_ploeglid(string $id): void
{
    vereist_login();
    vereist_post();
    $lid = ploeglid((int) $id) ?: niet_gevonden();
    try {
        if (post('actie') === 'verwijderen') {
            verwijder_ploeglid($lid);
            flash("{$lid->voornaam} {$lid->naam} zit niet meer in {$lid->ploegnaam}.");
        } else {
            flash(ploeg_beslissing(behandel_ploeglid($lid, post('actie'), post('reden'))));
        }
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    naar(terug(app_url("ploegen/{$lid->ploeg_id}/")));
}
