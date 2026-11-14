<?php
/** Module inschrijvingen: Excel-overzicht en dagelijkse deadline-herinneringen. */

namespace KHZS;

defined('ABSPATH') || exit;

function excel_overzicht(object $w, array $inschrijvingen): string
{
    usort($inschrijvingen, fn($a, $b) => [(int) $a->nummer->sessie->nummer, (int) $a->nummer->volgorde, $a->zwemmer->naam]
        <=> [(int) $b->nummer->sessie->nummer, (int) $b->nummer->volgorde, $b->zwemmer->naam]);
    $rijen = [['Sessie', 'Nr', 'Nummer', 'Naam', 'Voornaam', 'Geboortedatum', 'Licentie', 'Inschrijftijd', 'Baan', 'Kost (€)']];
    $per_zwemmer = [];
    foreach ($inschrijvingen as $i) {
        $z = $i->zwemmer;
        $n = $i->nummer;
        $kost = ((int) $n->kost_cent) / 100;
        $rijen[] = [(int) $n->sessie->nummer, (int) $n->nummer, omschrijving($n), $z->naam, $z->voornaam,
            new \DateTimeImmutable($z->geboortedatum, new \DateTimeZone('UTC')), $z->licentie,
            toon_tijd($i->inschrijftijd), $i->inschrijfbaan, $kost];
        $per_zwemmer[$z->id] ??= [$z, 0, 0.0];
        $per_zwemmer[$z->id][1]++;
        $per_zwemmer[$z->id][2] += $kost;
    }
    uasort($per_zwemmer, fn($a, $b) => [$a[0]->naam, $a[0]->voornaam] <=> [$b[0]->naam, $b[0]->voornaam]);
    $rijen2 = [['Naam', 'Voornaam', 'Aantal nummers', 'Totaal (€)']];
    foreach ($per_zwemmer as [$z, $aantal, $kost]) {
        $rijen2[] = [$z->naam, $z->voornaam, $aantal, $kost];
    }
    $rijen2[] = ['Totaal', '', array_sum(array_column($per_zwemmer, 1)), array_sum(array_column($per_zwemmer, 2))];
    return maak_xlsx([['naam' => 'Inschrijvingen', 'rijen' => $rijen], ['naam' => 'Per zwemmer', 'rijen' => $rijen2]]);
}

/** Dagelijks (WP-Cron): pushherinnering 1 dag voor de deadline van zwemmers en trainers. */
function verstuur_herinneringen(): array
{
    $morgen = vandaag()->modify('+1 day')->format('Y-m-d');
    $log = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('wedstrijden') . " WHERE status=%s", W_OPEN)) as $w) {
        $soorten = [
            ['zwemmer', deadline_zwemmer($w), GEZIN, 'Morgen (%s) is de laatste dag om in te schrijven.', app_url("wedstrijd/{$w->id}/")],
            ['trainer', deadline_trainer($w), STAF, 'Morgen (%s) moeten de inschrijvingen nagekeken zijn.', app_url("wedstrijd/{$w->id}/nakijken/")],
        ];
        foreach ($soorten as [$soort, $datum, $rollen, $tekst, $url]) {
            if ($datum->format('Y-m-d') !== $morgen) {
                continue;
            }
            $ok = db()->query(db()->prepare("INSERT IGNORE INTO " . t('herinneringen') . " (wedstrijd_id, soort, datum) VALUES (%d, %s, %s)",
                $w->id, $soort, $morgen));
            if (!$ok) {
                continue;
            }
            $users = users_met('push_deadline', $rollen, $soort === 'zwemmer' ? (int) $w->id : null);
            $n = stuur($users, "Deadline {$w->naam}", sprintf($tekst, $datum->format('d/m')), $url, false);
            $log[] = "{$w->naam} ($soort): $n toestel(len)";
        }
    }
    return $log;
}
