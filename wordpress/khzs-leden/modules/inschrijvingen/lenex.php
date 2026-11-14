<?php
/** Lezen van Lenex-uitnodigingen (.lxf/.lef) en uitslagen, en schrijven van een inschrijvingsbestand. */

namespace KHZS;

defined('ABSPATH') || exit;

class LenexFout extends \RuntimeException
{
}

const SLAGEN = ['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'];
// Rondes waarvoor je niet rechtstreeks inschrijft: finales, halve finales, swim-offs.
const VERVOLGRONDES = ['FIN', 'SEM', 'QUA', 'SOP', 'SOS', 'SOQ', 'SOF'];


/** Geeft de LENEX-root terug; aanvaardt zowel zip (.lxf) als kale XML (.lef). */
function lees_xml(string $data): \SimpleXMLElement
{
    if (str_starts_with($data, 'PK')) {
        $tmp = tijdelijk();
        file_put_contents($tmp, $data);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new LenexFout('Geen geldig .lxf-archief.');
        }
        $data = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $naam = $zip->getNameIndex($i);
            if (preg_match('/\.(lef|xml)$/i', $naam)) {
                $data = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        @unlink($tmp);
        if ($data === null || $data === false) {
            throw new LenexFout('Geen .lef-bestand gevonden in het .lxf-archief.');
        }
    }
    // Geen DTD's of entiteiten (XXE / billion laughs): Lenex gebruikt ze nooit.
    if (stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) {
        throw new LenexFout('Geen geldige Lenex-XML: DTD/entiteiten zijn niet toegelaten.');
    }
    $vorige = libxml_use_internal_errors(true);
    $root = simplexml_load_string($data, \SimpleXMLElement::class, LIBXML_NONET);
    $fouten = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($vorige);
    if ($root === false) {
        $f = $fouten ? trim($fouten[0]->message) : 'onleesbaar';
        throw new LenexFout("Geen geldige Lenex-XML: $f");
    }
    if ($root->getName() !== 'LENEX') {
        throw new LenexFout('Dit is geen Lenex-bestand.');
    }
    return $root;
}

/** '00:01:02.86' → honderdsten. 'NT' of leeg → null. */
function parse_tijd(?string $s): ?int
{
    if (!$s || strtoupper($s) === 'NT') {
        return null;
    }
    if (!preg_match('/^(\d+):(\d+):(\d+)\.(\d+)$/', $s, $m)) {
        return null;
    }
    return (int) $m[1] * 360000 + (int) $m[2] * 6000 + (int) $m[3] * 100 + (int) substr(str_pad($m[4], 2, '0'), 0, 2);
}

/** honderdsten → Lenex-swimtime 'HH:MM:SS.hh'. */
function format_tijd($hon): string
{
    if ($hon === null) {
        return 'NT';
    }
    $hon = (int) $hon;
    return sprintf('%02d:%02d:%02d.%02d', intdiv($hon, 360000), intdiv($hon % 360000, 6000), intdiv($hon % 6000, 100), $hon % 100);
}

function _a(?\SimpleXMLElement $el, string $naam): ?string
{
    return $el !== null && isset($el[$naam]) ? (string) $el[$naam] : null;
}

function _datum(?string $s): ?string
{
    if (!$s) {
        return null;
    }
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
    return $d && $d->format('Y-m-d') === substr($s, 0, 10) ? $d->format('Y-m-d') : null;
}

function _tijd(?string $s): ?string
{
    if (!$s || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?/', $s, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
        return null;
    }
    return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
}

function _int(?string $s, ?int $standaard = 0): ?int
{
    return $s !== null && preg_match('/^\s*-?\d+\s*$/', $s) ? (int) $s : $standaard;
}

/** Lenex kent SCM, LCM, SCY, SCM16, SCM20, SCM33, OPEN. Wij rekenen met 25 m / 50 m. */
function _baan(?string $course): string
{
    return strtoupper((string) $course) === 'LCM' ? 'LCM' : 'SCM';
}

function _geslacht(?string $g): string
{
    $g = strtoupper((string) $g);
    return in_array($g, ['M', 'F'], true) ? $g : 'X';
}

function _els(\SimpleXMLElement $el, string $pad): array
{
    return $el->xpath($pad) ?: [];
}

function _el(\SimpleXMLElement $el, string $pad): ?\SimpleXMLElement
{
    return _els($el, $pad)[0] ?? null;
}

/** Kost in cent uit een FEE-element (Lenex 3: value in cent; Lenex 2: soms decimaal). */
function _fee(?\SimpleXMLElement $el): ?int
{
    if ($el === null) {
        return null;
    }
    $v = _a($el, 'value') ?? '';
    if (str_contains($v, '.') || str_contains($v, ',')) {
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (int) round((float) $v * 100) : null;
    }
    return _int($v, null);
}

function _individuele_fee(array $fees): bool|int|null
{
    foreach ($fees as $f) {
        if (in_array(_a($f, 'type'), [null, '', 'ATHLETE.INDIVIDUAL', 'INDIVIDUAL'], true)) {
            return _fee($f);
        }
    }
    return false;
}

function _kies_meet(\SimpleXMLElement $root): \SimpleXMLElement
{
    $meets = _els($root, 'MEETS/MEET');
    if (!$meets) {
        throw new LenexFout('Geen wedstrijd (MEET) gevonden in het bestand.');
    }
    // Bij meerdere MEETs: de eerste met een programma.
    foreach ($meets as $m) {
        if (_el($m, 'SESSIONS/SESSION/EVENTS/EVENT') !== null) {
            return $m;
        }
    }
    return $meets[0];
}

/** Haalt de wedstrijd op als array-structuur (los van de database). Werkt voor Lenex 2.x en 3.0. */
function parse_meet(\SimpleXMLElement $root): array
{
    $meet = _kies_meet($root);
    $pool = _el($meet, 'POOL');
    $facility = _el($meet, 'FACILITY');
    $agedate = _el($meet, 'AGEDATE');
    $qualify = _el($meet, 'QUALIFY');
    $waarschuwingen = [];

    // Standaardkost op wedstrijdniveau, zonder type of voor individuele zwemmers.
    $standaard_fee = _individuele_fee(array_merge(_els($meet, 'FEES/FEE'), _els($meet, 'FEE')));
    $standaard_fee = $standaard_fee === false ? null : $standaard_fee;

    $sessies = [];
    $gebruikte_refs = [];
    foreach (_els($meet, 'SESSIONS/SESSION') as $s) {
        $sessie_fee = null;
        foreach (array_merge(_els($s, 'FEES/FEE'), _els($s, 'FEE')) as $f) {
            if (in_array(_a($f, 'type'), [null, '', 'ATHLETE.INDIVIDUAL', 'INDIVIDUAL'], true)) {
                $sessie_fee = _fee($f);
            }
        }
        $nummers = [];
        foreach (_els($s, 'EVENTS/EVENT') as $e) {
            $st = _el($e, 'SWIMSTYLE');
            if ($st === null) {
                continue;
            }
            $stroke = strtoupper(_a($st, 'stroke') ?: 'UNKNOWN');
            $ronde = strtoupper(_a($e, 'round') ?: 'TIM');
            $vorige = _a($e, 'preveventid');
            $heeft_vorige = !in_array($vorige, [null, '', '-1', '0'], true);
            $kost = _fee(_el($e, 'FEE'));
            if ($kost === null) {
                $kost = $sessie_fee !== null ? $sessie_fee : ($standaard_fee ?: 0);
            }
            foreach (_els($e, 'TIMESTANDARDREFS/TIMESTANDARDREF') as $r) {
                $gebruikte_refs[] = _a($r, 'timestandardlistid');
            }
            $groepen = [];
            foreach (_els($e, 'AGEGROUPS/AGEGROUP') as $a) {
                $groepen[] = [_int(_a($a, 'agemin'), -1), _int(_a($a, 'agemax'), -1)];
            }
            $nummers[] = [
                'eventid' => _a($e, 'eventid') ?: (_a($s, 'number') . '-' . _a($e, 'number')),
                'nummer' => _int(_a($e, 'number')),
                'volgorde' => _int(_a($e, 'order'), _int(_a($e, 'number'))),
                'geslacht' => _geslacht(_a($e, 'gender')),
                'afstand' => _int(_a($st, 'distance')),
                'slag' => $stroke,
                'naam' => !in_array($stroke, SLAGEN, true) ? (_a($st, 'name') ?? '') : '',
                'aflossing' => max(_int(_a($st, 'relaycount'), 1), 1),
                'ronde' => $ronde,
                'inschrijfbaar' => !$heeft_vorige && !in_array($ronde, VERVOLGRONDES, true) && in_array($stroke, SLAGEN, true),
                'kost_cent' => $kost,
                'leeftijdsgroepen' => $groepen ?: [[-1, -1]],
            ];
        }
        $d = _datum(_a($s, 'date'));
        if (!$d) {
            $waarschuwingen[] = 'Sessie ' . _a($s, 'number') . ' heeft geen datum.';
        }
        $sessies[] = [
            'nummer' => _int(_a($s, 'number'), count($sessies) + 1),
            'datum' => $d,
            'starttijd' => _tijd(_a($s, 'daytime')),
            'opwarmen' => _tijd(_a($s, 'warmupfrom')),
            'nummers' => $nummers,
        ];
    }
    if (!array_filter($sessies, fn($s) => $s['nummers'])) {
        throw new LenexFout('Het bestand bevat geen programma (geen sessies of nummers).');
    }
    $data = array_filter(array_column($sessies, 'datum'));
    $eerste_dag = $data ? min($data) : null;
    $vandaag = vandaag()->format('Y-m-d');
    foreach ($sessies as &$s) {
        $s['datum'] = $s['datum'] ?: ($eerste_dag ?: $vandaag);
    }
    unset($s);

    $deadline = _datum(_a($meet, 'deadline'));
    if (!$deadline) {
        $deadline = (new \DateTimeImmutable($eerste_dag ?: $vandaag))->modify('-7 days')->format('Y-m-d');
        $waarschuwingen[] = 'Geen laatste inschrijfdatum in het bestand: voorlopig 7 dagen voor de wedstrijd gezet. Pas dit aan.';
    }

    $limietlijsten = [];
    // Limieten staan soms onder MEET, soms (SPLASH) rechtstreeks onder LENEX.
    foreach (array_merge(_els($meet, 'TIMESTANDARDLISTS/TIMESTANDARDLIST'), _els($root, 'TIMESTANDARDLISTS/TIMESTANDARDLIST')) as $lst) {
        $ag = _el($lst, 'AGEGROUP');
        $limieten = [];
        foreach (_els($lst, 'TIMESTANDARDS/TIMESTANDARD') as $t) {
            $sst = _el($t, 'SWIMSTYLE');
            $tijd = parse_tijd(_a($t, 'swimtime'));
            if ($sst !== null && $tijd && _int(_a($sst, 'relaycount'), 1) <= 1) {
                $limieten[] = ['afstand' => _int(_a($sst, 'distance')), 'slag' => strtoupper(_a($sst, 'stroke') ?? ''), 'tijd' => $tijd];
            }
        }
        if (!$limieten) {
            continue;
        }
        $lid = _a($lst, 'timestandardlistid');
        $limietlijsten[] = [
            'lenex_id' => $lid ?? '',
            'naam' => _a($lst, 'name') ?: ('Limiet ' . (_a($lst, 'code') ?: $lid)),
            'code' => _a($lst, 'code') ?? '',
            'soort' => strtoupper(_a($lst, 'type') ?: 'DEFAULT'),
            'baan' => _baan(_a($lst, 'course')),
            'geslacht' => _geslacht(_a($lst, 'gender')),
            'agemin' => $ag !== null ? _int(_a($ag, 'agemin'), -1) : -1,
            'agemax' => $ag !== null ? _int(_a($ag, 'agemax'), -1) : -1,
            'voorgeselecteerd' => $lid !== null && in_array($lid, $gebruikte_refs, true),
            'limieten' => $limieten,
        ];
    }

    return [
        'naam' => _a($meet, 'name') ?: 'Wedstrijd',
        'stad' => _a($meet, 'city') ?: (_a($facility, 'city') ?? ''),
        'zwembad' => _a($pool, 'name') ?: (_a($facility, 'name') ?? ''),
        'baan' => _baan(_a($meet, 'course')),
        'deadline' => $deadline,
        'agedate' => $agedate !== null ? _datum(_a($agedate, 'value')) : null,
        'agedate_type' => $agedate !== null ? strtoupper(_a($agedate, 'type') ?: 'YEAR') : 'YEAR',
        'kwalificatie_van' => $qualify !== null ? _datum(_a($qualify, 'from')) : null,
        'kwalificatie_tot' => $qualify !== null ? _datum(_a($qualify, 'until')) : null,
        'sessies' => $sessies,
        'limietlijsten' => $limietlijsten,
        'waarschuwingen' => $waarschuwingen,
    ];
}

/** Alle individuele resultaten uit een uitslagen-lenex. */
function parse_uitslagen(\SimpleXMLElement $root): array
{
    $resultaten = [];
    foreach (_els($root, 'MEETS/MEET') as $meet) {
        $baan = _baan(_a($meet, 'course'));
        $stijlen = $data = [];
        foreach (_els($meet, 'SESSIONS/SESSION') as $s) {
            foreach (_els($s, 'EVENTS/EVENT') as $e) {
                $st = _el($e, 'SWIMSTYLE');
                if ($st !== null && _int(_a($st, 'relaycount'), 1) <= 1) {
                    $stijlen[_a($e, 'eventid')] = [_int(_a($st, 'distance')), strtoupper(_a($st, 'stroke') ?? '')];
                    $data[_a($e, 'eventid')] = _datum(_a($s, 'date'));
                }
            }
        }
        foreach (_els($meet, 'CLUBS/CLUB/ATHLETES/ATHLETE') as $a) {
            foreach (_els($a, 'RESULTS/RESULT') as $r) {
                $tijd = parse_tijd(_a($r, 'swimtime'));
                $eid = _a($r, 'eventid');
                if (!$tijd || _a($r, 'status') || !isset($stijlen[$eid])) {
                    continue; // DSQ/DNS/DNF of aflossing
                }
                [$afstand, $slag] = $stijlen[$eid];
                if (!in_array($slag, SLAGEN, true)) {
                    continue;
                }
                $resultaten[] = [
                    'voornaam' => _a($a, 'firstname') ?? '', 'naam' => _a($a, 'lastname') ?? '',
                    'geboortedatum' => _datum(_a($a, 'birthdate')), 'licentie' => _a($a, 'license') ?? '',
                    'geslacht' => _a($a, 'gender') ?? '', 'afstand' => $afstand, 'slag' => $slag, 'baan' => $baan,
                    'tijd' => $tijd, 'datum' => $data[$eid] ?: vandaag()->format('Y-m-d'),
                    'wedstrijd' => mb_substr(_a($meet, 'name') ?? '', 0, 120),
                ];
            }
        }
    }
    return $resultaten;
}

function _sleutel(string $voornaam, string $naam, ?string $gd): string
{
    return mb_strtolower(trim($voornaam)) . '|' . mb_strtolower(trim($naam)) . '|' . $gd;
}

/** Bewaart per clubzwemmer de beste tijd per afstand/slag/baan. Koppeling via licentie, anders via naam + geboortedatum. */
function importeer_uitslagen(array $resultaten): array
{
    $op_licentie = $op_naam = [];
    foreach (db()->get_results("SELECT * FROM " . t('zwemmers')) as $z) {
        if (trim($z->licentie) !== '') {
            $op_licentie[trim($z->licentie)] = $z;
        }
        $op_naam[_sleutel($z->voornaam, $z->naam, $z->geboortedatum)] = $z;
    }
    $stats = ['resultaten' => count($resultaten), 'gekoppeld' => 0, 'nieuw' => 0, 'verbeterd' => 0, 'onbekend' => []];
    db()->query('START TRANSACTION');
    foreach ($resultaten as $r) {
        $z = (trim($r['licentie']) !== '' ? ($op_licentie[trim($r['licentie'])] ?? null) : null)
            ?? $op_naam[_sleutel($r['voornaam'], $r['naam'], $r['geboortedatum'])] ?? null;
        if (!$z) {
            $stats['onbekend'][$r['voornaam'] . ' ' . $r['naam']] = true;
            continue;
        }
        $stats['gekoppeld']++;
        $bestaande = db()->get_row(db()->prepare(
            "SELECT * FROM " . t('tijden') . " WHERE zwemmer_id=%d AND afstand=%d AND slag=%s AND baan=%s ORDER BY tijd LIMIT 1",
            $z->id, $r['afstand'], $r['slag'], $r['baan']
        ));
        $velden = ['tijd' => $r['tijd'], 'datum' => $r['datum'], 'wedstrijd' => $r['wedstrijd'], 'bron' => 'uitslagen'];
        if (!$bestaande) {
            db()->insert(t('tijden'), $velden + ['zwemmer_id' => $z->id, 'afstand' => $r['afstand'], 'slag' => $r['slag'], 'baan' => $r['baan']]);
            $stats['nieuw']++;
        } elseif ($r['tijd'] < (int) $bestaande->tijd) {
            db()->update(t('tijden'), $velden, ['id' => $bestaande->id]);
            $stats['verbeterd']++;
        }
    }
    db()->query('COMMIT');
    $stats['onbekend'] = array_keys($stats['onbekend']);
    sort($stats['onbekend']);
    return $stats;
}

/**
 * Schrijft een geparste meet naar de database. Bij een nieuwe versie van dezelfde wedstrijd blijven
 * bestaande inschrijvingen behouden (gekoppeld via eventid). Geeft het id van de wedstrijd terug.
 */
function importeer(array $meet, string $bestand, ?int $wid = null): int
{
    $velden = [];
    foreach (['naam', 'stad', 'zwembad', 'baan', 'deadline', 'agedate', 'agedate_type', 'kwalificatie_van', 'kwalificatie_tot'] as $k) {
        $velden[$k] = $meet[$k];
    }
    $velden['lenex_bestand'] = $bestand;
    db()->query('START TRANSACTION');
    if ($wid === null) {
        db()->insert(t('wedstrijden'), $velden + ['info' => '', 'status' => W_CONCEPT, 'aangemaakt' => nu_sql()]);
        $wid = (int) db()->insert_id;
        $gekozen = [];
    } else {
        $oud = wedstrijd($wid);
        if ($oud && $oud->lenex_bestand && $oud->lenex_bestand !== $bestand) {
            @unlink(prive_map() . '/' . $oud->lenex_bestand);
        }
        db()->update(t('wedstrijden'), $velden, ['id' => $wid]);
        $gekozen = [];
        foreach (gekozen_limietlijsten($wid) as $l) {
            $gekozen["{$l->naam}|{$l->baan}"] = true;
        }
    }

    $oude_nummers = [];
    foreach (nummers_van($wid) as $n) {
        $oude_nummers[$n->eventid] = $n;
    }
    $behouden = [];
    foreach ($meet['sessies'] as $s) {
        $sid = db()->get_var(db()->prepare("SELECT id FROM " . t('sessies') . " WHERE wedstrijd_id=%d AND nummer=%d", $wid, $s['nummer']));
        $sv = ['datum' => $s['datum'], 'starttijd' => $s['starttijd'], 'opwarmen' => $s['opwarmen']];
        if ($sid) {
            db()->update(t('sessies'), $sv, ['id' => $sid]);
        } else {
            db()->insert(t('sessies'), $sv + ['wedstrijd_id' => $wid, 'nummer' => $s['nummer']]);
            $sid = db()->insert_id;
        }
        foreach ($s['nummers'] as $n) {
            $data = ['sessie_id' => $sid];
            foreach (['nummer', 'volgorde', 'geslacht', 'afstand', 'slag', 'naam', 'aflossing', 'ronde', 'kost_cent'] as $k) {
                $data[$k] = $n[$k];
            }
            $data['inschrijfbaar'] = $n['inschrijfbaar'] ? 1 : 0;
            $obj = $oude_nummers[$n['eventid']] ?? null;
            if ($obj && !isset($behouden[$n['eventid']])) {
                db()->update(t('nummers'), $data, ['id' => $obj->id]);
                db()->delete(t('leeftijdsgroepen'), ['nummer_id' => $obj->id]);
                $nid = (int) $obj->id;
            } else {
                db()->insert(t('nummers'), $data + ['eventid' => $n['eventid']]);
                $nid = (int) db()->insert_id;
            }
            $behouden[$n['eventid']] = true;
            foreach ($n['leeftijdsgroepen'] as [$a, $b]) {
                db()->insert(t('leeftijdsgroepen'), ['nummer_id' => $nid, 'agemin' => $a, 'agemax' => $b]);
            }
        }
    }
    verwijder_nummers(array_map(fn($n) => (int) $n->id, array_filter($oude_nummers, fn($n) => !isset($behouden[$n->eventid]))));
    // Lege sessies weg.
    db()->query(db()->prepare("DELETE s FROM " . t('sessies') . " s LEFT JOIN " . t('nummers') . " n ON n.sessie_id=s.id WHERE s.wedstrijd_id=%d AND n.id IS NULL", $wid));

    verwijder_limietlijsten($wid);
    foreach ($meet['limietlijsten'] as $lst) {
        $sleutel = "{$lst['naam']}|{$lst['baan']}";
        if ($lst['voorgeselecteerd']) {
            $gekozen[$sleutel] = true;
        }
        $rij = [];
        foreach (['naam', 'code', 'soort', 'baan', 'geslacht', 'agemin', 'agemax'] as $k) {
            $rij[$k] = $lst[$k];
        }
        db()->insert(t('limietlijsten'), $rij + ['wedstrijd_id' => $wid, 'gekozen' => 0]);
        $lid = (int) db()->insert_id;
        foreach ($lst['limieten'] as $l) {
            db()->insert(t('limieten'), $l + ['lijst_id' => $lid]);
        }
    }
    // Gekozen selectiecriteria opnieuw koppelen na een update.
    foreach (limietlijsten($wid, false) as $l) {
        if (isset($gekozen["{$l->naam}|{$l->baan}"])) {
            db()->update(t('limietlijsten'), ['gekozen' => 1], ['id' => $l->id]);
        }
    }
    db()->query('COMMIT');
    do_action('khzs_wedstrijd_bewaard', $wid);
    return $wid;
}

/** Maakt een Lenex 3.0-inschrijvingsbestand (.lxf) voor de organisator. */
function bouw_inschrijvingen(object $w, array $inschrijvingen): string
{
    $club = club();
    $doc = new \DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $el = function (\DOMNode $ouder, string $naam, array $attr = []) use ($doc) {
        $e = $doc->createElement($naam);
        foreach ($attr as $k => $v) {
            $e->setAttribute($k, (string) $v);
        }
        $ouder->appendChild($e);
        return $e;
    };
    $root = $el($doc, 'LENEX', ['version' => '3.0']);
    $el($root, 'CONSTRUCTOR', ['name' => 'KHZS Leden', 'registration' => $club['naam'], 'version' => '1.0']);
    $meet = $el($el($root, 'MEETS'), 'MEET', ['name' => $w->naam, 'city' => $w->stad, 'course' => $w->baan,
        'deadline' => dag($w->deadline)->format('Y-m-d'), 'nation' => 'BEL']);
    $c = $el($el($meet, 'CLUBS'), 'CLUB', ['name' => $club['naam'], 'code' => $club['code'], 'nation' => $club['nation']]);
    $atleten = $el($c, 'ATHLETES');

    $per_zwemmer = [];
    foreach ($inschrijvingen as $i) {
        $per_zwemmer[$i->zwemmer->id] ??= [$i->zwemmer, []];
        $per_zwemmer[$i->zwemmer->id][1][] = $i;
    }
    uasort($per_zwemmer, fn($a, $b) => [$a[0]->naam, $a[0]->voornaam] <=> [$b[0]->naam, $b[0]->voornaam]);
    $nr = 0;
    foreach ($per_zwemmer as [$z, $lijst]) {
        $attr = ['athleteid' => ++$nr, 'firstname' => $z->voornaam, 'lastname' => $z->naam,
            'birthdate' => $z->geboortedatum, 'gender' => $z->geslacht, 'nation' => 'BEL'];
        if ($z->licentie) {
            $attr['license'] = $z->licentie;
        }
        $a = $el($atleten, 'ATHLETE', $attr);
        $entries = $el($a, 'ENTRIES');
        usort($lijst, fn($x, $y) => [(int) $x->nummer->sessie->nummer, (int) $x->nummer->volgorde] <=> [(int) $y->nummer->sessie->nummer, (int) $y->nummer->volgorde]);
        foreach ($lijst as $i) {
            $tijd = $i->inschrijftijd === null ? null : (int) $i->inschrijftijd;
            $e = $el($entries, 'ENTRY', ['eventid' => $i->nummer->eventid, 'entrytime' => format_tijd($tijd)]);
            if ($tijd !== null) {
                $e->setAttribute('entrycourse', $i->inschrijfbaan ?: $w->baan);
            }
        }
    }
    $xml = $doc->saveXML();
    $tmp = tijdelijk();
    $zip = new \ZipArchive();
    $zip->open($tmp, \ZipArchive::OVERWRITE);
    $zip->addFromString("Inschrijvingen {$club['naam']} - {$w->naam}.lef", $xml);
    $zip->close();
    $data = file_get_contents($tmp);
    @unlink($tmp);
    return $data;
}

function bestandsnaam(object $w, string $ext): string
{
    $veilig = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $w->naam), '_');
    return "inschrijvingen_{$veilig}_" . current_datetime()->format('Ymd') . ".$ext";
}
