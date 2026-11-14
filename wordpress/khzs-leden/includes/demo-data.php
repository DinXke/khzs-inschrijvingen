<?php
/**
 * Demodata voor /leden-demo/ (zie includes/demo.php). Alle personen zijn verzonnen.
 *
 * - Ploegen van de competitie met hun trainingsuren: overgenomen van www.khzs.be/trainingsuren-competitie (november 2026),
 *   met verzonnen trainers.
 * - Zwemschool: de lesmomenten van www.khzs.be/inschrijven-zwemtest en de groepen van de zwemschool
 *   (startersgroep, groep 1 grijs t.e.m. 5 groen, recreatie, pre-competitie 1 en 2). Welke groep op welk moment
 *   zwemt, staat niet op de website en is verzonnen.
 * - Datums hangen af van vandaag(), zodat de demo na elke nacht actueel blijft.
 *
 * Uitbreiden (bv. een nieuwe module): haak in op de actie khzs_demo_vullen. Die krijgt een object met
 *   ->users     sleutel => user-id (zie demo_personen(), bv. 'demo', 'lotte')
 *   ->zwemmers  sleutel => zwemmer-id (zie demo_zwemmers())
 *   ->ploegen   demo_ploegen(), met per ploeg ->momenten (id's van de trainingsmomenten)
 *   ->seizoen   eerste dag van het zwemseizoen (Y-m-d)
 */

namespace KHZS;

defined('ABSPATH') || exit;

const DEMO_ZWEMBAD = 'Zwembad Kapermolen, Hasselt';
const DEMO_DROOG = 'Sporthal Kapermolen';

/**
 * Ploegen: [naam, soort (competitie|zwemschool), trainers (sleutels), uren [[weekdag 1-7, start, einde, wat, plaats?]]].
 * De uren van de competitie komen van www.khzs.be.
 */
function demo_ploegen(): array
{
    $z = 'zwemmen';
    $d = 'droogtraining';
    $p = [
        ['Competitie 3', 'competitie', ['lotte', 'demo'],
            [[2, '18:30', '20:00', $z], [3, '16:30', '18:30', $z], [4, '17:30', '18:30', $d], [6, '07:00', '07:30', $d], [6, '07:30', '09:00', $z]]],
        ['Competitie 2', 'competitie', ['bram', 'tom'],
            [[1, '18:00', '20:00', $z], [3, '18:30', '20:30', $z], [4, '17:30', '18:30', $d], [5, '16:30', '18:00', $z], [6, '07:00', '09:00', $z]]],
        ['Beloften', 'competitie', ['bram', 'tom'],
            [[1, '18:00', '20:00', $z], [3, '16:30', '18:30', $z], [4, '17:30', '18:30', $d], [4, '18:30', '20:00', $z], [5, '16:30', '18:00', $z], [6, '07:00', '09:00', $z]]],
        ['Competitie 1 provinciaal', 'competitie', ['tom', 'wout', 'bram'],
            [[3, '16:30', '18:30', $z], [5, '18:30', '20:00', $z], [6, '07:00', '09:00', $z]]],
        ['Competitie 1 nationaal', 'competitie', ['wout', 'annelies'],
            [[1, '18:00', '20:00', $z], [3, '18:30', '20:30', $z], [4, '18:00', '18:30', $d, DEMO_ZWEMBAD], [4, '18:30', '20:00', $z], [5, '18:00', '20:00', $z], [6, '07:00', '09:00', $z]]],
        ['Kern', 'competitie', ['wout', 'annelies'],
            [[1, '18:00', '20:00', $z], [2, '17:30', '18:30', $d], [2, '18:30', '20:00', $z], [3, '18:30', '20:30', $z], [4, '18:00', '18:30', $d, DEMO_ZWEMBAD], [4, '18:30', '20:00', $z], [5, '18:00', '20:00', $z], [6, '07:00', '09:00', $z]]],
    ];
    // Zwemschool: lesmomenten van de website, groepen verdeeld over die momenten (verzonnen).
    $momenten = ['di' => [2, '17:30', '18:30'], 'do' => [4, '16:30', '17:30'], 'vr1' => [5, '16:30', '17:30'],
        'vr2' => [5, '17:30', '18:30'], 'za1' => [6, '08:00', '09:00'], 'za2' => [6, '09:00', '10:00']];
    $groepen = [
        ['Startersgroep', ['nina'], ['do', 'za2']],
        ['Groep 1 (grijs)', ['ruben'], ['do', 'vr1']],
        ['Groep 2 (geel)', ['ruben'], ['di', 'za1']],
        ['Groep 3 (oranje)', ['elise'], ['do', 'za1']],
        ['Groep 4 (rood)', ['elise', 'demo'], ['di', 'za2']],
        ['Groep 5 (groen)', ['jasper'], ['vr1', 'za2']],
        ['Recreatie (12+)', ['jasper'], ['vr2', 'za1']],
        ['Pre-competitie 1', ['nina'], ['vr2', 'za1']],
        ['Pre-competitie 2', ['lotte'], ['di', 'za2']],
    ];
    foreach ($groepen as [$naam, $trainers, $wanneer]) {
        $p[] = ["Zwemschool $naam", 'zwemschool', $trainers, array_map(fn($k) => [...$momenten[$k], 'zwemles'], $wanneer)];
    }
    return array_map(fn($x) => (object) ['naam' => $x[0], 'soort' => $x[1], 'trainers' => $x[2], 'uren' => $x[3], 'momenten' => []], $p);
}

/**
 * Accounts: sleutel => [voornaam, naam, app-rol, extra]. Gebruikersnaam = sleutel, wachtwoord 'demo'.
 * Extra: 'penningmeester', 'bestuurder' (voor de rollenmodule zodra die er is).
 */
function demo_personen(): array
{
    return [
        'demo' => ['Demo', 'Gebruiker', BEHEERDER, ['penningmeester', 'bestuurder']],
        'katrien' => ['Katrien', 'Vos', SECRETARIS, []],
        'marc' => ['Marc', 'Lenaerts', ZWEMMER, ['bestuurder']],
        'lotte' => ['Lotte', 'Janssens', TRAINER, []],
        'bram' => ['Bram', 'Willems', TRAINER, []],
        'tom' => ['Tom', 'Peeters', TRAINER, []],
        'wout' => ['Wout', 'Claes', TRAINER, []],
        'annelies' => ['Annelies', 'Hendrickx', TRAINER, []],
        'nina' => ['Nina', 'Vandenberghe', TRAINER, []],
        'ruben' => ['Ruben', 'Goossens', TRAINER, []],
        'elise' => ['Elise', 'Wouters', TRAINER, []],
        'jasper' => ['Jasper', 'Mertens', TRAINER, []],
        'koen' => ['Koen', 'Vermeulen', ZWEMMER, []],
        'an' => ['An', 'Smets', ZWEMMER, []],
        'pieter' => ['Pieter', 'Maes', ZWEMMER, []],
        'hilde' => ['Hilde', 'Jacobs', ZWEMMER, []],
        'sofie' => ['Sofie', 'Martens', ZWEMMER, []],
    ];
}

/** Zwemmers: sleutel => [voornaam, naam, leeftijd, geslacht, ouders (eerste = eerste account), ploeg]. */
function demo_zwemmers(): array
{
    return [
        'demo' => ['Demo', 'Gebruiker', 42, 'M', ['demo'], ''],                  // de demogebruiker zwemt zelf (masters)
        'lina' => ['Lina', 'Gebruiker', 13, 'F', ['demo'], 'Competitie 2'],
        'mats' => ['Mats', 'Gebruiker', 10, 'M', ['demo', 'sofie'], 'Competitie 3'], // gedeeld met de andere ouder
        'noor' => ['Noor', 'Gebruiker', 7, 'F', ['demo'], 'Zwemschool Groep 3 (oranje)'],
        'mila' => ['Mila', 'Vermeulen', 14, 'F', ['koen'], 'Competitie 1 provinciaal'],
        'lars' => ['Lars', 'Vermeulen', 12, 'M', ['koen'], 'Beloften'],
        'jules' => ['Jules', 'Smets', 16, 'M', ['an'], 'Competitie 1 nationaal'],
        'fien' => ['Fien', 'Maes', 9, 'F', ['pieter'], 'Competitie 3'],
        'ella' => ['Ella', 'Maes', 13, 'F', ['pieter'], 'Competitie 2'],
        'arne' => ['Arne', 'Jacobs', 17, 'M', ['hilde'], 'Kern'],
        'kobe' => ['Kobe', 'Jacobs', 15, 'M', ['hilde'], 'Competitie 1 nationaal'],
        'emma' => ['Emma', 'Vos', 11, 'F', ['katrien'], 'Beloften'],
        'febe' => ['Febe', 'Lenaerts', 15, 'F', ['marc'], 'Kern'],
        'robbe' => ['Robbe', 'Lenaerts', 8, 'M', ['marc'], 'Zwemschool Pre-competitie 1'],
    ];
}

/** Vast pseudo-toeval (altijd dezelfde demo), los van mt_rand van de rest van WordPress. */
function demo_rnd(float $van = 0, float $tot = 1): float
{
    static $s = 2026;
    $s = ($s * 1103515245 + 12345) % 2147483648;
    return $van + ($tot - $van) * $s / 2147483648;
}

function demo_kies(array $l)
{
    return $l[(int) floor(demo_rnd(0, count($l) - 0.0001))];
}

/** Paaszondag (zonder de extensie calendar). */
function demo_pasen(int $j): \DateTimeImmutable
{
    $a = $j % 19;
    $b = intdiv($j, 100);
    $c = $j % 100;
    $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
    $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $maand = intdiv($h + $l - 7 * $m + 114, 31);
    $dag = ($h + $l - 7 * $m + 114) % 31 + 1;
    return new \DateTimeImmutable(sprintf('%d-%02d-%02d', $j, $maand, $dag));
}

function demo_vul(): void
{
    $c = (object) ['users' => [], 'zwemmers' => [], 'ploegen' => demo_ploegen()];
    $v = vandaag();
    $c->seizoen = ((int) $v->format('n') >= 9 ? $v->format('Y') : ((int) $v->format('Y') - 1)) . '-09-01';
    demo_vul_accounts($c);
    demo_vul_zwemmers($c);
    demo_vul_wedstrijden($c);
    demo_vul_trainers($c);
    do_action('khzs_demo_vullen', $c);
}

// ---------- accounts ----------

function demo_vul_accounts(object $c): void
{
    $hash = wp_hash_password('demo');
    $caps = db()->get_blog_prefix() . 'capabilities';
    $nu = gmdate('Y-m-d H:i:s');
    foreach (demo_personen() as $login => [$vn, $nm, $rol, $extra]) {
        db()->insert(db()->users, ['user_login' => $login, 'user_pass' => $hash, 'user_nicename' => $login,
            'user_email' => "$login@demo.invalid", 'user_registered' => $nu, 'display_name' => "$vn $nm"]);
        $uid = (int) db()->insert_id;
        $c->users[$login] = $uid;
        $meta = ['first_name' => $vn, 'last_name' => $nm, 'nickname' => $login, $caps => serialize(['subscriber' => true]),
            db()->get_blog_prefix() . 'user_level' => '0', 'khzs_rol' => $rol];
        if (in_array('penningmeester', $extra, true)) {
            $meta['khzs_penningmeester'] = '1';
        }
        foreach ($meta as $k => $w) {
            db()->insert(db()->usermeta, ['user_id' => $uid, 'meta_key' => $k, 'meta_value' => $w]);
        }
    }
}

// ---------- zwemmers en beste tijden ----------

/** Plausibele tijd in honderdsten: jonger = trager, 50 m-bad iets trager. */
function demo_richttijd(int $leeftijd, string $geslacht, int $afstand, string $slag, string $baan): int
{
    $basis = [50 => ['FREE' => 30, 'BACK' => 36, 'BREAST' => 40, 'FLY' => 34], 100 => ['FREE' => 66, 'BACK' => 76, 'BREAST' => 86, 'FLY' => 75, 'MEDLEY' => 77],
        200 => ['FREE' => 145, 'BACK' => 165, 'BREAST' => 186, 'FLY' => 170, 'MEDLEY' => 165], 400 => ['FREE' => 310, 'MEDLEY' => 350]];
    $sec = ($basis[$afstand][$slag] ?? $afstand * .75) * (1 + max(0, 16 - $leeftijd) * .06) * ($geslacht === 'F' ? 1.07 : 1)
        * ($leeftijd > 30 ? 1.15 : 1) * ($baan === 'LCM' ? 1.02 : 1) * demo_rnd(.96, 1.06);
    return (int) round($sec * 100);
}

function demo_vul_zwemmers(object $c): void
{
    $v = vandaag();
    $jaar = (int) $v->format('Y');
    $nummers = [[50, 'FREE'], [100, 'FREE'], [200, 'FREE'], [50, 'BACK'], [100, 'BACK'], [50, 'BREAST'], [100, 'BREAST'], [50, 'FLY'], [100, 'MEDLEY'], [200, 'MEDLEY']];
    $i = 0;
    foreach (demo_zwemmers() as $sleutel => [$vn, $nm, $leeftijd, $gs, $ouders, $ploeg]) {
        $gd = sprintf('%d-%02d-%02d', $jaar - $leeftijd, 1 + ($i * 5) % 12, 1 + ($i * 7) % 28);
        $comp = !str_starts_with($ploeg, 'Zwemschool') && $sleutel !== 'noor';
        db()->insert(t('zwemmers'), ['account_id' => $c->users[$ouders[0]], 'voornaam' => $vn, 'naam' => $nm, 'geboortedatum' => $gd,
            'geslacht' => $gs, 'licentie' => $comp ? sprintf('%08d', 61200300 + $i * 37) : '', 'swimrankings_id' => '']);
        $zid = (int) db()->insert_id;
        $c->zwemmers[$sleutel] = $zid;
        foreach (array_slice($ouders, 1) as $o) {
            db()->insert(t('zwemmer_accounts'), ['zwemmer_id' => $zid, 'user_id' => $c->users[$o], 'aangemaakt' => nu_sql()]);
        }
        $i++;
        if (!$comp) {
            continue;
        }
        // Beste tijden van het voorbije jaar (enkele in het 50 m-bad).
        foreach ($nummers as [$afstand, $slag]) {
            if (($leeftijd < 10 && $afstand > 100) || demo_rnd() < .25) {
                continue;
            }
            foreach (['SCM', 'LCM'] as $baan) {
                if ($baan === 'LCM' && demo_rnd() < .6) {
                    continue;
                }
                $datum = $v->modify('-' . (int) demo_rnd(60, 330) . ' days')->format('Y-m-d');
                db()->insert(t('tijden'), ['zwemmer_id' => $zid, 'afstand' => $afstand, 'slag' => $slag, 'baan' => $baan,
                    'tijd' => demo_richttijd($leeftijd, $gs, $afstand, $slag, $baan), 'datum' => $datum,
                    'wedstrijd' => $baan === 'LCM' ? 'Zomermeeting Antwerpen' : demo_kies(['Clubkampioenschap HZS', 'Regiocup Limburg', 'Kerstcriterium Genk', 'Lentemeeting Tongeren']),
                    'bron' => demo_rnd() < .5 ? 'uitslagen' : 'manueel']);
            }
        }
    }
}

// ---------- wedstrijden ----------

/** Leeftijdsgroepen van de nummers. */
const DEMO_JEUGD = [[11, 11], [12, 12], [13, 13], [14, 14], [15, 16], [17, -1]];
const DEMO_MINIEM = [[9, 9], [10, 10]];

/** [naam, stad, zwembad, baan, dag (t.o.v. vandaag), start, deadline (t.o.v. vandaag), status, kost, info, nummers]. */
function demo_wedstrijden(): array
{
    $j = DEMO_JEUGD;
    $m = DEMO_MINIEM;
    return [
        ['Herfstmeeting HZS', 'Hasselt', 'Kapermolen', 'LCM', -35, '09:00', -49, W_DOORGESTUURD, '5.00', 'Thuiswedstrijd: elke zwemmer helpt mee aan de cafetaria.',
            [[50, 'FREE', 'F', $j], [50, 'FREE', 'M', $j], [200, 'FREE', 'F', $j], [200, 'FREE', 'M', $j], [100, 'BACK', 'F', $j], [100, 'BACK', 'M', $j],
                [50, 'BREAST', 'X', $m], [100, 'FLY', 'F', $j], [100, 'FLY', 'M', $j]]],
        ['Regiocup Limburg', 'Genk', 'Zwembad Sportinn', 'SCM', -14, '14:00', -28, W_DOORGESTUURD, '4.00', '',
            [[100, 'FREE', 'F', $j], [100, 'FREE', 'M', $j], [50, 'BACK', 'X', $m], [100, 'BREAST', 'F', $j], [100, 'BREAST', 'M', $j],
                [50, 'FLY', 'F', $j], [50, 'FLY', 'M', $j], [200, 'MEDLEY', 'F', $j], [200, 'MEDLEY', 'M', $j]]],
        ['Provinciaal kampioenschap Limburg', 'Lommel', 'Zwembad De Soeverein', 'SCM', 6, '13:30', -2, W_GESLOTEN, '4.50', 'Inschrijvingen zijn afgesloten en worden nagekeken.',
            [[100, 'MEDLEY', 'F', $j], [100, 'MEDLEY', 'M', $j], [50, 'FREE', 'F', $j], [50, 'FREE', 'M', $j], [100, 'BACK', 'F', $j], [100, 'BACK', 'M', $j],
                [50, 'BREAST', 'X', $m], [200, 'FREE', 'F', $j], [200, 'FREE', 'M', $j]]],
        ['Clubmeeting ZCT', 'Tremelo', 'Zwembad Tremelo', 'SCM', 25, '09:30', 11, W_OPEN, '4.50', 'Enkel voor zwemmers van 11 jaar en ouder. Busvervoer voorzien.',
            [[100, 'MEDLEY', 'F', $j], [100, 'MEDLEY', 'M', $j], [50, 'FREE', 'F', $j], [50, 'FREE', 'M', $j], [100, 'BACK', 'F', $j], [100, 'BACK', 'M', $j],
                [200, 'FREE', 'F', $j], [200, 'FREE', 'M', $j], [50, 'BREAST', 'F', $j], [50, 'BREAST', 'M', $j]]],
        ['Regiocup 1 Bree', 'Bree', 'De Sprink', 'SCM', 40, '14:00', 26, W_OPEN, '4.00', 'Regionale wedstrijd op uitnodiging. Inzwemmen vanaf 13:00.',
            [[200, 'BREAST', 'F', $j], [200, 'BREAST', 'M', $j], [50, 'BREAST', 'X', $m], [100, 'FREE', 'F', $j], [100, 'FREE', 'M', $j],
                [50, 'FLY', 'F', $j], [50, 'FLY', 'M', $j], [100, 'FREE', 'X', $m], [400, 'MEDLEY', 'F', $j], [400, 'MEDLEY', 'M', $j]]],
        ['Sprintcup KLSVZ', 'Hannuit', 'Plopsaqua Landen-Hannuit', 'SCM', 60, '18:00', 46, W_CONCEPT, '5.00', '9-10-jarigen: max. 2 individuele nummers. 11+: max. 4.',
            [[100, 'BREAST', 'X', $m], [50, 'FLY', 'F', [[11, 12], [13, 14], [15, 16], [17, -1]]], [50, 'FLY', 'M', [[11, 12], [13, 14], [15, 16], [17, -1]]],
                [100, 'BACK', 'X', $m], [50, 'FREE', 'F', [[11, 12], [13, 14], [15, 16], [17, -1]]], [50, 'FREE', 'M', [[11, 12], [13, 14], [15, 16], [17, -1]]]]],
    ];
}

/** Lenex 3.0-uitnodiging, zoals een organisator die zou sturen. */
function demo_lenex(array $w, string $dag, string $deadline): string
{
    [$naam, $stad, $zwembad, $baan, , $start, , , $kost, , $nummers] = $w;
    $x = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"/>');
    $meet = $x->addChild('MEETS')->addChild('MEET');
    foreach (['name' => $naam, 'city' => $stad, 'course' => $baan, 'deadline' => $deadline, 'nation' => 'BEL'] as $k => $v) {
        $meet->addAttribute($k, $v);
    }
    $a = $meet->addChild('AGEDATE');
    $a->addAttribute('value', substr($dag, 0, 4) . '-12-31');
    $a->addAttribute('type', 'YEAR');
    $meet->addChild('POOL')->addAttribute('name', $zwembad);
    $q = $meet->addChild('QUALIFY');
    $q->addAttribute('from', ((int) substr($dag, 0, 4) - 1) . '-01-01');
    $q->addAttribute('until', $deadline);
    $s = $meet->addChild('SESSIONS')->addChild('SESSION');
    $s->addAttribute('number', '1');
    $s->addAttribute('date', $dag);
    $s->addAttribute('daytime', $start);
    $events = $s->addChild('EVENTS');
    foreach ($nummers as $i => [$afstand, $slag, $geslacht, $groepen]) {
        $nr = $i + 1;
        $e = $events->addChild('EVENT');
        foreach (['eventid' => 9000 + $nr, 'number' => $nr, 'order' => $nr, 'gender' => $geslacht, 'round' => 'TIM'] as $k => $v) {
            $e->addAttribute($k, (string) $v);
        }
        $st = $e->addChild('SWIMSTYLE');
        $st->addAttribute('distance', (string) $afstand);
        $st->addAttribute('stroke', $slag);
        $st->addAttribute('relaycount', '1');
        $f = $e->addChild('FEE');
        $f->addAttribute('currency', 'EUR');
        $f->addAttribute('value', $kost);
        $ag = $e->addChild('AGEGROUPS');
        foreach ($groepen as $g => [$min, $max]) {
            $el = $ag->addChild('AGEGROUP');
            $el->addAttribute('agegroupid', (string) (9000 + $nr * 10 + $g));
            $el->addAttribute('agemin', (string) $min);
            $el->addAttribute('agemax', (string) $max);
        }
    }
    return $x->asXML();
}

/** Kleine geldige PDF met een paar regels tekst (voor een bijlage). */
function demo_pdf(array $regels): string
{
    $tekst = 'BT /F1 16 Tf 60 780 Td 22 TL ' . implode(' ', array_map(fn($r) => '(' . addcslashes($r, '()\\') . ") '", $regels)) . ' ET';
    $objecten = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($tekst) . " >>\nstream\n$tekst\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $uit = "%PDF-1.4\n";
    $pos = [];
    foreach ($objecten as $i => $o) {
        $pos[] = strlen($uit);
        $uit .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($uit);
    $uit .= "xref\n0 " . (count($objecten) + 1) . "\n0000000000 65535 f \n" . implode('', array_map(fn($p) => sprintf("%010d 00000 n \n", $p), $pos));
    return $uit . "trailer\n<< /Size " . (count($objecten) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

function demo_vul_wedstrijden(object $c): void
{
    $v = vandaag();
    $trainer = $c->users['lotte'];
    // Jeugdwedstrijden: de demogebruiker zelf (masters) doet niet mee.
    $zwemmers = db()->get_results(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE licentie<>'' AND geboortedatum>%s", $v->modify('-25 years')->format('Y-m-d')));
    prive_map('lenex');
    foreach (demo_wedstrijden() as $w) {
        [$naam, , , $baan, $dag_r, , $deadline_r, $status, , $info] = $w;
        $dag = $v->modify(sprintf('%+d days', $dag_r))->format('Y-m-d');
        $deadline = $v->modify(sprintf('%+d days', $deadline_r))->format('Y-m-d');
        $xml = demo_lenex($w, $dag, $deadline);
        $rel = 'lenex/demo-' . str_replace('-', '', $dag) . '.lef';
        file_put_contents(prive_map() . "/$rel", $xml);
        $wid = importeer(parse_meet(lees_xml($xml)), $rel);
        db()->update(t('wedstrijden'), ['status' => $status, 'info' => $info, 'dagen_secretaris' => 3, 'dagen_trainer' => 2], ['id' => $wid]);
        $wed = wedstrijd($wid);
        $verleden = $dag < $v->format('Y-m-d');

        foreach ($zwemmers as $z) {
            $jaren = leeftijd($z, $wed);
            $mogelijk = [];
            foreach (sessies_met_nummers($wid) as $s) {
                foreach ($s->nummers as $n) {
                    if ($n->inschrijfbaar && past_in_nummer($z, $n, $jaren)) {
                        $mogelijk[] = $n;
                    }
                }
            }
            if (!$mogelijk) {
                continue;
            }
            // De kinderen van de demogebruiker doen altijd mee, zodat er bij elke wedstrijd iets te zien is.
            $eigen = (int) $z->account_id === $c->users['demo'];
            $r = demo_rnd();
            $keuze = $eigen ? DOET_MEE : ($status === W_CONCEPT
                ? ($r < .2 ? DOET_MEE : ($r < .6 ? INTERESSE : ($r < .7 ? DOET_NIET_MEE : null)))
                : ($r < .55 ? DOET_MEE : ($r < .75 ? INTERESSE : ($r < .85 ? DOET_NIET_MEE : null))));
            if ($keuze === null) {
                continue;
            }
            db()->insert(t('deelnames'), ['zwemmer_id' => $z->id, 'wedstrijd_id' => $wid, 'keuze' => $keuze, 'gewijzigd' => nu_sql()]);
            if ($keuze !== DOET_MEE || $status === W_CONCEPT) {
                continue;
            }
            $tijden = tijden_van((int) $z->id);
            usort($mogelijk, fn() => demo_rnd() <=> .5);
            foreach (array_slice($mogelijk, 0, min(count($mogelijk), 2 + (int) demo_rnd(0, 2.99))) as $n) {
                $beste = null;
                foreach ($tijden as $t) {
                    if ((int) $t->afstand === (int) $n->afstand && $t->slag === $n->slag && (!$beste || ($t->baan === $baan && $beste->baan !== $baan)
                            || ($t->baan === $beste->baan && (int) $t->tijd < (int) $beste->tijd))) {
                        $beste = $t;
                    }
                }
                $st = $verleden ? GOEDGEKEURD : demo_kies([INGEDIEND, INGEDIEND, GOEDGEKEURD, $status === W_GESLOTEN ? INGEDIEND : AFGEKEURD]);
                db()->insert(t('inschrijvingen'), ['zwemmer_id' => $z->id, 'nummer_id' => $n->id, 'inschrijftijd' => $beste ? (int) $beste->tijd : null,
                    'inschrijfbaan' => $beste ? $beste->baan : '', 'status' => $st, 'opmerking' => $st === AFGEKEURD ? 'eerst 50 m proberen' : '',
                    'aangemaakt' => nu_sql(), 'door_id' => $z->account_id]);
                if ($st === AFGEKEURD) {
                    db()->insert(t('meldingen'), ['zwemmer_id' => $z->id, 'wedstrijd_id' => $wid, 'door_id' => $trainer, 'gelezen' => 0, 'aangemaakt' => nu_sql(),
                        'tekst' => 'afgekeurd: #' . $n->nummer . ' ' . omschrijving($n) . ' (eerst 50 m proberen)']);
                }
            }
        }

        if ($verleden) {
            // Alles gefinaliseerd en ingegeven bij de organisator.
            db()->query(db()->prepare("UPDATE " . t('deelnames') . " SET gefinaliseerd=1, gefinaliseerd_door=%d, gefinaliseerd_op=%s, ingegeven=1 WHERE wedstrijd_id=%d AND keuze=%s",
                $trainer, nu_sql(), $wid, DOET_MEE));
        } elseif (in_array($status, [W_OPEN, W_GESLOTEN], true)) {
            // Een paar zwemmers al gefinaliseerd (de eerste ook al ingegeven), één deelname geweigerd.
            $ds = db()->get_results(db()->prepare("SELECT * FROM " . t('deelnames') . " WHERE wedstrijd_id=%d AND keuze=%s ORDER BY id", $wid, DOET_MEE));
            foreach ($ds as $i => $d) {
                if ((int) $d->zwemmer_id === $c->zwemmers['lina']) {
                    continue; // de demogebruiker kan dit zelf uitproberen
                }
                if ($i < 3) {
                    db()->update(t('deelnames'), ['gefinaliseerd' => 1, 'gefinaliseerd_door' => $trainer, 'gefinaliseerd_op' => nu_sql(), 'ingegeven' => $i === 0 ? 1 : 0], ['id' => $d->id]);
                    db()->query(db()->prepare("UPDATE " . t('inschrijvingen') . " i JOIN " . t('nummers') . " n ON n.id=i.nummer_id JOIN " . t('sessies')
                        . " s ON s.id=n.sessie_id SET i.status=%s WHERE s.wedstrijd_id=%d AND i.zwemmer_id=%d AND i.status=%s", GOEDGEKEURD, $wid, $d->zwemmer_id, INGEDIEND));
                } elseif ($i === count($ds) - 1) {
                    db()->update(t('deelnames'), ['geweigerd' => 1, 'weigerreden' => 'Nog niet in de wedstrijdgroep'], ['id' => $d->id]);
                    db()->query(db()->prepare("UPDATE " . t('inschrijvingen') . " i JOIN " . t('nummers') . " n ON n.id=i.nummer_id JOIN " . t('sessies')
                        . " s ON s.id=n.sessie_id SET i.status=%s, i.opmerking='deelname geweigerd' WHERE s.wedstrijd_id=%d AND i.zwemmer_id=%d", AFGEKEURD, $wid, $d->zwemmer_id));
                    db()->insert(t('meldingen'), ['zwemmer_id' => $d->zwemmer_id, 'wedstrijd_id' => $wid, 'door_id' => $trainer, 'gelezen' => 0,
                        'aangemaakt' => nu_sql(), 'tekst' => 'deelname geweigerd: Nog niet in de wedstrijdgroep']);
                }
            }
        }

        if ($naam === 'Clubmeeting ZCT') {
            prive_map('bijlagen/demo');
            $rel = 'bijlagen/demo/busregeling.pdf';
            file_put_contents(prive_map() . "/$rel", demo_pdf(["Busregeling $naam - " . datum($dag, 'l j F'), '', 'Vertrek: 7u15 aan zwembad Kapermolen',
                'Terug: rond 13u00', 'Meebrengen: clubbadmuts, T-shirt, lunchpakket']));
            db()->insert(t('bijlagen'), ['wedstrijd_id' => $wid, 'bestand' => $rel, 'naam' => 'Busregeling', 'door_id' => $trainer, 'toegevoegd' => nu_sql()]);
        }

        if ($verleden) {
            // Uitslagen verwerken → beste tijden.
            $res = [];
            foreach (db()->get_results(db()->prepare("SELECT i.*, n.afstand, n.slag FROM " . t('inschrijvingen') . " i JOIN " . t('nummers') . " n ON n.id=i.nummer_id JOIN "
                . t('sessies') . " s ON s.id=n.sessie_id WHERE s.wedstrijd_id=%d", $wid)) as $i) {
                $z = zwemmer((int) $i->zwemmer_id);
                $basis = $i->inschrijftijd ? (int) $i->inschrijftijd : demo_richttijd(leeftijd($z, $wed), $z->geslacht, (int) $i->afstand, $i->slag, $baan);
                $res[] = ['voornaam' => $z->voornaam, 'naam' => $z->naam, 'geboortedatum' => $z->geboortedatum, 'licentie' => $z->licentie, 'geslacht' => $z->geslacht,
                    'afstand' => (int) $i->afstand, 'slag' => $i->slag, 'baan' => $baan, 'datum' => $dag, 'wedstrijd' => $naam, 'tijd' => (int) round($basis * demo_rnd(.96, 1.02))];
            }
            importeer_uitslagen($res);
        }
    }
}

// ---------- trainers ----------

function demo_vul_trainers(object $c): void
{
    $v = vandaag();
    $vandaag = $v->format('Y-m-d');
    $seizoen = $c->seizoen;
    $u = $c->users;
    $nu = nu_sql();

    // Trainingsmomenten met de vaste trainers. Ploegen van de competitie die op hetzelfde uur hetzelfde doen, trainen
    // samen: dat is één training (met de trainers van alle ploegen). Elke zwemschoolgroep heeft eigen lessen.
    $momenten = [];
    foreach ($c->ploegen as $p) {
        foreach ($p->uren as $uur) {
            [$dag, $start, $einde, $wat] = $uur;
            $plaats = $uur[4] ?? ($wat === 'droogtraining' ? DEMO_DROOG : DEMO_ZWEMBAD);
            $sleutel = $p->soort === 'zwemschool' ? "{$p->naam}|$dag|$start" : "$dag|$start|$einde|$wat|$plaats";
            $momenten[$sleutel] ??= ['dag' => $dag, 'start' => $start, 'einde' => $einde, 'wat' => $wat, 'plaats' => $plaats, 'ploegen' => [], 'trainers' => []];
            $momenten[$sleutel]['ploegen'][] = $p;
            $momenten[$sleutel]['trainers'] = array_unique(array_merge($momenten[$sleutel]['trainers'], $p->trainers));
        }
    }
    foreach ($momenten as $m) {
        $namen = implode(' + ', array_map(fn($p) => $p->naam, $m['ploegen']));
        db()->insert(t('tr_momenten'), ['naam' => $m['wat'] === 'zwemles' ? $namen : "$namen · {$m['wat']}", 'weekdag' => $m['dag'],
            'start' => $m['start'], 'einde' => $m['einde'], 'locatie' => $m['plaats'], 'van' => $seizoen, 'tot' => null, 'aangemaakt' => $nu]);
        $mid = (int) db()->insert_id;
        foreach ($m['ploegen'] as $p) {
            $p->momenten[] = $mid;
        }
        foreach ($m['trainers'] as $k) {
            db()->insert(t('tr_vast'), ['moment_id' => $mid, 'user_id' => $u[$k], 'van' => $seizoen, 'tot' => null]);
        }
    }
    $ploeg = fn(string $naam) => current(array_filter($c->ploegen, fn($p) => $p->naam === $naam));

    // Afgelast: zwemschool in de kerst- en paasvakantie; alle trainingen op feestdagen (zwembad dicht).
    $j = (int) substr($seizoen, 0, 4);
    $pasen = demo_pasen($j + 1);
    foreach ($c->ploegen as $p) {
        if ($p->soort !== 'zwemschool') {
            continue;
        }
        foreach ($p->momenten as $mid) {
            db()->insert(t('tr_afgelast'), ['moment_id' => $mid, 'van' => "$j-12-22", 'tot' => ($j + 1) . '-01-04', 'reden' => 'Kerstvakantie: geen zwemlessen']);
            db()->insert(t('tr_afgelast'), ['moment_id' => $mid, 'van' => $pasen->modify('-6 days')->format('Y-m-d'), 'tot' => $pasen->modify('+8 days')->format('Y-m-d'), 'reden' => 'Paasvakantie: geen zwemlessen']);
        }
    }
    foreach (["$j-11-01" => 'Allerheiligen: zwembad dicht', "$j-11-11" => 'Wapenstilstand: zwembad dicht', ($j + 1) . '-05-01' => 'Dag van de Arbeid: zwembad dicht'] as $d => $reden) {
        db()->insert(t('tr_afgelast'), ['moment_id' => 0, 'van' => $d, 'tot' => $d, 'reden' => $reden]);
    }

    // Afmeldingen, vervangingen en extra hulp, rond vandaag.
    $volgende = function (int $mid, int $na) use ($v): string {
        $m = tr_moment($mid);
        $d = $v->modify(sprintf('%+d days', $na));
        while ((int) $d->format('N') !== (int) $m->weekdag) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    };
    $wijz = function (int $mid, string $datum, string $wie, string $soort, ?string $vervangt = null, string $reden = '') use ($u, $nu, $seizoen) {
        if ($datum < $seizoen) {
            return;
        }
        db()->insert(t('tr_wijzigingen'), ['moment_id' => $mid, 'datum' => $datum, 'user_id' => $u[$wie], 'soort' => $soort,
            'vervangt_id' => $vervangt ? $u[$vervangt] : null, 'reden' => $reden, 'door_id' => $u[$wie], 'aangemaakt' => $nu]);
    };
    $beloften = $ploeg('Beloften')->momenten;
    $comp3 = $ploeg('Competitie 3')->momenten;
    $kern = $ploeg('Kern')->momenten;
    $groep4 = $ploeg('Zwemschool Groep 4 (rood)')->momenten;
    // Voorbij: Bram was ziek, Tom nam over; de demogebruiker hielp mee bij de Kern.
    $d = $volgende($beloften[0], -12);
    $wijz($beloften[0], $d, 'bram', 'afwezig', null, 'Ziek');
    $wijz($beloften[0], $d, 'tom', 'vervanger', 'bram');
    $wijz($kern[2], $volgende($kern[2], -9), 'demo', 'extra');
    // Voorbij: de demogebruiker kon niet, Lotte nam over.
    $d = $volgende($comp3[0], -6);
    $wijz($comp3[0], $d, 'demo', 'afwezig', null, 'Werk');
    $wijz($comp3[0], $d, 'lotte', 'vervanger', 'demo');
    // Binnenkort: Lotte kan niet en zoekt een vervanger (Vervanger gezocht).
    $wijz($comp3[1], $volgende($comp3[1], 2), 'lotte', 'afwezig', null, 'Examen');
    // Binnenkort: Elise kan niet, Jasper neemt over; de demogebruiker helpt extra bij de Kern.
    $d = $volgende($groep4[1], 3);
    $wijz($groep4[1], $d, 'elise', 'afwezig', null, 'Familiefeest');
    $wijz($groep4[1], $d, 'jasper', 'vervanger', 'elise');
    $wijz($kern[0], $volgende($kern[0], 5), 'demo', 'extra');

    // Aanwezigheid bevestigd voor alle voorbije trainingen, behalve de laatste twee dagen: die staan nog open
    // ('Was je aanwezig?'). Enkel bevestigde trainingen tellen mee voor de uren.
    $bevestig = function (string $van, string $tot) use ($nu) {
        foreach ($van <= $tot ? tr_trainingen($van, $tot) : [] as $b) {
            if ($b->afgelast) {
                continue;
            }
            foreach (array_keys($b->aanwezig) as $uid) {
                db()->query(db()->prepare("INSERT IGNORE INTO " . t('tr_bevestigingen')
                    . " (moment_id, datum, user_id, aanwezig, door_id, bron, op) VALUES (%d, %s, %d, 1, %d, 'trainer', %s)",
                    $b->moment->id, $b->datum, $uid, $uid, $nu));
            }
        }
    };
    $bevestig($seizoen, $v->modify('-3 days')->format('Y-m-d'));

    // Tarieven: competitietrainers per uur, lesgevers per training. Eén trainer kreeg een nieuw tarief.
    $tarief = fn(string $wie, string $soort, int $cent, string $vanaf, string $opm = '') => db()->insert(t('tr_tarieven'),
        ['user_id' => $u[$wie], 'soort' => $soort, 'bedrag_cent' => $cent, 'geldig_vanaf' => $vanaf, 'opmerking' => $opm, 'aangemaakt' => $nu]);
    foreach (['lotte' => 1250, 'bram' => 1250, 'tom' => 1250, 'wout' => 1500, 'annelies' => 1500, 'demo' => 1250] as $wie => $cent) {
        $tarief($wie, 'uur', $cent, $seizoen, 'Vrijwilligersvergoeding');
    }
    foreach (['nina' => 1000, 'ruben' => 1000, 'elise' => 1000, 'jasper' => 1000] as $wie => $cent) {
        $tarief($wie, 'beurt', $cent, $seizoen, 'Per zwemles');
    }
    $tarief('tom', 'uur', 1400, $v->modify('first day of this month')->format('Y-m-d'), 'Nieuw tarief na diploma Trainer B');

    // Voorbije maanden van dit seizoen uitbetaald (momentopname), behalve één trainer van de vorige maand.
    $maand = substr($seizoen, 0, 7);
    $deze = $v->format('Y-m');
    while ($maand < $deze) {
        $vorige = $v->modify('first day of last month')->format('Y-m');
        foreach (tr_maand($maand) as $uid => $r) {
            if ($maand === $vorige && $uid === $u['jasper']) {
                continue;
            }
            db()->insert(t('tr_uitbetaald'), ['user_id' => $uid, 'maand' => $maand, 'trainingen' => $r['trainingen'], 'minuten' => $r['minuten'],
                'bedrag_cent' => $r['bedrag_cent'], 'door_id' => $u['demo'], 'op' => $nu]);
        }
        $maand = (new \DateTimeImmutable("$maand-01"))->modify('+1 month')->format('Y-m');
    }
    // Na de uitbetaling nog een correctie: de vorige maand toont 'gewijzigd na uitbetaling'.
    $d = $volgende($beloften[1], -40);
    if ($d >= $seizoen && substr($d, 0, 7) < $deze) {
        $wijz($beloften[1], $d, 'wout', 'extra');
        $bevestig($d, $d);
    }
}
