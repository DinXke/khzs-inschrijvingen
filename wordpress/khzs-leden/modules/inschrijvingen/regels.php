<?php
/** Module inschrijvingen — bedrijfsregels: leeftijd, geschiktheid, limieten, deadlines en tijden. Tijden zijn gehele honderdsten. */

namespace KHZS;

defined('ABSPATH') || exit;

const GESLACHT = ['M' => 'Man', 'F' => 'Vrouw'];
const GESLACHT_NUMMER = ['M' => 'Man', 'F' => 'Vrouw', 'X' => 'Gemengd'];
const BAAN = ['SCM' => '25 m', 'LCM' => '50 m'];
const SLAG = ['FREE' => 'Vrije slag', 'BACK' => 'Rugslag', 'BREAST' => 'Schoolslag', 'FLY' => 'Vlinderslag', 'MEDLEY' => 'Wisselslag'];


const W_CONCEPT = 'concept', W_OPEN = 'open', W_GESLOTEN = 'gesloten', W_DOORGESTUURD = 'doorgestuurd';
const W_STATUS = [W_CONCEPT => 'Concept', W_OPEN => 'Open', W_GESLOTEN => 'Gesloten', W_DOORGESTUURD => 'Doorgestuurd'];

const INGEDIEND = 'ingediend', GOEDGEKEURD = 'goedgekeurd', AFGEKEURD = 'afgekeurd';
const I_STATUS = [INGEDIEND => 'Ingediend', GOEDGEKEURD => 'Goedgekeurd', AFGEKEURD => 'Afgekeurd'];

const INTERESSE = 'interesse', DOET_MEE = 'doet_mee', DOET_NIET_MEE = 'doet_niet_mee';
const KEUZES = [INTERESSE => 'Interesse', DOET_MEE => 'Ik doe mee', DOET_NIET_MEE => 'Ik doe niet mee'];
const ACTIEF = [INTERESSE, DOET_MEE];

add_filter('khzs_voorkeuren', fn($v) => $v + [
    'push_nieuwe_wedstrijd' => ['Nieuwe wedstrijd open voor inschrijving', true, false],
    'push_deadline' => ['Herinnering: 1 dag voor de deadline', true, false],
    'push_wijziging' => ['Trainer/secretariaat wijzigt mijn inschrijving', true, false],
    'push_nieuwe_inschrijving' => ['(staf) Nieuwe inschrijving om na te kijken', false, true],
]);


function deadline_trainer(object $w): \DateTimeImmutable
{
    return dag($w->deadline)->modify('-' . (int) $w->dagen_secretaris . ' days');
}

function deadline_zwemmer(object $w): \DateTimeImmutable
{
    return deadline_trainer($w)->modify('-' . (int) $w->dagen_trainer . ' days');
}

// ---------- tijden ----------

/** Aanvaardt '1:02.86', '62.86', '1.02.86', '01:02,86' → honderdsten. */
function parse_invoer_tijd(string $s): int
{
    $s = str_replace(',', '.', trim($s));
    $delen = explode('.', str_replace(':', '.', $s));
    $ok = count($delen) >= 2 && count($delen) <= 3;
    foreach ($delen as $p) {
        $ok = $ok && $p !== '' && ctype_digit($p);
    }
    if (!$ok) {
        throw new \InvalidArgumentException('Gebruik het formaat m:ss.hh, bv. 1:02.86');
    }
    if (count($delen) === 2) {
        [$m, $sec, $hon] = [0, (int) $delen[0], $delen[1]];
    } else {
        [$m, $sec, $hon] = [(int) $delen[0], (int) $delen[1], $delen[2]];
    }
    if ($sec >= 60 && $m) {
        throw new \InvalidArgumentException('Seconden moeten kleiner dan 60 zijn.');
    }
    return $m * 6000 + $sec * 100 + (int) substr(str_pad($hon, 2, '0'), 0, 2);
}

function toon_tijd($hon): string
{
    if ($hon === null || $hon === '') {
        return 'NT';
    }
    $hon = (int) $hon;
    $m = intdiv($hon, 6000);
    $rest = $hon % 6000;
    $s = intdiv($rest, 100);
    $h = $rest % 100;
    return $m ? sprintf('%d:%02d.%02d', $m, $s, $h) : sprintf('%d.%02d', $s, $h);
}


// ---------- nummers ----------

function omschrijving(object $n): string
{
    if ($n->naam) {
        return $n->naam;
    }
    $afst = $n->aflossing > 1 ? "{$n->aflossing}x{$n->afstand}" : (string) $n->afstand;
    return "$afst m " . mb_strtolower(SLAG[$n->slag] ?? $n->slag);
}

function groep_tekst(object $g): string
{
    [$a, $b] = [(int) $g->agemin, (int) $g->agemax];
    if ($a === -1 && $b === -1) {
        return 'open';
    }
    if ($b === -1) {
        return "$a+";
    }
    if ($a === -1) {
        return "t/m $b";
    }
    return $a !== $b ? "$a-$b" : "$a";
}

function groep_bevat(object $g, int $jaren): bool
{
    return ((int) $g->agemin === -1 || $jaren >= (int) $g->agemin) && ((int) $g->agemax === -1 || $jaren <= (int) $g->agemax);
}

// ---------- geschiktheid ----------

/** Leeftijd volgens de AGEDATE van de wedstrijd. Type YEAR = geboortejaar telt. */
function leeftijd(object $zwemmer, object $w): int
{
    $ref = dag($w->agedate) ?? startdatum($w) ?? vandaag();
    $gd = dag($zwemmer->geboortedatum);
    $jaren = (int) $ref->format('Y') - (int) $gd->format('Y');
    if (strtoupper($w->agedate_type) === 'YEAR') {
        return $jaren;
    }
    return $jaren - ($ref->format('md') < $gd->format('md') ? 1 : 0);
}

function past_in_nummer(object $zwemmer, object $nummer, int $jaren): bool
{
    if (!in_array($nummer->geslacht, ['X', $zwemmer->geslacht], true)) {
        return false;
    }
    foreach ($nummer->groepen as $g) {
        if (groep_bevat($g, $jaren)) {
            return true;
        }
    }
    return false;
}

function geldige_tijden(array $tijden, object $w, int $afstand, string $slag): array
{
    $van = $w->kwalificatie_van ? dag($w->kwalificatie_van)->format('Y-m-d') : null;
    $tot = $w->kwalificatie_tot ? dag($w->kwalificatie_tot)->format('Y-m-d') : null;
    return array_values(array_filter($tijden, fn($t) => (int) $t->afstand === $afstand && $t->slag === $slag
        && (!$van || $t->datum >= $van) && (!$tot || $t->datum <= $tot)));
}

/**
 * Beoordeling van één nummer voor een zwemmer: is een limiet nodig, is ze gehaald, welke beste tijd dient als inschrijftijd.
 * $lijsten = de gekozen limietlijsten met ->limieten.
 */
function beoordeel(object $zwemmer, object $nummer, object $w, int $jaren, array $lijsten, array $tijden): object
{
    $tijden = geldige_tijden($tijden, $w, (int) $nummer->afstand, $nummer->slag);
    $limieten = [];
    foreach ($lijsten as $lst) {
        if (!in_array($lst->geslacht, ['X', $zwemmer->geslacht], true)) {
            continue;
        }
        if (!groep_bevat($lst, $jaren)) {
            continue;
        }
        foreach ($lst->limieten as $l) {
            if ((int) $l->afstand === (int) $nummer->afstand && $l->slag === $nummer->slag) {
                $limieten[] = ['naam' => $lst->naam, 'baan' => $lst->baan, 'tijd' => (int) $l->tijd, 'minimum' => $lst->soort === 'MINIMUM'];
            }
        }
    }
    $gehaald = false;
    foreach ($limieten as $l) {
        foreach ($tijden as $t) {
            if ($t->baan === $l['baan'] && ($l['minimum'] ? (int) $t->tijd >= $l['tijd'] : (int) $t->tijd <= $l['tijd'])) {
                $gehaald = true;
            }
        }
    }
    // Inschrijftijd: beste tijd in de baan van de wedstrijd, anders beste tijd in de andere baan.
    $zelfde = array_filter($tijden, fn($t) => $t->baan === $w->baan);
    $andere = array_filter($tijden, fn($t) => $t->baan !== $w->baan);
    $sorteer = function ($l) {
        usort($l, fn($a, $b) => (int) $a->tijd <=> (int) $b->tijd);
        return $l;
    };
    $beste = ($sorteer($zelfde) ?: $sorteer($andere) ?: [null])[0];
    $limiet_nodig = (bool) $limieten;
    return (object) [
        'nummer' => $nummer,
        'limiet_nodig' => $limiet_nodig,
        'limiet_gehaald' => $gehaald,
        'limieten' => $limieten,
        'beste' => $beste,
        'mag_inschrijven' => !$limiet_nodig || $gehaald,
    ];
}

/** Alle inschrijfbare nummers waarvoor de zwemmer qua geslacht/leeftijd in aanmerking komt: [jaren, [[sessie, [beoordeling…]]…]]. */
function programma_voor(object $zwemmer, object $w): array
{
    $jaren = leeftijd($zwemmer, $w);
    $lijsten = gekozen_limietlijsten((int) $w->id);
    $tijden = tijden_van((int) $zwemmer->id);
    $resultaat = [];
    foreach (sessies_met_nummers((int) $w->id) as $sessie) {
        $rijen = [];
        foreach ($sessie->nummers as $n) {
            if ($n->inschrijfbaar && (int) $n->aflossing === 1 && past_in_nummer($zwemmer, $n, $jaren)) {
                $rijen[] = beoordeel($zwemmer, $n, $w, $jaren, $lijsten, $tijden);
            }
        }
        if ($rijen) {
            $resultaat[] = [$sessie, $rijen];
        }
    }
    return [$jaren, $resultaat];
}

/** Mag deze gebruiker nu nog inschrijvingen toevoegen/schrappen voor deze wedstrijd? */
function mag_wijzigen($user, object $w, ?\DateTimeImmutable $dag = null): bool
{
    $dag = $dag ?? vandaag();
    if (heeft_rol(SECRETARIAAT, $user)) {
        return in_array($w->status, [W_OPEN, W_GESLOTEN], true);
    }
    if ($w->status !== W_OPEN) {
        return false;
    }
    if (heeft_rol([TRAINER], $user)) {
        return $dag <= deadline_trainer($w);
    }
    return $dag <= deadline_zwemmer($w);
}
