<?php
/**
 * Module trainers — planning en verloning.
 *
 * Een trainingsmoment is een vaste training per week (bv. A-groep, maandag 18:00–19:30). Vaste trainers duiden aan
 * dat ze een moment normaal geven (tr_vast, met een periode). Per datum kan dat wijzigen (tr_wijzigingen):
 *   afwezig   — een vaste trainer kan niet
 *   vervanger — iemand neemt de plaats van een afwezige trainer over (vervangt_id)
 *   extra     — iemand komt helpen
 * Een moment kan voor één of meer ploegen zijn (tr_moment_ploegen); zonder ploeg is het voor iedereen.
 *
 * Afgelast en verlof (tr_afgelast, met een soort: vakantie, verlof, zwembad dicht, ander) gelden voor
 *   één training (moment_id), alle trainingen van een ploeg (ploeg_id) of alle trainingen (beide 0).
 *   Een training voor meerdere ploegen valt pas weg als al haar ploegen verlof hebben: zolang één ploeg traint, gaat ze door.
 *   Een afgelaste training blijft zichtbaar (met de reden), maar telt nooit mee en krijgt geen vraag "Was je aanwezig?".
 *
 * Seizoenen (tr_seizoenen): buiten een seizoen bestaan er geen trainingen. Ze staan niet in de planning of bij
 *   Mijn trainingen, tellen niet voor de uren en krijgen geen vraag (tr_trainingen() en tr_geldig() laten ze weg).
 *   Zonder seizoenen loopt alles door, en dagen vóór het begin van het eerste seizoen blijven zoals ze waren
 *   (geschiedenis en uitbetaalde uren van vóór de seizoenen veranderen niet). Zie tr_in_seizoen().
 *
 * Aanwezigheid (tr_bevestigingen): na elke training vraagt een pushmelding aan elke ingeplande trainer "Was je aanwezig?".
 *   ja             — de training telt mee voor de uren en de verloning
 *   nee            — telt niet mee
 *   niet bevestigd — telt (nog) niet mee; staat apart in de overzichten tot de trainer of het bestuur ze bevestigt
 * Trainingen vóór de optie khzs_tr_bevestigen_vanaf (bestaande installaties) tellen mee zonder bevestiging.
 * Gepresteerde uren = trainingen tot en met vandaag, niet afgelast, waarop de trainer ingepland stond en 'ja' bevestigde.
 *
 * Zwemmers (tr_zwemmers): een trainer duidt aan welke zwemmers op een training waren, eventueel met te laat, vroeger
 *   weg of ziek (TR_ZW_SOORT). Zwemmers en ouders bevestigen zelf niets en krijgen geen vraag; ze zien wel de
 *   statistiek van zichzelf of van hun kinderen (tr_zw_mag_zwemmer).
 *   De lijst komt uit de ploegen van de training. Aanduiden mag eender welke trainer van de competitie voor de ploegen
 *   van de competitie, eender welke trainer van de zwemschool voor die van de zwemschool, en het bestuur, het
 *   secretariaat en de beheerder voor alles (tr_zw_mag_ploeg). Niet enkel wie op de training ingepland staat.
 *   Per zwemmer één rij met aanwezig 1 of 0: wie bewaart, bewaart de hele lijst. Geen rijen = niet aangeduid.
 *
 * Een trainer past zijn of haar eigen voorbije trainingen aan zolang die maand voor hem of haar niet uitbetaald is.
 * Het bestuur kan altijd alles aanpassen; de trainer krijgt dan een melding en ziet de wijziging (tr_log).
 */

namespace KHZS;

defined('ABSPATH') || exit;

const TR_DAGEN = [1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag'];
const TR_SOORT = ['vast' => 'vaste trainer', 'vervanger' => 'vervanger', 'extra' => 'extra'];
const TR_TARIEF = ['uur' => 'per uur', 'beurt' => 'per training'];

add_filter('khzs_voorkeuren', fn($v) => $v + [
    'push_vervanging' => ['(trainers) Een collega zoekt een vervanger, of iemand neemt mijn training over', true, 'trainer'],
    'push_aanwezig' => ['(trainers) Na een training: was je aanwezig?', true, 'trainer'],
    'push_aanpassing' => ['(trainers) Iemand van het bestuur past mijn planning of aanwezigheid aan', true, 'trainer'],
    'push_afgelast' => ['(trainers) Een training van mij wordt afgelast (verlof, vakantie…) of gaat toch door', true, 'trainer'],
]);

const TR_STATUS = ['ja' => 'aanwezig', 'nee' => 'niet aanwezig', 'open' => 'niet bevestigd', 'gepland' => 'gepland'];
const TR_BRON = ['trainer' => 'zelf', 'melding' => 'via de melding', 'bestuur' => 'door het bestuur'];
// Aanwezigheid van een zwemmer op een training. Te laat en vroeger weg tellen als aanwezig, ziek als afwezig.
const TR_ZW_SOORT = ['aanwezig' => 'aanwezig', 'laat' => 'te laat', 'vroeger' => 'vroeger weg', 'ziek' => 'ziek', 'afwezig' => 'afwezig'];
const TR_ZW_STATUS = ['laat', 'vroeger', 'ziek'];
const TR_AFGELAST_SOORT = ['vakantie' => 'vakantie', 'verlof' => 'verlof', 'zwembad' => 'zwembad dicht', 'ander' => 'afgelast'];

// ---------- rechten ----------

/**
 * Bestuurder: ziet en wijzigt alle schema's, aanwezigheden en uren van de trainers.
 * Bestuurder of beheerder (zodra de rollen uit feature/rollen er zijn), en altijd de penningmeester.
 */
function tr_is_bestuur($user = null): bool
{
    if (function_exists(__NAMESPACE__ . '\\heeft_rol') && defined(__NAMESPACE__ . '\\BESTUURDER') && heeft_rol([BESTUURDER, BEHEERDER], $user)) {
        return true;
    }
    return is_penningmeester($user);
}

/** Mag de planning en aanwezigheid van anderen zien en aanpassen: bestuur en sportsecretariaat. */
function tr_mag_beheren($user = null): bool
{
    return tr_is_bestuur($user) || is_secretariaat($user);
}

/** Wie trainingen geeft: de trainers van de competitie en van de zwemschool, het sportsecretariaat en de beheerder. */
const TR_STAF = [TRAINER, TRAINER_ZWEMSCHOOL, SECRETARIS, BEHEERDER];

function tr_is_trainer($user = null): bool
{
    return heeft_rol(TR_STAF, $user);
}

/** Is de maand van $datum voor deze trainer al uitbetaald? Dan kan enkel het bestuur nog iets wijzigen. */
function tr_vergrendeld(int $uid, string $datum): bool
{
    return (bool) db()->get_var(db()->prepare("SELECT id FROM " . t('tr_uitbetaald') . " WHERE user_id=%d AND maand=%s", $uid, substr($datum, 0, 7)));
}

/** Eerste dag die een trainer zelf nog kan wijzigen: de dag na de laatste uitbetaalde maand. */
function tr_eerste_open_dag(int $uid): string
{
    $maand = db()->get_var(db()->prepare("SELECT MAX(maand) FROM " . t('tr_uitbetaald') . " WHERE user_id=%d", $uid));
    return $maand ? (new \DateTimeImmutable("$maand-01"))->modify('+1 month')->format('Y-m-d') : '0000-00-00';
}

// ---------- opvragen ----------

function tr_moment(int $id): ?object
{
    return rij('tr_momenten', $id);
}

/** Alle momenten, of enkel die actief zijn ergens in [van, tot]. Gesorteerd op weekdag en uur. */
function tr_momenten(?string $van = null, ?string $tot = null): array
{
    $sql = "SELECT * FROM " . t('tr_momenten');
    if ($van !== null) {
        $sql .= db()->prepare(" WHERE van<=%s AND (tot IS NULL OR tot>=%s)", $tot ?? $van, $van);
    }
    return db()->get_results("$sql ORDER BY weekdag, start, naam");
}

/** Ploeg-id's per moment: [moment_id => [ploeg_id, …]]. Momenten zonder ploeg staan er niet in. */
function tr_moment_ploegen(): array
{
    $uit = [];
    foreach (db()->get_results("SELECT moment_id, ploeg_id FROM " . t('tr_moment_ploegen') . " ORDER BY id") as $r) {
        $uit[(int) $r->moment_id][] = (int) $r->ploeg_id;
    }
    return $uit;
}

/** Alle ploegen, actieve eerst (een gearchiveerde ploeg kan nog aan een training hangen). */
function tr_alle_ploegen(): array
{
    return db()->get_results("SELECT * FROM " . t('ploegen') . " ORDER BY actief DESC, naam");
}

/** Namen van ploegen, bv. "A-groep, B-groep" (leeg als er geen zijn: de training is voor iedereen). */
function tr_ploegnamen(array $ploeg_ids): string
{
    if (!$ploeg_ids) {
        return '';
    }
    return implode(', ', db()->get_col("SELECT naam FROM " . t('ploegen') . " WHERE id IN (" . in_lijst($ploeg_ids) . ") ORDER BY naam"));
}

function tr_bewaar_moment_ploegen(int $moment_id, array $ploeg_ids): void
{
    db()->delete(t('tr_moment_ploegen'), ['moment_id' => $moment_id]);
    foreach (array_unique(array_filter(array_map('intval', $ploeg_ids))) as $p) {
        db()->insert(t('tr_moment_ploegen'), ['moment_id' => $moment_id, 'ploeg_id' => $p]);
    }
}

// ---------- seizoen ----------

/** Alle seizoenen, chronologisch. */
function tr_seizoenen(): array
{
    return db()->get_results("SELECT * FROM " . t('tr_seizoenen') . " ORDER BY van, id");
}

/**
 * Is er op $datum training mogelijk? Ja als er geen seizoenen zijn, als de dag vóór het eerste seizoen ligt
 * (de geschiedenis blijft), of als een seizoen de dag bevat. Anders (tussen twee seizoenen, na het laatste) niet.
 */
function tr_in_seizoen(string $datum, ?array $seizoenen = null): bool
{
    $seizoenen ??= tr_seizoenen();
    if (!$seizoenen || $datum < $seizoenen[0]->van) {
        return true;
    }
    foreach ($seizoenen as $s) {
        if ($s->van <= $datum && $s->tot >= $datum) {
            return true;
        }
    }
    return false;
}

/** Leesbare reden van een afgelasting: de ingevulde reden, anders de soort (bv. "verlof"). */
function tr_afgelast_tekst(object $a): string
{
    return $a->reden !== '' ? $a->reden : (TR_AFGELAST_SOORT[$a->soort] ?? 'afgelast');
}

/** Voor wie een afgelasting geldt: "Alle trainingen", "Ploeg A-groep" of de naam van de training. */
function tr_afgelast_voor(object $a): string
{
    if ((int) $a->ploeg_id) {
        $p = ploeg((int) $a->ploeg_id);
        return 'Ploeg ' . ($p ? $p->naam : '?');
    }
    if ((int) $a->moment_id) {
        $m = tr_moment((int) $a->moment_id);
        return $m ? $m->naam . ' (' . TR_DAGEN[$m->weekdag] . ')' : '?';
    }
    return 'Alle trainingen';
}

function tr_tijd(object $m): string
{
    return substr($m->start, 0, 5) . '–' . substr($m->einde, 0, 5);
}

function tr_duur_min(object $m): int
{
    [$h1, $i1] = array_map('intval', explode(':', $m->start));
    [$h2, $i2] = array_map('intval', explode(':', $m->einde));
    return max(0, ($h2 * 60 + $i2) - ($h1 * 60 + $i1));
}

function tr_uren(int $minuten): string
{
    return intdiv($minuten, 60) . ':' . sprintf('%02d', $minuten % 60);
}

/** Alle datums van een moment binnen [van, tot] (Y-m-d). */
function tr_datums(object $m, string $van, string $tot): array
{
    $begin = max($van, $m->van);
    $eind = $m->tot ? min($tot, $m->tot) : $tot;
    if ($begin > $eind) {
        return [];
    }
    $d = new \DateTimeImmutable($begin);
    $verschil = ((int) $m->weekdag - (int) $d->format('N') + 7) % 7;
    $d = $d->modify("+$verschil days");
    $uit = [];
    while ($d->format('Y-m-d') <= $eind) {
        $uit[] = $d->format('Y-m-d');
        $d = $d->modify('+7 days');
    }
    return $uit;
}

/**
 * Alle gegevens die nodig zijn om de bezetting in een periode te berekenen, in één keer opgehaald.
 */
function tr_context(string $van, string $tot): object
{
    $c = (object) ['vast' => [], 'wijz' => [], 'afgelast' => [], 'bev' => [], 'ploegen' => tr_moment_ploegen(), 'seizoenen' => tr_seizoenen()];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE van<=%s AND (tot IS NULL OR tot>=%s) ORDER BY id", $tot, $van)) as $v) {
        $c->vast[(int) $v->moment_id][] = $v;
    }
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_wijzigingen') . " WHERE datum BETWEEN %s AND %s ORDER BY id", $van, $tot)) as $w) {
        $c->wijz[(int) $w->moment_id][$w->datum][] = $w;
    }
    $c->afgelast = db()->get_results(db()->prepare("SELECT * FROM " . t('tr_afgelast') . " WHERE van<=%s AND tot>=%s", $tot, $van));
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_bevestigingen') . " WHERE datum BETWEEN %s AND %s", $van, $tot)) as $r) {
        $c->bev[(int) $r->moment_id][$r->datum][(int) $r->user_id] = $r;
    }
    $c->zw = [];
    foreach (db()->get_results(db()->prepare("SELECT moment_id, datum, ploeg_id, SUM(aanwezig) AS aanwezig, COUNT(*) AS totaal FROM " . t('tr_zwemmers')
        . " WHERE datum BETWEEN %s AND %s GROUP BY moment_id, datum, ploeg_id", $van, $tot)) as $r) {
        $c->zw[(int) $r->moment_id][$r->datum][(int) $r->ploeg_id] = [(int) $r->aanwezig, (int) $r->totaal];
    }
    return $c;
}

/**
 * Bezetting van één training: [afgelast => reden|null, aanwezig => [uid => [soort, vervangt]], afwezig => [uid => reden],
 * open => [uid van afwezige vaste trainers zonder vervanger]].
 */
function tr_bezetting(object $m, string $datum, object $c): object
{
    $b = (object) ['moment' => $m, 'datum' => $datum, 'afgelast' => null, 'aanwezig' => [], 'afwezig' => [], 'open' => [],
        'bevestigd' => $c->bev[(int) $m->id][$datum] ?? [], 'zwemmers' => $c->zw[(int) $m->id][$datum] ?? [], 'ploegen' => $c->ploegen[(int) $m->id] ?? []];
    $ploegen = $c->ploegen[(int) $m->id] ?? [];
    $verlof = [];
    foreach ($c->afgelast as $a) {
        if ($a->van > $datum || $a->tot < $datum) {
            continue;
        }
        if ((int) $a->ploeg_id) {
            $verlof[(int) $a->ploeg_id] ??= tr_afgelast_tekst($a);
        } elseif ((int) $a->moment_id === 0 || (int) $a->moment_id === (int) $m->id) {
            $b->afgelast ??= tr_afgelast_tekst($a);
        }
    }
    // Verlof per ploeg: de training valt weg als al haar ploegen verlof hebben.
    if ($b->afgelast === null && $ploegen && !array_diff($ploegen, array_keys($verlof))) {
        $b->afgelast = $verlof[$ploegen[0]];
    }
    foreach ($c->vast[(int) $m->id] ?? [] as $v) {
        if ($v->van <= $datum && (!$v->tot || $v->tot >= $datum)) {
            $b->aanwezig[(int) $v->user_id] = ['vast', null];
        }
    }
    $vervangen = [];
    foreach ($c->wijz[(int) $m->id][$datum] ?? [] as $w) {
        $uid = (int) $w->user_id;
        if ($w->soort === 'afwezig') {
            unset($b->aanwezig[$uid]);
            $b->afwezig[$uid] = $w->reden;
        } else {
            $b->aanwezig[$uid] = [$w->soort, $w->vervangt_id ? (int) $w->vervangt_id : null];
            if ($w->vervangt_id) {
                $vervangen[(int) $w->vervangt_id] = $uid;
            }
        }
    }
    foreach ($b->afwezig as $uid => $_) {
        if (!isset($vervangen[$uid])) {
            $b->open[] = $uid;
        }
    }
    $b->vervangen = $vervangen;
    return $b;
}

/** Alle trainingen in [van, tot], chronologisch, als bezettingen. */
function tr_trainingen(string $van, string $tot): array
{
    $c = tr_context($van, $tot);
    $uit = [];
    foreach (tr_momenten($van, $tot) as $m) {
        foreach (tr_datums($m, $van, $tot) as $d) {
            if (tr_in_seizoen($d, $c->seizoenen)) {
                $uit[] = tr_bezetting($m, $d, $c);
            }
        }
    }
    usort($uit, fn($a, $b) => [$a->datum, $a->moment->start, $a->moment->naam] <=> [$b->datum, $b->moment->start, $b->moment->naam]);
    return $uit;
}

/** Is $m een geldig moment op $datum (juiste weekdag, binnen de periode en binnen het seizoen)? */
function tr_geldig(object $m, string $datum): bool
{
    $d = dag($datum);
    return $d && (int) $d->format('N') === (int) $m->weekdag && $datum >= $m->van && (!$m->tot || $datum <= $m->tot) && tr_in_seizoen($datum);
}

/** De afgelaste trainingen in [van, tot], als ["moment|datum" => bezetting]. */
function tr_afgelast_staat(string $van, string $tot): array
{
    $uit = [];
    foreach ($van <= $tot ? tr_trainingen($van, $tot) : [] as $b) {
        if ($b->afgelast) {
            $uit["{$b->moment->id}|{$b->datum}"] = $b;
        }
    }
    return $uit;
}

/**
 * Voert $wijzig uit (een afgelasting toevoegen of verwijderen) en verwittigt de trainers van de komende trainingen
 * in [van, tot] die daardoor wegvallen of toch doorgaan: één melding per trainer. Geeft [afgelast, gaat toch door] terug
 * (aantal trainingen).
 */
function tr_wijzig_afgelast(callable $wijzig, string $van, string $tot, string $omschrijving): array
{
    $van = max($van, vandaag()->format('Y-m-d'));
    $voor = tr_afgelast_staat($van, $tot);
    $wijzig();
    $na = tr_afgelast_staat($van, $tot);
    $nieuw = array_diff_key($na, $voor);
    $terug = array_diff_key($voor, $na);
    $per_trainer = [];
    foreach ([[$nieuw, 'af'], [$terug, 'terug']] as [$lijst, $soort]) {
        foreach ($lijst as $b) {
            foreach (array_keys($b->aanwezig) as $uid) {
                $per_trainer[$uid][$soort][] = $b;
            }
        }
    }
    $wanneer = function (array $lijst): string {
        return count($lijst) === 1 ? tr_moment_kort($lijst[0]->moment, $lijst[0]->datum)
            : count($lijst) . ' trainingen van ' . datum($lijst[0]->datum, 'j/m') . ' tot ' . datum(end($lijst)->datum, 'j/m');
    };
    foreach ($per_trainer as $uid => $w) {
        if (!voorkeur($uid, 'push_afgelast')) {
            continue;
        }
        if (!empty($w['af'])) {
            stuur([$uid], 'Training afgelast', "$omschrijving: " . $wanneer($w['af']) . (count($w['af']) === 1 ? ' gaat' : ' gaan') . ' niet door.',
                app_url('trainers/'));
        }
        if (!empty($w['terug'])) {
            stuur([$uid], 'Training gaat toch door', $wanneer($w['terug']) . (count($w['terug']) === 1 ? ' gaat' : ' gaan') . ' toch door.',
                app_url('trainers/'));
        }
    }
    return [count($nieuw), count($terug)];
}

// ---------- aanwezigheid ----------

/** Is de training voorbij (einde verstreken)? Pas dan kan iemand bevestigen dat hij of zij aanwezig was. */
function tr_afgelopen(object $b): bool
{
    $vandaag = vandaag()->format('Y-m-d');
    return $b->datum < $vandaag || ($b->datum === $vandaag && $b->moment->einde <= current_time('H:i:s'));
}

/** Aanwezigheid van een ingeplande trainer: ja, nee, open (niet bevestigd) of gepland (de training is nog niet voorbij). */
function tr_status(object $b, int $uid): string
{
    if (!tr_afgelopen($b)) {
        return 'gepland';
    }
    $r = $b->bevestigd[$uid] ?? null;
    if ($r && $r->aanwezig !== null) {
        return (int) $r->aanwezig ? 'ja' : 'nee';
    }
    return $b->datum < (string) get_option('khzs_tr_bevestigen_vanaf', '') ? 'ja' : 'open';
}

/** Wie bevestigde en wanneer, bv. "zelf, 7/09 19:42" of "door Piet, 8/09 10:00". */
function tr_bevestigd_door(object $b, int $uid): string
{
    $r = $b->bevestigd[$uid] ?? null;
    if (!$r || $r->aanwezig === null || !$r->op) {
        return '';
    }
    $wie = $r->bron === 'bestuur' && $r->door_id ? 'door ' . tr_voornaam((int) $r->door_id) : (TR_BRON[$r->bron] ?? '');
    return trim($wie . ', ' . datum($r->op, 'j/m H:i'), ', ');
}

/** Bewaart of iemand aanwezig was (true/false), of wist het antwoord (null = terug naar niet bevestigd). */
function tr_zet_bevestiging(object $m, string $datum, int $uid, ?bool $aanwezig, int $door, string $bron): void
{
    db()->query(db()->prepare("INSERT INTO " . t('tr_bevestigingen') . " (moment_id, datum, user_id, aanwezig, door_id, bron, op) VALUES (%d, %s, %d, "
        . ($aanwezig === null ? 'NULL' : (int) $aanwezig) . ", %d, %s, %s) ON DUPLICATE KEY UPDATE aanwezig=VALUES(aanwezig), door_id=VALUES(door_id),"
        . " bron=VALUES(bron), op=VALUES(op)", $m->id, $datum, $uid, $door, $bron, nu_sql()));
}

/**
 * Noteert een aanpassing door iemand anders aan de planning of aanwezigheid van een trainer en stuurt de trainer een melding.
 * Doet niets als de trainer het zelf deed.
 */
function tr_log(int $uid, int $door, string $wat, ?object $m = null, ?string $datum = null): void
{
    if ($uid === $door) {
        return;
    }
    db()->insert(t('tr_log'), ['user_id' => $uid, 'moment_id' => $m ? (int) $m->id : 0, 'datum' => $datum, 'wat' => mb_substr($wat, 0, 255),
        'door_id' => $door, 'op' => nu_sql(), 'gelezen' => 0]);
    if (voorkeur($uid, 'push_aanpassing')) {
        stuur([$uid], 'Je planning is aangepast', tr_voornaam($door) . ': ' . $wat, app_url('trainers/wijzigingen/'));
    }
}

function tr_logboek(int $uid, bool $enkel_ongelezen = false, int $max = 100): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('tr_log') . " WHERE user_id=%d" . ($enkel_ongelezen ? ' AND gelezen=0' : '')
        . " ORDER BY op DESC, id DESC LIMIT %d", $uid, $max));
}

// ---------- zwemmers op de training ----------

/** Alle ploegen [id => ploeg], één keer per verzoek opgehaald. */
function tr_ploegen_per_id(): array
{
    static $uit = null;
    return $uit ??= array_column(tr_alle_ploegen(), null, 'id');
}

/**
 * Mag dit account de aanwezigheid van de zwemmers van deze ploeg aanduiden en bekijken? Bestuur, secretariaat en
 * beheerder altijd; een trainer van de competitie voor de ploegen van de competitie, een trainer van de zwemschool
 * voor die van de zwemschool.
 */
function tr_zw_mag_ploeg(object $ploeg, $user = null): bool
{
    if (tr_mag_beheren($user)) {
        return true;
    }
    return heeft_rol([($ploeg->soort ?? 'competitie') === 'zwemschool' ? TRAINER_ZWEMSCHOOL : TRAINER], $user);
}

/** De ploegen waarvan dit account de aanwezigheid mag zien, [id => ploeg]. */
function tr_zw_ploegen($user = null): array
{
    return array_filter(tr_ploegen_per_id(), fn($p) => tr_zw_mag_ploeg($p, $user));
}

/** De ploegen van deze training waarvan dit account de zwemmers mag aanduiden, [id => ploeg]. */
function tr_zw_ploegen_van(object $b, $user = null): array
{
    $alle = tr_ploegen_per_id();
    $uit = [];
    foreach ($b->ploegen as $id) {
        if (isset($alle[$id]) && tr_zw_mag_ploeg($alle[$id], $user)) {
            $uit[$id] = $alle[$id];
        }
    }
    return $uit;
}

/** Kan de aanwezigheid van zwemmers aangeduid worden? Vanaf de dag van de training, niet als ze afgelast is. */
function tr_zw_kan(object $b): bool
{
    return !$b->afgelast && $b->datum <= vandaag()->format('Y-m-d');
}

/** Telling voor de ploegen in $ploegen: [aanwezig, totaal], of null als nog niets aangeduid is. */
function tr_zw_telling(object $b, array $ploegen): ?array
{
    $t = null;
    foreach ($ploegen as $id => $_) {
        if (isset($b->zwemmers[$id])) {
            $t = [($t[0] ?? 0) + $b->zwemmers[$id][0], ($t[1] ?? 0) + $b->zwemmers[$id][1]];
        }
    }
    return $t;
}

/**
 * De lijst om aan te duiden: [ploeg_id => ['ploeg' => ploeg, 'zwemmers' => [zwemmer met ->aanwezig (null, 0 of 1) en ->status],
 * 'laatst' => rij|null]].
 * Per ploeg de huidige zwemmers, plus wie al aangeduid was maar intussen niet meer in de ploeg zit. Een zwemmer in
 * twee ploegen van dezelfde training staat één keer, onder de eerste ploeg.
 */
function tr_zw_lijst(object $b, array $ploegen): array
{
    $bestaand = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_zwemmers') . " WHERE moment_id=%d AND datum=%s", $b->moment->id, $b->datum)) as $r) {
        $bestaand[(int) $r->zwemmer_id] = $r;
    }
    $uit = [];
    $gezien = [];
    foreach ($ploegen as $id => $p) {
        $zwemmers = ploeg_zwemmers((int) $id);
        $ids = array_map('intval', array_column($zwemmers, 'id'));
        foreach ($bestaand as $zid => $r) {
            if ((int) $r->ploeg_id === (int) $id && !in_array($zid, $ids, true) && ($z = pl_zwemmer($zid))) {
                $zwemmers[] = $z;
            }
        }
        $lijst = [];
        foreach ($zwemmers as $z) {
            $zid = (int) $z->id;
            if (isset($gezien[$zid])) {
                continue;
            }
            $gezien[$zid] = true;
            $z->aanwezig = isset($bestaand[$zid]) ? (int) $bestaand[$zid]->aanwezig : null;
            $z->status = $bestaand[$zid]->status ?? '';
            $lijst[] = $z;
        }
        usort($lijst, fn($x, $y) => strcasecmp("$x->voornaam $x->naam", "$y->voornaam $y->naam"));
        $laatst = null;
        foreach ($bestaand as $r) {
            if ((int) $r->ploeg_id === (int) $id && (!$laatst || $r->op > $laatst->op)) {
                $laatst = $r;
            }
        }
        $uit[$id] = ['ploeg' => $p, 'zwemmers' => $lijst, 'laatst' => $laatst];
    }
    return $uit;
}

/**
 * Bewaart de hele lijst: wie in $aanwezig staat was er (1), de rest niet (0). $status = [zwemmer_id => laat|vroeger|ziek]:
 * te laat en vroeger weg zetten de zwemmer op aanwezig, ziek op afwezig. Geeft [aanwezig, totaal] terug.
 */
function tr_zw_bewaar(object $b, array $lijst, array $aanwezig, int $door, array $status = []): array
{
    $aanwezig = array_flip(array_map('intval', $aanwezig));
    $n = [0, 0];
    foreach ($lijst as $id => $l) {
        foreach ($l['zwemmers'] as $z) {
            $st = in_array($status[(int) $z->id] ?? '', TR_ZW_STATUS, true) ? $status[(int) $z->id] : '';
            $ja = $st === 'ziek' ? 0 : ($st !== '' || isset($aanwezig[(int) $z->id]) ? 1 : 0);
            db()->query(db()->prepare("INSERT INTO " . t('tr_zwemmers') . " (moment_id, datum, zwemmer_id, ploeg_id, aanwezig, status, door_id, op)"
                . " VALUES (%d, %s, %d, %d, %d, %s, %d, %s) ON DUPLICATE KEY UPDATE ploeg_id=VALUES(ploeg_id), aanwezig=VALUES(aanwezig),"
                . " status=VALUES(status), door_id=VALUES(door_id), op=VALUES(op)", $b->moment->id, $b->datum, $z->id, $id, $ja, $st, $door, nu_sql()));
            $n[0] += $ja;
            $n[1]++;
        }
    }
    return $n;
}

/** Wist de aanduiding van deze ploegen op deze training (terug naar 'niet aangeduid'). */
function tr_zw_wis(object $b, array $ploegen): void
{
    if ($ploegen) {
        db()->query(db()->prepare("DELETE FROM " . t('tr_zwemmers') . " WHERE moment_id=%d AND datum=%s AND ploeg_id IN (" . in_lijst(array_keys($ploegen)) . ")",
            $b->moment->id, $b->datum));
    }
}

/** aanwezig, laat, vroeger, ziek of afwezig (een sleutel van TR_ZW_SOORT) voor een rij uit tr_zwemmers. */
function tr_zw_soort(object $r): string
{
    if (in_array($r->status ?? '', TR_ZW_STATUS, true)) {
        return $r->status;
    }
    return (int) $r->aanwezig ? 'aanwezig' : 'afwezig';
}

/**
 * Rijen uit tr_zwemmers in [van, tot], met zwemmer, training en ->soort. Beperkt tot de ploegen $ploeg_ids (null = alle)
 * en eventueel tot één zwemmer. Chronologisch.
 */
function tr_zw_rijen(string $van, string $tot, ?array $ploeg_ids = null, ?int $zwemmer = null): array
{
    if ($ploeg_ids === []) {
        return [];
    }
    $sql = db()->prepare("SELECT a.*, z.voornaam, z.naam AS znaam, m.naam AS mnaam, m.start, m.einde, m.weekdag FROM " . t('tr_zwemmers') . " a JOIN "
        . t('zwemmers') . " z ON z.id=a.zwemmer_id LEFT JOIN " . t('tr_momenten') . " m ON m.id=a.moment_id WHERE a.datum BETWEEN %s AND %s", $van, $tot);
    if ($ploeg_ids !== null) {
        $sql .= " AND a.ploeg_id IN (" . in_lijst($ploeg_ids) . ")";
    }
    if ($zwemmer !== null) {
        $sql .= db()->prepare(" AND a.zwemmer_id=%d", $zwemmer);
    }
    $rijen = db()->get_results("$sql ORDER BY a.datum, m.start, z.voornaam, z.naam");
    foreach ($rijen as $r) {
        $r->soort = tr_zw_soort($r);
    }
    return $rijen;
}

/** Lege telling: één teller per soort plus totaal en het aantal verschillende trainingen. */
function tr_zw_leeg(): array
{
    return array_fill_keys(array_keys(TR_ZW_SOORT), 0) + ['totaal' => 0, 'trainingen' => 0, '_t' => []];
}

function tr_zw_tel_bij(array &$t, object $r): void
{
    $t[$r->soort]++;
    $t['totaal']++;
    if (!isset($t['_t']["$r->moment_id|$r->datum"])) {
        $t['_t']["$r->moment_id|$r->datum"] = true;
        $t['trainingen']++;
    }
}

/** Aantal keer er (aanwezig, te laat of vroeger weg). */
function tr_zw_er(array $t): int
{
    return $t['aanwezig'] + $t['laat'] + $t['vroeger'];
}

/** Percentage aanwezig (te laat en vroeger weg tellen mee), of zonder de trainingen waarop de zwemmer ziek was. */
function tr_zw_pct(array $t, bool $zonder_ziek = false): ?float
{
    $noemer = $t['totaal'] - ($zonder_ziek ? $t['ziek'] : 0);
    return $noemer > 0 ? 100 * tr_zw_er($t) / $noemer : null;
}

function tr_zw_procent(array $t, bool $zonder_ziek = false): string
{
    $p = tr_zw_pct($t, $zonder_ziek);
    return $p === null ? '—' : round($p) . ' %';
}

/**
 * Statistiek van een reeks rijen (tr_zw_rijen): totaal, per ploeg, per zwemmer, per maand, per training, per weekdag
 * en de verdeling van de zwemmers volgens hun percentage. Elke telling is tr_zw_leeg() met extra velden (naam…).
 */
function tr_zw_stats(array $rijen): array
{
    $alle = tr_ploegen_per_id();
    $s = ['totaal' => tr_zw_leeg(), 'ploegen' => [], 'zwemmers' => [], 'maanden' => [], 'momenten' => [], 'weekdagen' => [], 'verdeling' => [],
        'detail' => $rijen];
    foreach ($rijen as $r) {
        $zid = (int) $r->zwemmer_id;
        $pid = (int) $r->ploeg_id;
        tr_zw_tel_bij($s['totaal'], $r);
        $s['ploegen'][$pid] ??= tr_zw_leeg() + ['naam' => $alle[$pid]->naam ?? '?', 'soort' => $alle[$pid]->soort ?? ''];
        tr_zw_tel_bij($s['ploegen'][$pid], $r);
        $s['zwemmers'][$zid] ??= tr_zw_leeg() + ['naam' => trim("$r->voornaam $r->znaam"), 'ploegen' => []];
        $s['zwemmers'][$zid]['ploegen'][$pid] = $alle[$pid]->naam ?? '?';
        tr_zw_tel_bij($s['zwemmers'][$zid], $r);
        $s['maanden'][substr($r->datum, 0, 7)] ??= tr_zw_leeg();
        tr_zw_tel_bij($s['maanden'][substr($r->datum, 0, 7)], $r);
        $mk = (int) $r->moment_id;
        $s['momenten'][$mk] ??= tr_zw_leeg() + ['naam' => (string) $r->mnaam, 'wanneer' => $r->weekdag ? ucfirst(TR_DAGEN[(int) $r->weekdag]) . ' ' . substr($r->start, 0, 5) : ''];
        tr_zw_tel_bij($s['momenten'][$mk], $r);
        $wd = (int) (new \DateTimeImmutable($r->datum))->format('N');
        $s['weekdagen'][$wd] ??= tr_zw_leeg();
        tr_zw_tel_bij($s['weekdagen'][$wd], $r);
    }
    ksort($s['maanden']);
    ksort($s['weekdagen']);
    uasort($s['ploegen'], fn($a, $b) => strcasecmp($a['naam'], $b['naam']));
    uasort($s['zwemmers'], fn($a, $b) => strcasecmp($a['naam'], $b['naam']));
    uasort($s['momenten'], fn($a, $b) => strcasecmp($a['naam'], $b['naam']));
    $s['verdeling'] = ['90 % of meer' => 0, '75 tot 90 %' => 0, '50 tot 75 %' => 0, 'minder dan 50 %' => 0];
    foreach ($s['zwemmers'] as $z) {
        $p = tr_zw_pct($z) ?? 0;
        $s['verdeling'][$p >= 90 ? '90 % of meer' : ($p >= 75 ? '75 tot 90 %' : ($p >= 50 ? '50 tot 75 %' : 'minder dan 50 %'))]++;
    }
    return $s;
}

/**
 * Reeksen van één zwemmer (rijen chronologisch): huidige en langste reeks trainingen na elkaar aanwezig (ziek onderbreekt
 * niet en telt niet mee), en de laatste datum dat de zwemmer er was.
 */
function tr_zw_reeksen(array $rijen): array
{
    $huidig = $langst = 0;
    $laatst = null;
    foreach ($rijen as $r) {
        if ($r->soort === 'ziek') {
            continue;
        }
        if ($r->soort === 'afwezig') {
            $huidig = 0;
            continue;
        }
        $huidig++;
        $langst = max($langst, $huidig);
        $laatst = $r->datum;
    }
    return ['huidig' => $huidig, 'langst' => $langst, 'laatst' => $laatst];
}

/**
 * Mag dit account de aanwezigheden van deze zwemmer zien? De zwemmer zelf en de gekoppelde accounts (ouders) altijd;
 * trainers en bestuur als ze een ploeg van de zwemmer mogen zien. Geeft null (geen toegang), [] (alles) of de
 * ploeg-id's waartoe de trainer zich moet beperken.
 */
function tr_zw_mag_zwemmer(object $z, $user = null): ?array
{
    $u = gebruiker($user);
    if (!$u) {
        return null;
    }
    if (heeft_account($z, (int) $u->ID) || tr_mag_beheren($u)) {
        return [];
    }
    $ids = array_map('intval', db()->get_col(db()->prepare("SELECT ploeg_id FROM " . t('ploegleden') . " WHERE zwemmer_id=%d UNION SELECT DISTINCT ploeg_id FROM "
        . t('tr_zwemmers') . " WHERE zwemmer_id=%d", $z->id, $z->id)));
    $mag = array_values(array_intersect($ids, array_keys(tr_zw_ploegen($u))));
    return $mag ?: null;
}

/** Standaardperiode: het lopende seizoen, anders de laatste drie maanden; ?van= en ?tot= overschrijven. */
function tr_zw_periode(): array
{
    $vandaag = vandaag()->format('Y-m-d');
    $van = dag(get('van'));
    $tot = dag(get('tot'));
    if (!$van) {
        $seizoen = current(array_filter(tr_seizoenen(), fn($s) => $s->van <= $vandaag && $s->tot >= $vandaag));
        $van = new \DateTimeImmutable($seizoen ? $seizoen->van : vandaag()->modify('-3 months')->format('Y-m-d'));
    }
    $tot ??= vandaag();
    if ($tot < $van) {
        [$van, $tot] = [$tot, $van];
    }
    return [$van->format('Y-m-d'), $tot->format('Y-m-d')];
}

// ---------- vraag na de training (pushmelding met Ja/Nee) ----------

/** Token in de melding: laat toe met één tik te antwoorden, zonder aan te melden. Enkel geldig voor deze trainer en training. */
function tr_token(int $uid, int $moment, string $datum): string
{
    $data = "$uid.$moment.$datum";
    return $data . '.' . substr(hash_hmac('sha256', "tr_bevestig|$data", wp_salt('auth')), 0, 32);
}

/** [uid, moment_id, datum] of null. Een token verloopt 60 dagen na de training. */
function tr_token_lees(string $token): ?array
{
    if (!preg_match('/^(\d+)\.(\d+)\.(\d{4}-\d{2}-\d{2})\.([a-f0-9]{32})$/', $token, $t)) {
        return null;
    }
    if (!hash_equals(tr_token((int) $t[1], (int) $t[2], $t[3]), $token) || $t[3] < vandaag()->modify('-60 days')->format('Y-m-d')) {
        return null;
    }
    return [(int) $t[1], (int) $t[2], $t[3]];
}

function tr_moment_kort(object $m, string $datum): string
{
    return "{$m->naam} " . datum($datum, 'D j/m') . ' ' . tr_tijd($m);
}

/** Bericht voor de service worker: titel, tekst, adres van het antwoordscherm en twee knoppen die meteen antwoorden. */
function tr_vraag_bericht(int $uid, object $m, string $datum, bool $herinnering): array
{
    $token = tr_token($uid, (int) $m->id, $datum);
    $post = app_url('trainers/bevestig/');
    return [
        'titel' => $herinnering ? 'Nog niet bevestigd: was je aanwezig?' : 'Was je aanwezig?',
        'tekst' => tr_moment_kort($m, $datum),
        'url' => app_url('trainers/bevestig/', ['t' => $token]),
        'tag' => "tr-{$m->id}-$datum",
        'acties' => [
            ['actie' => 'ja', 'titel' => '✓ Ja', 'post' => $post, 'velden' => ['t' => $token, 'antwoord' => 'ja', 'json' => '1']],
            ['actie' => 'nee', 'titel' => '✗ Nee', 'post' => $post, 'velden' => ['t' => $token, 'antwoord' => 'nee', 'json' => '1']],
        ],
    ];
}

/** Stuurt meteen (we zitten al in een geplande taak). Geeft het aantal toestellen terug. */
function tr_stuur_bericht(int $uid, array $bericht): int
{
    $ids = array_map('intval', db()->get_col(db()->prepare("SELECT id FROM " . t('push') . " WHERE user_id=%d", $uid)));
    verstuur_abonnementen($ids, $bericht);
    return count($ids);
}

/**
 * Geplande taak (elk kwartier): vraagt na afloop van elke training aan de ingeplande trainers of ze aanwezig waren,
 * en stuurt één herinnering als er na 12 uur nog geen antwoord is (niet 's nachts: enkel tussen 8 en 21 uur).
 * Kijkt 2 dagen terug. Geeft het aantal verstuurde vragen en herinneringen terug (voor tests).
 */
function tr_vraag_aanwezigheid(?string $nu = null): array
{
    $tz = wp_timezone();
    $nu = new \DateTimeImmutable($nu ?: 'now', $tz);
    $uit = ['gevraagd' => 0, 'herinnerd' => 0];
    $van = max($nu->modify('-2 days')->format('Y-m-d'), (string) get_option('khzs_tr_bevestigen_vanaf', ''));
    $tot = $nu->format('Y-m-d');
    $uur = (int) $nu->format('G');
    foreach ($van <= $tot ? tr_trainingen($van, $tot) : [] as $b) {
        if ($b->afgelast || new \DateTimeImmutable("{$b->datum} {$b->moment->einde}", $tz) > $nu) {
            continue;
        }
        foreach (array_keys($b->aanwezig) as $uid) {
            $r = $b->bevestigd[$uid] ?? null;
            if (!$r) {
                db()->query(db()->prepare("INSERT IGNORE INTO " . t('tr_bevestigingen') . " (moment_id, datum, user_id, gevraagd) VALUES (%d, %s, %d, %s)",
                    $b->moment->id, $b->datum, $uid, $nu->format('Y-m-d H:i:s')));
                if (db()->rows_affected && voorkeur($uid, 'push_aanwezig') && tr_stuur_bericht($uid, tr_vraag_bericht($uid, $b->moment, $b->datum, false))) {
                    $uit['gevraagd']++;
                }
            } elseif ($r->aanwezig === null && $r->gevraagd && !$r->herinnerd && $uur >= 8 && $uur < 21
                && new \DateTimeImmutable($r->gevraagd, $tz) <= $nu->modify('-12 hours')) {
                db()->update(t('tr_bevestigingen'), ['herinnerd' => $nu->format('Y-m-d H:i:s')], ['id' => $r->id]);
                if (voorkeur($uid, 'push_aanwezig') && tr_stuur_bericht($uid, tr_vraag_bericht($uid, $b->moment, $b->datum, true))) {
                    $uit['herinnerd']++;
                }
            }
        }
    }
    return $uit;
}

/** Trainers: iedereen uit TR_STAF (beide soorten trainers, sportsecretaris, beheerder), plus wie ooit ingepland was. */
function tr_trainers(): array
{
    $ids = array_map('intval', db()->get_col("SELECT DISTINCT user_id FROM " . t('tr_vast') . " UNION SELECT DISTINCT user_id FROM " . t('tr_wijzigingen')
        . " UNION SELECT DISTINCT user_id FROM " . t('tr_tarieven')));
    $uit = [];
    foreach (get_users(['fields' => 'all']) as $u) {
        if (tr_is_trainer($u) || in_array($u->ID, $ids, true)) {
            $uit[$u->ID] = $u;
        }
    }
    uasort($uit, fn($a, $b) => strcasecmp(tr_naam($a), tr_naam($b)));
    return $uit;
}

function tr_naam($u): string
{
    $u = is_object($u) ? $u : get_userdata((int) $u);
    if (!$u) {
        return '(verwijderd)';
    }
    return volle_naam($u) ?: $u->display_name ?: $u->user_login;
}

function tr_voornaam($u): string
{
    $u = is_object($u) ? $u : get_userdata((int) $u);
    return $u ? ($u->first_name ?: tr_naam($u)) : '(verwijderd)';
}

// ---------- verloning ----------

/** Tarieven van een trainer, nieuwste eerst. */
function tr_tarieven(int $uid): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('tr_tarieven') . " WHERE user_id=%d ORDER BY geldig_vanaf DESC, id DESC", $uid));
}

/** Het tarief dat geldt op $datum (of null). $tarieven = tr_tarieven(). */
function tr_tarief_op(array $tarieven, string $datum): ?object
{
    foreach ($tarieven as $t) {
        if ($t->geldig_vanaf <= $datum) {
            return $t;
        }
    }
    return null;
}

function tr_bedrag(?object $tarief, int $minuten): int
{
    if (!$tarief) {
        return 0;
    }
    return $tarief->soort === 'beurt' ? (int) $tarief->bedrag_cent : (int) round($tarief->bedrag_cent * $minuten / 60);
}

function tr_maand_grenzen(string $maand): array
{
    $begin = \DateTimeImmutable::createFromFormat('!Y-m', $maand) ?: new \DateTimeImmutable('first day of this month');
    return [$begin->format('Y-m-01'), $begin->format('Y-m-t')];
}

/**
 * Overzicht van een maand per trainer: [uid => [user, trainingen, minuten, bedrag_cent, zonder_tarief, gepland, nee, open,
 * open_minuten, regels[], andere[]]].
 * Enkel trainingen tot en met vandaag met 'ja' tellen mee (regels). 'Niet aanwezig' en 'niet bevestigd' staan in andere[];
 * latere trainingen staan als 'gepland'.
 */
function tr_maand(string $maand, ?int $enkel = null): array
{
    [$van, $tot] = tr_maand_grenzen($maand);
    $uit = [];
    $tarieven = [];
    foreach (tr_trainingen($van, $tot) as $b) {
        if ($b->afgelast) {
            continue;
        }
        foreach ($b->aanwezig as $uid => [$soort, $vervangt]) {
            if ($enkel !== null && $uid !== $enkel) {
                continue;
            }
            $uit[$uid] ??= tr_maand_leeg(get_userdata($uid));
            $status = tr_status($b, $uid);
            if ($status === 'gepland') {
                $uit[$uid]['gepland']++;
                continue;
            }
            $minuten = tr_duur_min($b->moment);
            if ($status !== 'ja') {
                $uit[$uid][$status]++;
                $uit[$uid]['open_minuten'] += $status === 'open' ? $minuten : 0;
                $uit[$uid]['andere'][] = ['datum' => $b->datum, 'moment' => $b->moment, 'soort' => $soort, 'vervangt' => $vervangt,
                    'minuten' => $minuten, 'status' => $status];
                continue;
            }
            $tarieven[$uid] ??= tr_tarieven($uid);
            $tarief = tr_tarief_op($tarieven[$uid], $b->datum);
            $bedrag = tr_bedrag($tarief, $minuten);
            $uit[$uid]['trainingen']++;
            $uit[$uid]['minuten'] += $minuten;
            $uit[$uid]['bedrag_cent'] += $bedrag;
            $uit[$uid]['zonder_tarief'] += $tarief ? 0 : 1;
            $uit[$uid]['regels'][] = ['datum' => $b->datum, 'moment' => $b->moment, 'soort' => $soort, 'vervangt' => $vervangt,
                'minuten' => $minuten, 'tarief' => $tarief, 'bedrag_cent' => $bedrag];
        }
    }
    uasort($uit, fn($a, $b) => strcasecmp(tr_naam($a['user']), tr_naam($b['user'])));
    return $uit;
}

function tr_maand_leeg($user): array
{
    return ['user' => $user, 'trainingen' => 0, 'minuten' => 0, 'bedrag_cent' => 0, 'zonder_tarief' => 0, 'gepland' => 0,
        'nee' => 0, 'open' => 0, 'open_minuten' => 0, 'regels' => [], 'andere' => []];
}

function tr_uitbetaald(string $maand): array
{
    $uit = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_uitbetaald') . " WHERE maand=%s", $maand)) as $r) {
        $uit[(int) $r->user_id] = $r;
    }
    return $uit;
}

function tr_maandnaam(string $maand): string
{
    [$y, $m] = array_map('intval', explode('-', $maand));
    return MAANDEN[$m] . " $y";
}
