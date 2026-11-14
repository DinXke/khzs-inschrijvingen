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
 * Gepresteerde uren = alle trainingen tot en met vandaag waarop iemand aanwezig was, behalve afgelaste.
 */

namespace KHZS;

defined('ABSPATH') || exit;

const TR_DAGEN = [1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag'];
const TR_SOORT = ['vast' => 'vaste trainer', 'vervanger' => 'vervanger', 'extra' => 'extra'];
const TR_TARIEF = ['uur' => 'per uur', 'beurt' => 'per training'];

add_filter('khzs_voorkeuren', fn($v) => $v + [
    'push_vervanging' => ['(trainers) Een collega zoekt een vervanger, of iemand neemt mijn training over', true, true],
]);

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
    $c = (object) ['vast' => [], 'wijz' => [], 'afgelast' => []];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE van<=%s AND (tot IS NULL OR tot>=%s) ORDER BY id", $tot, $van)) as $v) {
        $c->vast[(int) $v->moment_id][] = $v;
    }
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_wijzigingen') . " WHERE datum BETWEEN %s AND %s ORDER BY id", $van, $tot)) as $w) {
        $c->wijz[(int) $w->moment_id][$w->datum][] = $w;
    }
    $c->afgelast = db()->get_results(db()->prepare("SELECT * FROM " . t('tr_afgelast') . " WHERE van<=%s AND tot>=%s", $tot, $van));
    return $c;
}

/**
 * Bezetting van één training: [afgelast => reden|null, aanwezig => [uid => [soort, vervangt]], afwezig => [uid => reden],
 * open => [uid van afwezige vaste trainers zonder vervanger]].
 */
function tr_bezetting(object $m, string $datum, object $c): object
{
    $b = (object) ['moment' => $m, 'datum' => $datum, 'afgelast' => null, 'aanwezig' => [], 'afwezig' => [], 'open' => []];
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
 * Overzicht van een maand per trainer: [uid => [user, trainingen, minuten, bedrag_cent, zonder_tarief, gepland, regels[]]].
 * Enkel trainingen tot en met vandaag tellen mee; latere staan als 'gepland'.
 */
function tr_maand(string $maand, ?int $enkel = null): array
{
    [$van, $tot] = tr_maand_grenzen($maand);
    $vandaag = vandaag()->format('Y-m-d');
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
            $uit[$uid] ??= ['user' => get_userdata($uid), 'trainingen' => 0, 'minuten' => 0, 'bedrag_cent' => 0, 'zonder_tarief' => 0, 'gepland' => 0, 'regels' => []];
            if ($b->datum > $vandaag) {
                $uit[$uid]['gepland']++;
                continue;
            }
            $tarieven[$uid] ??= tr_tarieven($uid);
            $minuten = tr_duur_min($b->moment);
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
