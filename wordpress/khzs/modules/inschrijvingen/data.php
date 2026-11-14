<?php
/** Opvragen van gegevens. Rijen zijn stdClass-objecten zoals $wpdb ze teruggeeft. */

namespace KHZS;

defined('ABSPATH') || exit;


function wedstrijd(int $id): ?object
{
    return rij('wedstrijden', $id);
}

function zwemmer(int $id): ?object
{
    return rij('zwemmers', $id);
}

function startdatum(object $w): ?\DateTimeImmutable
{
    if (!property_exists($w, '_start')) {
        $w->_start = db()->get_var(db()->prepare("SELECT MIN(datum) FROM " . t('sessies') . " WHERE wedstrijd_id=%d", $w->id));
    }
    return dag($w->_start);
}

function zwemmers_van(int $user_id): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE account_id=%d ORDER BY naam, voornaam", $user_id));
}

function alle_zwemmers(string $q = ''): array
{
    $sql = "SELECT z.*, u.user_email AS email FROM " . t('zwemmers') . " z LEFT JOIN " . db()->users . " u ON u.ID=z.account_id";
    if ($q !== '') {
        $like = '%' . db()->esc_like($q) . '%';
        $sql .= db()->prepare(" WHERE z.naam LIKE %s OR z.voornaam LIKE %s", $like, $like);
    }
    return db()->get_results("$sql ORDER BY z.naam, z.voornaam");
}

/** Een zwemmer die deze gebruiker mag zien: eigen zwemmers, of alle voor staf. Anders 404. */
function zwemmer_voor($id): object
{
    $z = zwemmer((int) $id);
    if (!$z || (!is_staf() && (int) $z->account_id !== get_current_user_id())) {
        niet_gevonden();
    }
    return $z;
}

function tijden_van(int $zwemmer_id): array
{
    return db()->get_results(db()->prepare(
        "SELECT * FROM " . t('tijden') . " WHERE zwemmer_id=%d ORDER BY slag, afstand, baan, tijd",
        $zwemmer_id
    ));
}

/** Sessies van een wedstrijd met ->nummers (gesorteerd) en per nummer ->groepen. */
function sessies_met_nummers(int $wid): array
{
    $sessies = db()->get_results(db()->prepare("SELECT * FROM " . t('sessies') . " WHERE wedstrijd_id=%d ORDER BY nummer", $wid), OBJECT_K);
    foreach ($sessies as $s) {
        $s->nummers = [];
    }
    if (!$sessies) {
        return [];
    }
    $nummers = db()->get_results("SELECT * FROM " . t('nummers') . " WHERE sessie_id IN (" . in_lijst(array_keys($sessies)) . ") ORDER BY volgorde, nummer", OBJECT_K);
    foreach ($nummers as $n) {
        $n->groepen = [];
    }
    if ($nummers) {
        foreach (db()->get_results("SELECT * FROM " . t('leeftijdsgroepen') . " WHERE nummer_id IN (" . in_lijst(array_keys($nummers)) . ") ORDER BY id") as $g) {
            $nummers[$g->nummer_id]->groepen[] = $g;
        }
    }
    foreach ($nummers as $n) {
        $n->sessie = $sessies[$n->sessie_id];
        $sessies[$n->sessie_id]->nummers[] = $n;
    }
    return array_values($sessies);
}

/** Alle nummers van een wedstrijd, op id. */
function nummers_van(int $wid): array
{
    $uit = [];
    foreach (sessies_met_nummers($wid) as $s) {
        foreach ($s->nummers as $n) {
            $uit[(int) $n->id] = $n;
        }
    }
    return $uit;
}

function limietlijsten(int $wid, bool $enkel_gekozen): array
{
    $lijsten = db()->get_results(db()->prepare(
        "SELECT * FROM " . t('limietlijsten') . " WHERE wedstrijd_id=%d" . ($enkel_gekozen ? " AND gekozen=1" : "") . " ORDER BY naam, baan, geslacht, agemin",
        $wid
    ), OBJECT_K);
    foreach ($lijsten as $l) {
        $l->limieten = [];
    }
    if ($lijsten) {
        foreach (db()->get_results("SELECT * FROM " . t('limieten') . " WHERE lijst_id IN (" . in_lijst(array_keys($lijsten)) . ")") as $l) {
            $lijsten[$l->lijst_id]->limieten[] = $l;
        }
    }
    return array_values($lijsten);
}

function gekozen_limietlijsten(int $wid): array
{
    return limietlijsten($wid, true);
}

/**
 * Inschrijvingen van een wedstrijd met ->nummer (incl. ->sessie) en ->zwemmer.
 * $waar: extra SQL-voorwaarde op i (en z), al veilig opgebouwd.
 */
function inschrijvingen_van(int $wid, string $waar = ''): array
{
    $nummers = nummers_van($wid);
    if (!$nummers) {
        return [];
    }
    $rijen = db()->get_results("SELECT i.* FROM " . t('inschrijvingen') . " i JOIN " . t('zwemmers') . " z ON z.id=i.zwemmer_id
        WHERE i.nummer_id IN (" . in_lijst(array_keys($nummers)) . ") $waar ORDER BY z.naam, z.voornaam");
    $zwemmers = [];
    if ($rijen) {
        $zwemmers = db()->get_results("SELECT * FROM " . t('zwemmers') . " WHERE id IN (" . in_lijst(array_column($rijen, 'zwemmer_id')) . ")", OBJECT_K);
    }
    foreach ($rijen as $i) {
        $i->nummer = $nummers[(int) $i->nummer_id];
        $i->zwemmer = $zwemmers[$i->zwemmer_id];
    }
    usort($rijen, fn($a, $b) => [$a->zwemmer->naam, $a->zwemmer->voornaam, (int) $a->nummer->sessie->nummer, (int) $a->nummer->volgorde]
        <=> [$b->zwemmer->naam, $b->zwemmer->voornaam, (int) $b->nummer->sessie->nummer, (int) $b->nummer->volgorde]);
    return $rijen;
}

/** Inschrijvingen van één zwemmer voor een wedstrijd, op nummer_id. */
function inschrijvingen_zwemmer(int $zid, int $wid): array
{
    $uit = [];
    foreach (inschrijvingen_van($wid, db()->prepare("AND i.zwemmer_id=%d", $zid)) as $i) {
        $uit[(int) $i->nummer_id] = $i;
    }
    return $uit;
}

function deelname(int $zid, int $wid): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('deelnames') . " WHERE zwemmer_id=%d AND wedstrijd_id=%d", $zid, $wid)) ?: null;
}

/** Deelname ophalen of aanmaken (zoals get_or_create). */
function deelname_of_nieuw(int $zid, int $wid, string $keuze): object
{
    $d = deelname($zid, $wid);
    if (!$d) {
        db()->insert(t('deelnames'), ['zwemmer_id' => $zid, 'wedstrijd_id' => $wid, 'keuze' => $keuze, 'gewijzigd' => nu_sql()]);
        $d = deelname($zid, $wid);
    }
    return $d;
}

function bewaar_deelname(int $zid, int $wid, array $velden): void
{
    $velden['gewijzigd'] = nu_sql();
    if (deelname($zid, $wid)) {
        db()->update(t('deelnames'), $velden, ['zwemmer_id' => $zid, 'wedstrijd_id' => $wid]);
    } else {
        $velden += ['keuze' => INTERESSE];
        db()->insert(t('deelnames'), $velden + ['zwemmer_id' => $zid, 'wedstrijd_id' => $wid]);
    }
}

function bijlagen_van(int $wid): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('bijlagen') . " WHERE wedstrijd_id=%d ORDER BY toegevoegd, id", $wid));
}

function extensie(string $bestand): string
{
    return str_contains($bestand, '.') ? strtolower(substr(strrchr($bestand, '.'), 1)) : '';
}

function icoon(object $b): string
{
    return ['pdf' => '📄', 'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'doc' => '📝', 'docx' => '📝', 'xls' => '📊', 'xlsx' => '📊'][extensie($b->bestand)] ?? '📎';
}

/** Meldingen met ->door_naam en ->regels. */
function meldingen(string $waar, int $limiet = 10): array
{
    $rijen = db()->get_results("SELECT m.*, w.naam AS wedstrijd_naam FROM " . t('meldingen') . " m LEFT JOIN " . t('wedstrijden') . " w ON w.id=m.wedstrijd_id
        WHERE $waar ORDER BY m.aangemaakt DESC, m.id DESC LIMIT " . (int) $limiet);
    foreach ($rijen as $m) {
        $m->regels = preg_split('/\r?\n/', $m->tekst);
        $u = $m->door_id ? get_userdata((int) $m->door_id) : null;
        $m->door_naam = $u ? trim(ROLLEN[rol($u)] . ' ' . ($u->first_name ?: $u->user_login)) : 'De club';
    }
    return $rijen;
}

function swimrankings_url(object $z): string
{
    return ctype_digit((string) $z->swimrankings_id)
        ? 'https://www.swimrankings.net/index.php?page=athleteDetail&athleteId=' . $z->swimrankings_id : '';
}
