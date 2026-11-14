<?php
/**
 * Module trainers — planning en verloning.
 *
 * Een trainingsmoment is een vaste training per week (bv. A-groep, maandag 18:00–19:30). Vaste trainers duiden aan
 * dat ze een moment normaal geven (tr_vast, met een periode). Per datum kan dat wijzigen (tr_wijzigingen):
 *   afwezig   — een vaste trainer kan niet
 *   vervanger — iemand neemt de plaats van een afwezige trainer over (vervangt_id)
 *   extra     — iemand komt helpen
 * Afgelaste trainingen (vakantie, zwembad dicht) staan in tr_afgelast (per moment of voor alle momenten: moment_id 0).
 *
 * Aanwezigheid (tr_bevestigingen): na elke training vraagt een pushmelding aan elke ingeplande trainer "Was je aanwezig?".
 *   ja             — de training telt mee voor de uren en de verloning
 *   nee            — telt niet mee
 *   niet bevestigd — telt (nog) niet mee; staat apart in de overzichten tot de trainer of het bestuur ze bevestigt
 * Trainingen vóór de optie khzs_tr_bevestigen_vanaf (bestaande installaties) tellen mee zonder bevestiging.
 * Gepresteerde uren = trainingen tot en met vandaag, niet afgelast, waarop de trainer ingepland stond en 'ja' bevestigde.
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
    'push_vervanging' => ['(trainers) Een collega zoekt een vervanger, of iemand neemt mijn training over', true, true],
    'push_aanwezig' => ['(trainers) Na een training: was je aanwezig?', true, true],
    'push_aanpassing' => ['(trainers) Iemand van het bestuur past mijn planning of aanwezigheid aan', true, true],
]);

const TR_STATUS = ['ja' => 'aanwezig', 'nee' => 'niet aanwezig', 'open' => 'niet bevestigd', 'gepland' => 'gepland'];
const TR_BRON = ['trainer' => 'zelf', 'melding' => 'via de melding', 'bestuur' => 'door het bestuur'];

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
    $c = (object) ['vast' => [], 'wijz' => [], 'afgelast' => [], 'bev' => []];
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
    return $c;
}

/**
 * Bezetting van één training: [afgelast => reden|null, aanwezig => [uid => [soort, vervangt]], afwezig => [uid => reden],
 * open => [uid van afwezige vaste trainers zonder vervanger]].
 */
function tr_bezetting(object $m, string $datum, object $c): object
{
    $b = (object) ['moment' => $m, 'datum' => $datum, 'afgelast' => null, 'aanwezig' => [], 'afwezig' => [], 'open' => [],
        'bevestigd' => $c->bev[(int) $m->id][$datum] ?? []];
    foreach ($c->afgelast as $a) {
        if (((int) $a->moment_id === 0 || (int) $a->moment_id === (int) $m->id) && $a->van <= $datum && $a->tot >= $datum) {
            $b->afgelast = $a->reden ?: 'afgelast';
        }
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
            $uit[] = tr_bezetting($m, $d, $c);
        }
    }
    usort($uit, fn($a, $b) => [$a->datum, $a->moment->start, $a->moment->naam] <=> [$b->datum, $b->moment->start, $b->moment->naam]);
    return $uit;
}

/** Is $m een geldig moment op $datum (juiste weekdag, binnen de periode)? */
function tr_geldig(object $m, string $datum): bool
{
    $d = dag($datum);
    return $d && (int) $d->format('N') === (int) $m->weekdag && $datum >= $m->van && (!$m->tot || $datum <= $m->tot);
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

/** Trainers: iedereen met een staf-rol (trainer, sportsecretaris, beheerder), plus wie ooit ingepland was. */
function tr_trainers(): array
{
    $ids = array_map('intval', db()->get_col("SELECT DISTINCT user_id FROM " . t('tr_vast') . " UNION SELECT DISTINCT user_id FROM " . t('tr_wijzigingen')
        . " UNION SELECT DISTINCT user_id FROM " . t('tr_tarieven')));
    $uit = [];
    foreach (get_users(['fields' => 'all']) as $u) {
        if (is_staf($u) || in_array($u->ID, $ids, true)) {
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
