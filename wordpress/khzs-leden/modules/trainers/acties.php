<?php
/** Module trainers: pagina's en acties. */

namespace KHZS;

defined('ABSPATH') || exit;

/** Trainers, en het bestuur en het sportsecretariaat (die de planning van iedereen zien). */
function tr_vereist_trainer(): void
{
    vereist_login();
    if (!tr_is_trainer() && !tr_mag_beheren()) {
        geen_toegang();
    }
}

function tr_vereist_penningmeester(): void
{
    vereist_login();
    if (!is_penningmeester()) {
        geen_toegang('Enkel de penningmeester heeft toegang tot de verloning.');
    }
}

function tr_vereist_beheer(): void
{
    vereist_login();
    if (!tr_mag_beheren()) {
        geen_toegang('Enkel het bestuur en het sportsecretariaat zien de planning en uren van alle trainers.');
    }
}

function tr_training_url(object $m, string $datum): string
{
    return app_url("trainers/training/{$m->id}/$datum/");
}

function tr_moment_tekst(object $m, string $datum): string
{
    return "{$m->naam} " . datum($datum, 'D j/m') . ' ' . substr($m->start, 0, 5);
}

/**
 * Mag de aangemelde gebruiker de aanwezigheid van $uid op deze training aanpassen?
 * Voor de eigen trainingen gelden altijd de regels van een trainer, ook voor wie in het bestuur zit:
 * na uitbetaling corrigeert een ander lid van het bestuur of het secretariaat.
 */
function tr_mag_bevestigen(object $b, int $uid): bool
{
    if ($b->afgelast || !isset($b->aanwezig[$uid]) || !tr_afgelopen($b)) {
        return false;
    }
    return $uid === get_current_user_id() ? !tr_vergrendeld($uid, $b->datum) : tr_mag_beheren();
}

/** Zet alle niet bevestigde, voorbije trainingen van $uid in [van, tot] op 'aanwezig'. Geeft het aantal terug. */
function tr_alles_bevestigen(int $uid, string $van, string $tot, int $door): int
{
    $n = 0;
    foreach (tr_trainingen($van, min($tot, vandaag()->format('Y-m-d'))) as $b) {
        if (!$b->afgelast && isset($b->aanwezig[$uid]) && tr_afgelopen($b) && tr_status($b, $uid) === 'open'
            && ($door !== $uid || !tr_vergrendeld($uid, $b->datum))) {
            tr_zet_bevestiging($b->moment, $b->datum, $uid, true, $door, $door === $uid ? 'trainer' : 'bestuur');
            $n++;
        }
    }
    return $n;
}

// ---------- mijn trainingen ----------

function v_tr_mijn(): void
{
    tr_vereist_trainer();
    if (!tr_is_trainer()) {
        naar(app_url('trainers/overzicht/')); // bestuur zonder trainersrol
    }
    $uid = get_current_user_id();
    $vandaag = vandaag()->format('Y-m-d');
    // Zelf bevestigen kan tot het begin van vorige maand, en niet in een maand die al uitbetaald is.
    $terug_tot = max(vandaag()->modify('first day of previous month')->format('Y-m-d'), tr_eerste_open_dag($uid));
    if (is_post() && post('actie') === 'alles_ja') {
        $n = tr_alles_bevestigen($uid, $terug_tot, $vandaag, $uid);
        flash($n === 1 ? '1 training bevestigd.' : "$n trainingen bevestigd.");
        naar(app_url('trainers/'));
    }
    $van = min($terug_tot, vandaag()->modify('-27 days')->format('Y-m-d'));
    $tot = vandaag()->modify('+27 days')->format('Y-m-d');
    $mijn = $open = $te_bevestigen = $afgelopen = $zwemmers_aanduiden = [];
    $week_terug = vandaag()->modify('-6 days')->format('Y-m-d');
    foreach (tr_trainingen($van, $tot) as $b) {
        $op = isset($b->aanwezig[$uid]);
        // Eigen trainingen van de voorbije week (en vandaag) waarop de zwemmers nog niet aangeduid zijn.
        if ($op && $b->datum >= $week_terug && tr_zw_kan($b) && ($zp = tr_zw_ploegen_van($b)) && !tr_zw_telling($b, $zp)
            && array_filter(array_keys($zp), fn($p) => ploeg_zwemmers($p))) {
            $zwemmers_aanduiden[] = $b;
        }
        if ($b->datum <= $vandaag && $op && !$b->afgelast && tr_afgelopen($b)) {
            if ($b->datum >= vandaag()->modify('-27 days')->format('Y-m-d')) {
                $afgelopen[] = $b;
            }
            if ($b->datum >= $terug_tot && tr_status($b, $uid) === 'open') {
                $te_bevestigen[] = $b;
            }
        }
        if ($b->datum < $vandaag) {
            continue;
        }
        if ($op || isset($b->afwezig[$uid])) {
            $mijn[] = $b;
        }
        if (!$b->afgelast && $b->open && !$op && !in_array($uid, $b->open, true)) {
            $open[] = $b;
        }
    }
    $momenten = tr_momenten($vandaag, '9999-12-31');
    $vast = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE user_id=%d AND (tot IS NULL OR tot>=%s)", $uid, $vandaag)) as $v) {
        $vast[(int) $v->moment_id] = $v;
    }
    $maand = tr_maand(vandaag()->format('Y-m'), $uid)[$uid] ?? null;
    toon('trainers/mijn', ['uid' => $uid, 'mijn' => $mijn, 'open' => $open, 'momenten' => $momenten, 'vast' => $vast, 'maand' => $maand,
        'te_bevestigen' => $te_bevestigen, 'zwemmers_aanduiden' => array_reverse($zwemmers_aanduiden), 'afgelopen' => array_reverse($afgelopen), 'ongelezen' => tr_logboek($uid, true, 20),
        'terug_tot' => $terug_tot, 'buiten_seizoen' => !tr_in_seizoen($vandaag)], 'Mijn trainingen');
}

/** Wat het bestuur of het secretariaat aan mijn planning veranderde. Bij het openen wordt alles gelezen. */
function v_tr_wijzigingen(): void
{
    tr_vereist_trainer();
    $uid = get_current_user_id();
    $log = tr_logboek($uid);
    db()->update(t('tr_log'), ['gelezen' => 1], ['user_id' => $uid, 'gelezen' => 0]);
    toon('trainers/wijzigingen', ['log' => $log], 'Wijzigingen aan mijn planning', 'smal');
}

/** Antwoord op "Was je aanwezig?" vanuit de pushmelding: met een token, ook zonder aan te melden. */
function v_tr_bevestig(): void
{
    $token = is_post() ? post('t') : get('t');
    $json = post('json') === '1';
    $fout = function (string $tekst) use ($json): never {
        if ($json) {
            wp_send_json(['ok' => false, 'titel' => 'Niet bewaard', 'tekst' => $tekst, 'url' => app_url('trainers/')], 400);
        }
        toon('fout', ['titel' => 'Niet gelukt', 'tekst' => $tekst], 'Aanwezigheid', 'smal', 400);
    };
    $lees = tr_token_lees($token);
    if (!$lees) {
        $fout('Deze link is ongeldig of verlopen. Open de app en duid je aanwezigheid aan bij Trainingen.');
    }
    [$uid, $mid, $datum] = $lees;
    $m = tr_moment($mid);
    if (!$m || !tr_geldig($m, $datum)) {
        $fout('Deze training bestaat niet meer.');
    }
    $b = tr_bezetting($m, $datum, tr_context($datum, $datum));
    if ($b->afgelast) {
        $fout("Deze training is afgelast ({$b->afgelast}).");
    }
    if (!isset($b->aanwezig[$uid])) {
        $fout('Je staat niet (meer) op deze training.');
    }
    $vergrendeld = tr_vergrendeld($uid, $datum);
    if (is_post()) {
        $antwoord = post('antwoord');
        if (!in_array($antwoord, ['ja', 'nee'], true)) {
            $fout('Kies ja of nee.');
        }
        if (!tr_afgelopen($b)) {
            $fout('Je kan pas na de training bevestigen.');
        }
        if ($vergrendeld) {
            $fout('Deze maand is al uitbetaald. Vraag het bestuur om een correctie.');
        }
        tr_zet_bevestiging($m, $datum, $uid, $antwoord === 'ja', $uid, 'melding');
        $tekst = ($antwoord === 'ja' ? 'Genoteerd: je was aanwezig op ' : 'Genoteerd: je was niet aanwezig op ') . tr_moment_kort($m, $datum) . '.';
        if ($json) {
            wp_send_json(['ok' => true, 'titel' => 'Bedankt!', 'tekst' => $tekst, 'url' => app_url('trainers/')]);
        }
        flash($tekst);
        naar(app_url('trainers/bevestig/', ['t' => $token]));
    }
    toon('trainers/bevestig', ['b' => $b, 'm' => $m, 'datum' => $datum, 'wie' => $uid, 'token' => $token, 'status' => tr_status($b, $uid),
        'door' => tr_bevestigd_door($b, $uid), 'vergrendeld' => $vergrendeld], 'Was je aanwezig?', 'smal');
}

// ---------- planning ----------

function v_tr_planning(): void
{
    tr_vereist_trainer();
    $week = dag(get('week')) ?? vandaag();
    $maandag = $week->modify('-' . ((int) $week->format('N') - 1) . ' days');
    $van = $maandag->format('Y-m-d');
    $tot = $maandag->modify('+6 days')->format('Y-m-d');
    $per_dag = [];
    foreach (tr_trainingen($van, $tot) as $b) {
        $per_dag[$b->datum][] = $b;
    }
    $seizoenen = tr_seizoenen();
    $buiten = array_filter(array_map(fn($i) => $maandag->modify("+$i days")->format('Y-m-d'), range(0, 6)), fn($d) => !tr_in_seizoen($d, $seizoenen));
    toon('trainers/planning', ['maandag' => $maandag, 'per_dag' => $per_dag, 'uid' => get_current_user_id(), 'buiten' => count($buiten)], 'Planning trainers');
}

/**
 * Kalender met de weergaven dag, week en maand. Een trainer ziet standaard de eigen trainingen (wie=mij) en kan naar
 * alle trainingen; het bestuur en het secretariaat zien standaard alles en filteren op ploeg of trainer.
 */
function v_tr_kalender(): void
{
    tr_vereist_trainer();
    $uid = get_current_user_id();
    $beheer = tr_mag_beheren();
    $weergave = in_array(get('weergave'), ['dag', 'week', 'maand'], true) ? get('weergave') : 'week';
    $dag = dag(get('datum')) ?? vandaag();
    if ($weergave === 'dag') {
        [$van, $tot] = [$dag, $dag];
    } elseif ($weergave === 'week') {
        $van = $dag->modify('-' . ((int) $dag->format('N') - 1) . ' days');
        $tot = $van->modify('+6 days');
    } else {
        $eerste = $dag->modify('first day of this month');
        $van = $eerste->modify('-' . ((int) $eerste->format('N') - 1) . ' days');
        $laatste = $dag->modify('last day of this month');
        $tot = $laatste->modify('+' . (7 - (int) $laatste->format('N')) . ' days');
    }
    // Filter: mij, alle of (bestuur) het id van een trainer; en een ploeg.
    $wie = get('wie') ?: ($beheer ? 'alle' : 'mij');
    if ($wie !== 'mij' && $wie !== 'alle' && (!$beheer || !ctype_digit($wie))) {
        $wie = 'mij';
    }
    $ploeg = (int) get('ploeg');
    $moment_ploegen = tr_moment_ploegen();
    $per_dag = [];
    foreach (tr_trainingen($van->format('Y-m-d'), $tot->format('Y-m-d')) as $b) {
        $wie_id = $wie === 'mij' ? $uid : (int) $wie;
        if ($wie !== 'alle' && !isset($b->aanwezig[$wie_id]) && !isset($b->afwezig[$wie_id])) {
            continue;
        }
        if ($ploeg && !in_array($ploeg, $moment_ploegen[(int) $b->moment->id] ?? [], true)) {
            continue;
        }
        $b->ploegen = $moment_ploegen[(int) $b->moment->id] ?? [];
        $per_dag[$b->datum][] = $b;
    }
    $stap = ['dag' => '1 day', 'week' => '7 days', 'maand' => '1 month'][$weergave];
    $ref = $weergave === 'maand' ? $dag->modify('first day of this month') : $van;
    toon('trainers/kalender', ['weergave' => $weergave, 'dag' => $dag, 'van' => $van, 'tot' => $tot, 'per_dag' => $per_dag, 'uid' => $uid,
        'beheer' => $beheer, 'wie' => $wie, 'ploeg' => $ploeg, 'ploegen' => tr_alle_ploegen(), 'trainers' => $beheer ? tr_trainers() : [],
        'vorige' => $ref->modify("-$stap")->format('Y-m-d'), 'volgende' => $ref->modify("+$stap")->format('Y-m-d')], 'Kalender');
}

function v_tr_training($id, $datum): void
{
    tr_vereist_trainer();
    $m = tr_moment((int) $id) ?: niet_gevonden();
    if (!tr_geldig($m, $datum)) {
        niet_gevonden();
    }
    $uid = get_current_user_id();
    $b = tr_bezetting($m, $datum, tr_context($datum, $datum));
    $wijz = db()->get_results(db()->prepare("SELECT * FROM " . t('tr_wijzigingen') . " WHERE moment_id=%d AND datum=%s ORDER BY aangemaakt", $m->id, $datum));
    $beheer = tr_mag_beheren();
    $voorbij = $datum < vandaag()->format('Y-m-d');
    $zw_ploegen = tr_zw_ploegen_van($b);
    toon('trainers/training', ['b' => $b, 'm' => $m, 'datum' => $datum, 'uid' => $uid, 'wijz' => $wijz, 'trainers' => tr_trainers(),
        'beheer' => $beheer, 'voorbij' => $voorbij, 'mag' => $beheer || !$voorbij || (tr_is_trainer() && !tr_vergrendeld($uid, $datum)),
        'zw_ploegen' => $zw_ploegen, 'zw_lijst' => $zw_ploegen && tr_zw_kan($b) ? tr_zw_lijst($b, $zw_ploegen) : []], tr_moment_tekst($m, $datum));
}

/**
 * Aanwezigheid van de zwemmers op een training bewaren of wissen. Enkel voor de ploegen van de training die de
 * gebruiker mag aanduiden (tr_zw_mag_ploeg); de andere ploegen van dezelfde training blijven onaangeroerd.
 */
function v_tr_zwemmers_actie(): void
{
    tr_vereist_trainer();
    vereist_post();
    $m = tr_moment((int) post('moment')) ?: niet_gevonden();
    $datum = post('datum');
    if (!tr_geldig($m, $datum)) {
        niet_gevonden();
    }
    $terug = terug(tr_training_url($m, $datum) . '#zwemmers');
    $b = tr_bezetting($m, $datum, tr_context($datum, $datum));
    $ploegen = tr_zw_ploegen_van($b);
    if (!$ploegen) {
        geen_toegang('Je mag de aanwezigheid van de zwemmers van deze training niet aanduiden.');
    }
    if (!tr_zw_kan($b)) {
        flash($b->afgelast ? "Deze training is afgelast ({$b->afgelast})." : 'Aanduiden kan vanaf de dag van de training.', 'error');
        naar($terug);
    }
    if (post('actie') === 'wis') {
        tr_zw_wis($b, $ploegen);
        flash('De aanwezigheid van de zwemmers is gewist.');
        naar($terug);
    }
    [$ja, $totaal] = tr_zw_bewaar($b, tr_zw_lijst($b, $ploegen), (array) ($_POST['zwemmer'] ?? []), get_current_user_id());
    flash("Bewaard: $ja van de $totaal zwemmers aanwezig op " . tr_moment_tekst($m, $datum) . '.');
    naar($terug);
}

/** Periode voor het overzicht van de zwemmers: standaard het lopende seizoen, anders de laatste drie maanden. */
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

/** Welke ploegen het overzicht toont: de zichtbare, of één ervan (?ploeg=). */
function tr_zw_gekozen(): array
{
    $mag = tr_zw_ploegen();
    $ploeg = (int) get('ploeg');
    return $ploeg && isset($mag[$ploeg]) ? [$ploeg => $mag[$ploeg]] : $mag;
}

/** Overzicht: aanwezigheid per zwemmer en per ploeg over een periode, met een percentage. Enkel voor trainers en bestuur. */
function v_tr_zwemmers(): void
{
    tr_vereist_trainer();
    $mag = tr_zw_ploegen();
    if (!$mag) {
        geen_toegang('Je traint geen ploegen waarvan je de aanwezigheid kunt zien.');
    }
    [$van, $tot] = tr_zw_periode();
    $ploeg = (int) get('ploeg');
    $ploeg = isset($mag[$ploeg]) ? $ploeg : 0;
    toon('trainers/zwemmers', ['van' => $van, 'tot' => $tot, 'ploeg' => $ploeg, 'ploegen' => $mag,
        'o' => tr_zw_overzicht($van, $tot, array_keys(tr_zw_gekozen()))], 'Aanwezigheid zwemmers');
}

function v_tr_zwemmers_export(): void
{
    tr_vereist_trainer();
    $gekozen = tr_zw_gekozen();
    if (!$gekozen) {
        geen_toegang();
    }
    [$van, $tot] = tr_zw_periode();
    $o = tr_zw_overzicht($van, $tot, array_keys($gekozen));
    $utc = new \DateTimeZone('UTC');
    $per_zwemmer = [['Zwemmer', 'Ploeg', 'Aanwezig', 'Afwezig', 'Trainingen', 'Aanwezig (%)']];
    foreach ($o['zwemmers'] as $z) {
        $n = $z['aanwezig'] + $z['afwezig'];
        $per_zwemmer[] = [$z['naam'], implode(', ', $z['ploegen']), $z['aanwezig'], $z['afwezig'], $n, $n ? round(100 * $z['aanwezig'] / $n, 1) : 0];
    }
    $per_ploeg = [['Ploeg', 'Soort', 'Aangeduide trainingen', 'Aanwezigheden', 'Mogelijke aanwezigheden', 'Aanwezig (%)']];
    foreach ($o['ploegen'] as $p) {
        $per_ploeg[] = [$p['naam'], PL_SOORT[$p['soort']] ?? $p['soort'], $p['trainingen'], $p['aanwezig'], $p['totaal'],
            $p['totaal'] ? round(100 * $p['aanwezig'] / $p['totaal'], 1) : 0];
    }
    $detail = [['Datum', 'Training', 'Uur', 'Ploeg', 'Zwemmer', 'Aanwezig']];
    $alle = tr_ploegen_per_id();
    foreach ($o['detail'] as $r) {
        $detail[] = [new \DateTimeImmutable($r->datum, $utc), (string) $r->mnaam, $r->start ? substr($r->start, 0, 5) . '–' . substr($r->einde, 0, 5) : '',
            $alle[(int) $r->ploeg_id]->naam ?? '', trim("$r->voornaam $r->znaam"), (int) $r->aanwezig ? 'ja' : 'nee'];
    }
    download(maak_xlsx([['naam' => 'Per zwemmer', 'rijen' => $per_zwemmer], ['naam' => 'Per ploeg', 'rijen' => $per_ploeg],
        ['naam' => 'Detail', 'rijen' => $detail, 'datumkolommen' => [0]]]),
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "aanwezigheid_zwemmers_{$van}_$tot.xlsx");
}

/**
 * Eén actie op één training: afwezig, aanwezig, overnemen, helpen, schrappen, toevoegen, of na de training ja/nee.
 * Op een voorbije training betekent erbij komen (overnemen, helpen, toevoegen, toch aanwezig) dat je er was: dat telt als 'ja'.
 */
function v_tr_actie(): void
{
    tr_vereist_trainer();
    vereist_post();
    $m = tr_moment((int) post('moment')) ?: niet_gevonden();
    $datum = post('datum');
    $actie = post('actie');
    $ik = get_current_user_id();
    $sec = tr_mag_beheren();
    $doel = post('user') !== '' ? (int) post('user') : $ik;
    $terug = terug(tr_training_url($m, $datum));
    if (!tr_geldig($m, $datum)) {
        niet_gevonden();
    }
    if ($doel !== $ik && !$sec) {
        geen_toegang('Enkel het bestuur en het sportsecretariaat kunnen de planning van anderen aanpassen.');
    }
    $voorbij = $datum < vandaag()->format('Y-m-d');
    // Eigen trainingen: trainersregels, ook voor wie in het bestuur zit (niemand keurt zijn eigen uren goed na uitbetaling).
    if ($voorbij && (!$sec || $doel === $ik)) {
        if ($actie === 'afwezig') {
            flash('Deze training is al voorbij. Duid aan of je aanwezig was: ja of nee.', 'error');
            naar($terug);
        }
        if (tr_vergrendeld($ik, $datum)) {
            flash('Deze maand is al uitbetaald. Vraag het bestuur om een correctie.', 'error');
            naar($terug);
        }
    }
    $b = tr_bezetting($m, $datum, tr_context($datum, $datum));
    if ($b->afgelast && in_array($actie, ['overnemen', 'helpen', 'ja', 'nee'], true)) {
        flash("Deze training is afgelast ({$b->afgelast}).", 'error');
        naar($terug);
    }
    $wis = function () use ($m, $datum, $doel) {
        db()->delete(t('tr_wijzigingen'), ['moment_id' => $m->id, 'datum' => $datum, 'user_id' => $doel]);
    };
    $wis_bevestiging = fn() => db()->delete(t('tr_bevestigingen'), ['moment_id' => $m->id, 'datum' => $datum, 'user_id' => $doel]);
    $voeg = fn(string $soort, ?int $vervangt = null, string $reden = '') => db()->insert(t('tr_wijzigingen'), [
        'moment_id' => $m->id, 'datum' => $datum, 'user_id' => $doel, 'soort' => $soort, 'vervangt_id' => $vervangt,
        'reden' => mb_substr($reden, 0, 200), 'door_id' => $ik, 'aangemaakt' => nu_sql()]);
    // Erbij komen op een voorbije training = er geweest.
    $was_er = function () use ($m, $datum, $doel, $ik, $voorbij) {
        if ($voorbij) {
            tr_zet_bevestiging($m, $datum, $doel, true, $ik, $doel === $ik ? 'trainer' : 'bestuur');
        }
    };
    $wie = $doel === $ik ? 'Je' : tr_voornaam($doel);
    $tekst = tr_moment_tekst($m, $datum);
    $log = '';

    switch ($actie) {
        case 'afwezig':
            if (($b->aanwezig[$doel][0] ?? '') !== 'vast') {
                flash('Enkel een vaste trainer van deze training kan zich afmelden. Neem je een training over? Gebruik dan ‘Toch niet’.', 'error');
                break;
            }
            $wis();
            $wis_bevestiging();
            $voeg('afwezig', null, post('reden'));
            $log = "Afgemeld voor $tekst" . (post('reden') ? ' (' . post('reden') . ')' : '');
            if ($voorbij) {
                flash(tr_voornaam($doel) . " staat niet meer op $tekst.");
                break;
            }
            flash(($doel === $ik ? 'Je bent' : tr_voornaam($doel) . ' is') . " afgemeld voor $tekst. Collega's krijgen een melding.");
            $ontvangers = array_values(array_diff(users_met('push_vervanging', TR_STAF), [$doel]));
            stuur($ontvangers, 'Vervanger gezocht', $tekst . ' – ' . tr_voornaam($doel) . ' kan niet' . (post('reden') ? ' (' . post('reden') . ')' : ''),
                tr_training_url($m, $datum));
            break;
        case 'aanwezig':
            if (!isset($b->afwezig[$doel])) {
                break;
            }
            if (isset($b->vervangen[$doel])) {
                flash(tr_voornaam($b->vervangen[$doel]) . ' heeft deze training al overgenomen. Spreek onderling af; wie niet komt, kiest ‘Toch niet’.', 'error');
                break;
            }
            $wis();
            $was_er();
            $log = "Toch ingepland op $tekst";
            flash("$wie geeft $tekst toch.");
            break;
        case 'overnemen':
            $vervangt = (int) post('vervangt');
            if (!in_array($vervangt, $b->open, true)) {
                flash('Deze training is al overgenomen.', 'error');
                break;
            }
            if (isset($b->aanwezig[$doel])) {
                flash("$wie staat al op deze training.", 'error');
                break;
            }
            $wis();
            $voeg('vervanger', $vervangt);
            $was_er();
            $log = "Ingepland als vervanger van " . tr_voornaam($vervangt) . " op $tekst";
            flash("$wie neemt $tekst over van " . tr_voornaam($vervangt) . '.');
            if (!$voorbij && voorkeur($vervangt, 'push_vervanging')) {
                stuur([$vervangt], 'Training overgenomen', tr_voornaam($doel) . " neemt $tekst van je over 👍", tr_training_url($m, $datum));
            }
            break;
        case 'helpen':
            if (isset($b->aanwezig[$doel])) {
                flash("$wie staat al op deze training.", 'error');
                break;
            }
            $wis();
            $voeg('extra');
            $was_er();
            $log = "Ingepland als extra trainer op $tekst";
            flash($voorbij ? "Genoteerd: $wie was er als extra trainer op $tekst." : "$wie helpt mee op $tekst.");
            break;
        case 'schrappen':
            $soort = $b->aanwezig[$doel][0] ?? '';
            if (!in_array($soort, ['vervanger', 'extra'], true)) {
                break;
            }
            $vervangt = $b->aanwezig[$doel][1];
            $wis();
            $wis_bevestiging();
            $log = "Geschrapt van $tekst";
            flash("$wie staat niet meer op $tekst.");
            if (!$voorbij && $soort === 'vervanger' && $vervangt) {
                $ontvangers = array_values(array_diff(users_met('push_vervanging', TR_STAF), [$doel]));
                stuur($ontvangers, 'Opnieuw vervanger gezocht', "$tekst – " . tr_voornaam($vervangt) . ' kan niet', tr_training_url($m, $datum));
            }
            break;
        case 'toevoegen':
            // Bestuur of sportsecretariaat: een trainer toevoegen (extra of als vervanger van een afwezige).
            if (!$sec) {
                geen_toegang();
            }
            if (isset($b->aanwezig[$doel])) {
                flash(tr_voornaam($doel) . ' staat al op deze training.', 'error');
                break;
            }
            $vervangt = (int) post('vervangt');
            $als_vervanger = $vervangt && in_array($vervangt, $b->open, true);
            $wis();
            $voeg($als_vervanger ? 'vervanger' : 'extra', $als_vervanger ? $vervangt : null);
            $was_er();
            $log = "Toegevoegd aan $tekst" . ($als_vervanger ? ' als vervanger van ' . tr_voornaam($vervangt) : '');
            flash(tr_voornaam($doel) . " staat nu op $tekst.");
            break;
        case 'ja':
        case 'nee':
        case 'wis':
            if (!isset($b->aanwezig[$doel])) {
                flash(($doel === $ik ? 'Je stond' : tr_voornaam($doel) . ' stond') . ' niet op deze training.', 'error');
                break;
            }
            if (!tr_afgelopen($b)) {
                flash('Bevestigen kan pas na de training.', 'error');
                break;
            }
            if ($actie === 'wis' && !$sec) {
                break;
            }
            $aanwezig = $actie === 'wis' ? null : $actie === 'ja';
            tr_zet_bevestiging($m, $datum, $doel, $aanwezig, $ik, $doel === $ik ? 'trainer' : 'bestuur');
            $log = ($aanwezig === null ? 'Aanwezigheid teruggezet naar ‘niet bevestigd’' : ($aanwezig ? 'Aanwezig' : 'Niet aanwezig')) . " op $tekst";
            flash($doel === $ik ? ($aanwezig ? "Genoteerd: je was aanwezig op $tekst." : "Genoteerd: je was niet aanwezig op $tekst.")
                : tr_voornaam($doel) . ': ' . mb_strtolower($log) . '. ' . tr_voornaam($doel) . ' krijgt een melding.');
            break;
    }
    if ($log !== '') {
        tr_log($doel, $ik, $log, $m, $datum);
    }
    naar($terug);
}

/**
 * Vaste training aan- of uitzetten vanaf een datum: zelf, of door het bestuur of het secretariaat voor iemand anders.
 * Een trainer kan zelf tot het begin van vorige maand teruggaan, maar niet in een maand die al uitbetaald is.
 */
function v_tr_vast(): void
{
    tr_vereist_trainer();
    vereist_post();
    $m = tr_moment((int) post('moment')) ?: niet_gevonden();
    $ik = get_current_user_id();
    $beheer = tr_mag_beheren();
    $doel = post('user') !== '' ? (int) post('user') : $ik;
    if ($doel !== $ik && !$beheer) {
        geen_toegang();
    }
    $v = (dag(post('vanaf')) ?? vandaag())->format('Y-m-d');
    if (!$beheer || $doel === $ik) {
        $v = max($v, vandaag()->modify('first day of previous month')->format('Y-m-d'), tr_eerste_open_dag($ik));
    }
    $gisteren = (new \DateTimeImmutable($v))->modify('-1 day')->format('Y-m-d');
    $lopend = db()->get_row(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE moment_id=%d AND user_id=%d AND (tot IS NULL OR tot>=%s) ORDER BY van DESC LIMIT 1",
        $m->id, $doel, $v));
    $wie = $doel === $ik ? 'Je' : tr_voornaam($doel);
    $omschrijving = "{$m->naam} (" . TR_DAGEN[$m->weekdag] . ' ' . tr_tijd($m) . ')';
    $log = '';
    if (post('aan') === '1') {
        $begin = max($v, $m->van);
        if ($lopend && $lopend->van <= $begin) {
            flash("$wie geeft {$m->naam} al.", 'info');
        } elseif ($lopend) {
            // Vroeger laten beginnen (bv. vergeten in te geven).
            db()->update(t('tr_vast'), ['van' => $begin], ['id' => $lopend->id]);
            $log = "Vaste training $omschrijving vanaf " . datum($begin, 'j/m/Y');
            flash("$wie geeft {$m->naam} nu vanaf " . datum($begin, 'j/m/Y') . '.');
        } else {
            db()->insert(t('tr_vast'), ['moment_id' => $m->id, 'user_id' => $doel, 'van' => $begin, 'tot' => $m->tot]);
            $log = "Vaste training $omschrijving toegevoegd vanaf " . datum($begin, 'j/m/Y');
            flash("$wie geeft voortaan $omschrijving vanaf " . datum($begin, 'j/m/Y') . '.');
        }
    } elseif ($lopend) {
        if ($lopend->van >= $v) {
            db()->delete(t('tr_vast'), ['id' => $lopend->id]);
        } else {
            db()->update(t('tr_vast'), ['tot' => $gisteren], ['id' => $lopend->id]);
        }
        // Afmeldingen en bevestigingen na de stopdatum zijn niet meer nodig.
        db()->query(db()->prepare("DELETE FROM " . t('tr_wijzigingen') . " WHERE moment_id=%d AND user_id=%d AND soort='afwezig' AND datum>=%s", $m->id, $doel, $v));
        db()->query(db()->prepare("DELETE FROM " . t('tr_bevestigingen') . " WHERE moment_id=%d AND user_id=%d AND datum>=%s", $m->id, $doel, $v));
        $log = "Vaste training $omschrijving gestopt vanaf " . datum($v, 'j/m/Y');
        flash("$wie geeft {$m->naam} niet meer vanaf " . datum($v, 'j/m/Y') . '.');
    }
    if ($log !== '') {
        tr_log($doel, $ik, $log, $m);
    }
    naar(terug(app_url('trainers/')));
}

// ---------- overzicht voor het bestuur ----------

/** Alle trainers in één maand: schema, bevestigingen en uren; met ?trainer= het detail om aan te passen. */
function v_tr_overzicht(): void
{
    tr_vereist_beheer();
    $maand = tr_maand_param();
    [$van, $tot] = tr_maand_grenzen($maand);
    $ik = get_current_user_id();
    $trainer = get('trainer') !== '' ? (int) get('trainer') : null;
    $hier = app_url('trainers/overzicht/', ['maand' => $maand] + ($trainer ? ['trainer' => $trainer] : []));
    if (is_post() && post('actie') === 'alles_ja') {
        $u = (int) post('user');
        $n = tr_alles_bevestigen($u, $van, $tot, $ik);
        if ($n) {
            tr_log($u, $ik, ($n === 1 ? '1 training' : "$n trainingen") . ' in ' . tr_maandnaam($maand) . ' als aanwezig bevestigd');
        }
        flash(tr_naam($u) . ': ' . ($n === 1 ? '1 training' : "$n trainingen") . ' bevestigd.' . ($n ? ' ' . tr_voornaam($u) . ' krijgt een melding.' : ''));
        naar($hier);
    }
    $overzicht = tr_maand($maand);
    $vast = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE van<=%s AND (tot IS NULL OR tot>=%s) ORDER BY van", $tot, $van)) as $r) {
        $vast[(int) $r->user_id][] = $r;
    }
    $trainers = tr_trainers();
    $rijen = [];
    foreach ($trainers as $uid => $u) {
        if (isset($overzicht[$uid]) || isset($vast[$uid]) || $uid === $trainer) {
            $rijen[$uid] = $overzicht[$uid] ?? tr_maand_leeg($u);
        }
    }
    $detail = null;
    if ($trainer !== null) {
        $u = get_userdata($trainer) ?: niet_gevonden();
        $trainingen = array_values(array_filter(tr_trainingen($van, $tot), fn($b) => isset($b->aanwezig[$trainer]) || isset($b->afwezig[$trainer])));
        $lopend = [];
        foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE user_id=%d AND (tot IS NULL OR tot>=%s)", $trainer, $van)) as $r) {
            $lopend[(int) $r->moment_id] = $r;
        }
        $detail = ['uid' => $trainer, 'user' => $u, 'trainingen' => $trainingen, 'momenten' => tr_momenten($van, '9999-12-31'), 'vast' => $lopend,
            'log' => tr_logboek($trainer, false, 30), 'r' => $rijen[$trainer] ?? tr_maand_leeg($u),'uitbetaald' => tr_vergrendeld($trainer, $van)];
    }
    $vorige = (new \DateTimeImmutable("$maand-01"))->modify('-1 month')->format('Y-m');
    $volgende = (new \DateTimeImmutable("$maand-01"))->modify('+1 month')->format('Y-m');
    toon('trainers/overzicht', ['maand' => $maand, 'van' => $van, 'rijen' => $rijen, 'vast' => $vast, 'detail' => $detail, 'hier' => $hier,
        'vorige' => $vorige, 'volgende' => $volgende], $detail ? tr_naam($detail['user']) : 'Overzicht trainers');
}

// ---------- beheer (sportsecretariaat) ----------

function tr_lees_moment(): array
{
    $v = ['naam' => mb_substr(post('naam'), 0, 100), 'weekdag' => (int) post('weekdag'), 'start' => post('start'), 'einde' => post('einde'),
        'locatie' => mb_substr(post('locatie'), 0, 100), 'van' => post('van'), 'tot' => post('tot') ?: null];
    $f = [];
    if ($v['naam'] === '') {
        $f['naam'] = 'Dit veld is verplicht.';
    }
    if (!isset(TR_DAGEN[$v['weekdag']])) {
        $f['weekdag'] = 'Maak een geldige keuze.';
    }
    foreach (['start', 'einde'] as $k) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v[$k])) {
            $f[$k] = 'Geef een uur op, bv. 18:00.';
        }
    }
    if (!$f && $v['einde'] <= $v['start']) {
        $f['einde'] = 'Het einde moet na het begin liggen.';
    }
    if (!dag($v['van'])) {
        $f['van'] = 'Geef een geldige datum op.';
    }
    if ($v['tot'] !== null && (!dag($v['tot']) || $v['tot'] < $v['van'])) {
        $f['tot'] = 'Geef een geldige datum op, na de startdatum.';
    }
    return [$v, $f];
}

/** De aangevinkte ploegen van het formulier (enkel bestaande). */
function tr_lees_ploegen(): array
{
    $ids = array_map('intval', (array) ($_POST['ploegen'] ?? []));
    $bestaand = array_map('intval', db()->get_col("SELECT id FROM " . t('ploegen')));
    return array_values(array_intersect($ids, $bestaand));
}

/** Leest een afgelasting of verlof. 'voor' is 'alle', 'p<ploeg-id>' of 'm<moment-id>' (of het oude veld 'moment'). */
function tr_lees_afgelast(): array
{
    $voor = post('voor') !== '' ? post('voor') : ((int) post('moment') ? 'm' . (int) post('moment') : 'alle');
    $a = ['moment_id' => 0, 'ploeg_id' => 0, 'soort' => isset(TR_AFGELAST_SOORT[post('soort')]) ? post('soort') : 'ander',
        'van' => post('van'), 'tot' => post('tot') ?: post('van'), 'reden' => mb_substr(post('reden'), 0, 200),
        'door_id' => get_current_user_id(), 'aangemaakt' => nu_sql()];
    if (preg_match('/^p(\d+)$/', $voor, $r) && ploeg((int) $r[1])) {
        $a['ploeg_id'] = (int) $r[1];
    } elseif (preg_match('/^m(\d+)$/', $voor, $r) && tr_moment((int) $r[1])) {
        $a['moment_id'] = (int) $r[1];
    } elseif ($voor !== 'alle') {
        return [$a, 'Kies voor welke ploeg of training.'];
    }
    if (!dag($a['van']) || !dag($a['tot']) || $a['tot'] < $a['van']) {
        return [$a, 'Geef een geldige periode op.'];
    }
    return [$a, null];
}

/** Flash na het toevoegen of verwijderen van een afgelasting: hoeveel trainingen er wegvallen of toch doorgaan. */
function tr_flash_afgelast(string $begin, array $aantal): void
{
    [$af, $terug] = $aantal;
    $delen = [];
    if ($af) {
        $delen[] = ($af === 1 ? '1 komende training valt' : "$af komende trainingen vallen") . ' weg';
    }
    if ($terug) {
        $delen[] = ($terug === 1 ? '1 komende training gaat' : "$terug komende trainingen gaan") . ' toch door';
    }
    flash($begin . ($delen ? ' ' . ucfirst(implode(', ', $delen)) . '; de trainers krijgen een melding.' : ''));
}

/** Trainingen, seizoenen en verlof beheren: bestuur en sportsecretariaat. */
function v_tr_beheer(): void
{
    tr_vereist_beheer();
    $v = ['naam' => '', 'weekdag' => 1, 'start' => '', 'einde' => '', 'locatie' => '', 'van' => vandaag()->format('Y-m-d'), 'tot' => '', 'ploegen' => []];
    $f = [];
    $hier = app_url('trainers/beheer/');
    if (is_post()) {
        $wat = post('wat');
        if ($wat === 'moment') {
            [$v, $f] = tr_lees_moment();
            $ploegen = tr_lees_ploegen();
            if (!$f) {
                db()->insert(t('tr_momenten'), $v + ['aangemaakt' => nu_sql()]);
                $id = (int) db()->insert_id;
                tr_bewaar_moment_ploegen($id, $ploegen);
                flash("Training ‘{$v['naam']}’ toegevoegd. Duid nu de vaste trainers aan.");
                naar(app_url("trainers/moment/$id/"));
            }
            $v['ploegen'] = $ploegen;
        } elseif ($wat === 'afgelast') {
            [$a, $fout] = tr_lees_afgelast();
            if ($fout) {
                flash($fout, 'error');
            } else {
                $voor = tr_afgelast_voor((object) $a);
                $aantal = tr_wijzig_afgelast(fn() => db()->insert(t('tr_afgelast'), $a), $a['van'], $a['tot'],
                    ucfirst(tr_afgelast_tekst((object) $a)) . ($a['moment_id'] ? '' : " ($voor)"));
                tr_flash_afgelast(ucfirst(TR_AFGELAST_SOORT[$a['soort']]) . ": $voor van " . datum($a['van'], 'j/m/Y') . ' tot ' . datum($a['tot'], 'j/m/Y') . '.', $aantal);
            }
            naar($hier);
        } elseif ($wat === 'afgelast_weg') {
            $a = rij('tr_afgelast', (int) post('id'));
            if ($a) {
                $aantal = tr_wijzig_afgelast(fn() => db()->delete(t('tr_afgelast'), ['id' => $a->id]), $a->van, $a->tot, '');
                tr_flash_afgelast('Verwijderd: ' . tr_afgelast_voor($a) . ' (' . tr_afgelast_tekst($a) . ').', $aantal);
            }
            naar($hier);
        } elseif ($wat === 'seizoen') {
            $s = ['naam' => mb_substr(post('naam'), 0, 60), 'van' => post('van'), 'tot' => post('tot')];
            if (!dag($s['van']) || !dag($s['tot']) || $s['tot'] <= $s['van']) {
                flash('Geef een geldig begin en einde van het seizoen op.', 'error');
            } elseif (db()->get_var(db()->prepare("SELECT id FROM " . t('tr_seizoenen') . " WHERE van<=%s AND tot>=%s", $s['tot'], $s['van']))) {
                flash('Dit seizoen overlapt met een ander seizoen.', 'error');
            } else {
                $s['naam'] = $s['naam'] !== '' ? $s['naam'] : 'Seizoen ' . substr($s['van'], 0, 4) . '–' . substr($s['tot'], 0, 4);
                db()->insert(t('tr_seizoenen'), $s);
                flash("{$s['naam']}: van " . datum($s['van'], 'j/m/Y') . ' tot ' . datum($s['tot'], 'j/m/Y') . '. Buiten een seizoen zijn er geen trainingen.');
            }
            naar($hier);
        } elseif ($wat === 'seizoen_weg') {
            db()->delete(t('tr_seizoenen'), ['id' => (int) post('id')]);
            flash('Seizoen verwijderd.');
            naar($hier);
        }
    }
    $momenten = tr_momenten();
    $vast = [];
    $vandaag = vandaag()->format('Y-m-d');
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE tot IS NULL OR tot>=%s", $vandaag)) as $r) {
        $vast[(int) $r->moment_id][] = (int) $r->user_id;
    }
    // Voorbije afgelastingen (ouder dan een maand) enkel op vraag.
    $alles = get('alles') === '1';
    $afgelast = db()->get_results("SELECT * FROM " . t('tr_afgelast') . ($alles ? '' : db()->prepare(" WHERE tot>=%s", vandaag()->modify('-1 month')->format('Y-m-d')))
        . " ORDER BY van DESC, id DESC");
    $seizoenen = tr_seizoenen();
    $laatste = $seizoenen ? end($seizoenen) : null;
    toon('trainers/beheer', ['momenten' => $momenten, 'vast' => $vast, 'v' => $v, 'f' => $f, 'afgelast' => $afgelast, 'alles' => $alles,
        'seizoenen' => $seizoenen, 'nu_seizoen' => tr_in_seizoen($vandaag), 'laatste' => $laatste, 'ploegen' => tr_alle_ploegen(),
        'moment_ploegen' => tr_moment_ploegen()], 'Trainingen beheren');
}

function v_tr_moment($id): void
{
    tr_vereist_beheer();
    $m = tr_moment((int) $id) ?: niet_gevonden();
    $v = (array) $m;
    $v['start'] = substr($m->start, 0, 5);
    $v['einde'] = substr($m->einde, 0, 5);
    $v['ploegen'] = tr_moment_ploegen()[(int) $m->id] ?? [];
    $f = [];
    if (is_post()) {
        if (post('wat') === 'verwijderen') {
            $heeft = (int) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('tr_wijzigingen') . " WHERE moment_id=%d", $m->id))
                + (int) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('tr_vast') . " WHERE moment_id=%d AND van<%s", $m->id, vandaag()->format('Y-m-d')));
            if ($heeft) {
                // Er zijn al uren op gepresteerd: niet wissen, maar stoppen (de geschiedenis blijft voor de verloning).
                $gisteren = vandaag()->modify('-1 day')->format('Y-m-d');
                db()->update(t('tr_momenten'), ['tot' => max($gisteren, $m->van)], ['id' => $m->id]);
                db()->query(db()->prepare("UPDATE " . t('tr_vast') . " SET tot=%s WHERE moment_id=%d AND (tot IS NULL OR tot>%s)", $gisteren, $m->id, $gisteren));
                flash("‘{$m->naam}’ is gestopt vanaf vandaag. Voorbije trainingen blijven meetellen voor de verloning.");
            } else {
                db()->delete(t('tr_vast'), ['moment_id' => $m->id]);
                db()->delete(t('tr_moment_ploegen'), ['moment_id' => $m->id]);
                db()->delete(t('tr_afgelast'), ['moment_id' => $m->id]);
                db()->delete(t('tr_momenten'), ['id' => $m->id]);
                flash("‘{$m->naam}’ verwijderd.");
            }
            naar(app_url('trainers/beheer/'));
        }
        [$v, $f] = tr_lees_moment();
        $ploegen = tr_lees_ploegen();
        if (!$f) {
            db()->update(t('tr_momenten'), $v, ['id' => $m->id]);
            tr_bewaar_moment_ploegen((int) $m->id, $ploegen);
            flash('Bewaard.');
            naar(app_url("trainers/moment/{$m->id}/"));
        }
    }
    $vast = db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE moment_id=%d ORDER BY (tot IS NULL) DESC, van DESC", $m->id));
    $v['ploegen'] = $ploegen ?? $v['ploegen'];
    toon('trainers/moment', ['m' => $m, 'v' => $v, 'f' => $f, 'vast' => $vast, 'trainers' => tr_trainers(), 'ploegen' => tr_alle_ploegen()], $m->naam);
}

// ---------- uren & verloning ----------

function tr_maand_param(): string
{
    $m = get('maand');
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : vandaag()->format('Y-m');
}

function v_tr_uren(): void
{
    vereist_login();
    $pm = is_penningmeester();
    if (!$pm && !tr_is_trainer()) {
        geen_toegang();
    }
    $maand = tr_maand_param();
    $ik = get_current_user_id();
    // Trainers (niet-penningmeester) zien enkel hun eigen uren.
    $detail = $pm ? (get('trainer') !== '' ? (int) get('trainer') : null) : $ik;
    if (is_post()) {
        tr_vereist_penningmeester();
        $u = (int) post('user');
        if (post('actie') === 'uitbetaald') {
            $r = tr_maand($maand, $u)[$u] ?? null;
            if ($r) {
                db()->query(db()->prepare("REPLACE INTO " . t('tr_uitbetaald') . " (user_id, maand, trainingen, minuten, bedrag_cent, door_id, op) VALUES (%d, %s, %d, %d, %d, %d, %s)",
                    $u, $maand, $r['trainingen'], $r['minuten'], $r['bedrag_cent'], $ik, nu_sql()));
                flash(tr_naam($u) . ': ' . tr_maandnaam($maand) . ' gemarkeerd als uitbetaald (' . euro($r['bedrag_cent']) . ').');
            }
        } elseif (post('actie') === 'niet_uitbetaald') {
            db()->delete(t('tr_uitbetaald'), ['user_id' => $u, 'maand' => $maand]);
            flash(tr_naam($u) . ': markering ‘uitbetaald’ verwijderd.');
        }
        naar(app_url('trainers/uren/', ['maand' => $maand]));
    }
    $overzicht = tr_maand($maand, $pm ? null : $ik);
    if (!$pm && !isset($overzicht[$ik])) {
        $overzicht[$ik] = tr_maand_leeg(wp_get_current_user());
    }
    $vorige = (new \DateTimeImmutable("$maand-01"))->modify('-1 month')->format('Y-m');
    $volgende = (new \DateTimeImmutable("$maand-01"))->modify('+1 month')->format('Y-m');
    toon('trainers/uren', ['maand' => $maand, 'overzicht' => $overzicht, 'uitbetaald' => tr_uitbetaald($maand), 'pm' => $pm, 'detail' => $detail,
        'vorige' => $vorige, 'volgende' => $volgende], 'Uren & verloning');
}

function v_tr_export(): void
{
    tr_vereist_penningmeester();
    $maand = tr_maand_param();
    $overzicht = tr_maand($maand);
    $uitbetaald = tr_uitbetaald($maand);
    // Enkel bevestigde trainingen tellen; niet bevestigde staan apart ter info.
    $rijen = [['Trainer', 'E-mail', 'Trainingen', 'Uren', 'Bedrag (€)', 'Zonder tarief', 'Niet bevestigd', 'Uitbetaald op']];
    foreach ($overzicht as $uid => $r) {
        $rijen[] = [tr_naam($r['user']), $r['user'] ? $r['user']->user_email : '', $r['trainingen'], round($r['minuten'] / 60, 2),
            $r['bedrag_cent'] / 100, $r['zonder_tarief'], $r['open'], isset($uitbetaald[$uid]) ? datum($uitbetaald[$uid]->op, 'j/m/Y') : ''];
    }
    $rijen[] = ['Totaal', '', array_sum(array_column($overzicht, 'trainingen')), round(array_sum(array_column($overzicht, 'minuten')) / 60, 2),
        array_sum(array_column($overzicht, 'bedrag_cent')) / 100, '', array_sum(array_column($overzicht, 'open')), ''];
    $detail = [['Trainer', 'Datum', 'Training', 'Uur', 'Rol', 'Vervangt', 'Uren', 'Tarief', 'Bedrag (€)']];
    foreach ($overzicht as $r) {
        foreach ($r['regels'] as $g) {
            $detail[] = [tr_naam($r['user']), new \DateTimeImmutable($g['datum'], new \DateTimeZone('UTC')), $g['moment']->naam, tr_tijd($g['moment']),
                TR_SOORT[$g['soort']], $g['vervangt'] ? tr_naam($g['vervangt']) : '', round($g['minuten'] / 60, 2),
                $g['tarief'] ? euro($g['tarief']->bedrag_cent) . ' ' . TR_TARIEF[$g['tarief']->soort] : 'geen tarief', $g['bedrag_cent'] / 100];
        }
    }
    download(maak_xlsx([['naam' => 'Per trainer', 'rijen' => $rijen], ['naam' => 'Detail', 'rijen' => $detail]]),
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "verloning_trainers_$maand.xlsx");
}

function v_tr_tarieven(): void
{
    tr_vereist_penningmeester();
    $fout = [];
    $v = ['user' => get('trainer'), 'soort' => 'uur', 'bedrag' => '', 'geldig_vanaf' => vandaag()->format('Y-m-01'), 'opmerking' => ''];
    if (is_post()) {
        if (post('actie') === 'verwijder') {
            db()->delete(t('tr_tarieven'), ['id' => (int) post('id')]);
            flash('Tarief verwijderd.');
            naar(app_url('trainers/tarieven/'));
        }
        $v = ['user' => post('user'), 'soort' => post('soort'), 'bedrag' => post('bedrag'), 'geldig_vanaf' => post('geldig_vanaf'), 'opmerking' => mb_substr(post('opmerking'), 0, 200)];
        $bedrag = str_replace([',', '€', ' '], ['.', '', ''], $v['bedrag']);
        if (!get_userdata((int) $v['user'])) {
            $fout['user'] = 'Kies een trainer.';
        }
        if (!isset(TR_TARIEF[$v['soort']])) {
            $fout['soort'] = 'Maak een geldige keuze.';
        }
        if (!is_numeric($bedrag) || (float) $bedrag < 0) {
            $fout['bedrag'] = 'Geef een bedrag op, bv. 15,50.';
        }
        if (!dag($v['geldig_vanaf'])) {
            $fout['geldig_vanaf'] = 'Geef een geldige datum op.';
        }
        if (!$fout) {
            db()->insert(t('tr_tarieven'), ['user_id' => (int) $v['user'], 'soort' => $v['soort'], 'bedrag_cent' => (int) round((float) $bedrag * 100),
                'geldig_vanaf' => $v['geldig_vanaf'], 'opmerking' => $v['opmerking'], 'aangemaakt' => nu_sql()]);
            flash(tr_naam((int) $v['user']) . ': tarief ' . euro((int) round((float) $bedrag * 100)) . ' ' . TR_TARIEF[$v['soort']] . ' vanaf ' . datum($v['geldig_vanaf'], 'j/m/Y') . '.');
            naar(app_url('trainers/tarieven/'));
        }
    }
    $trainers = tr_trainers();
    $tarieven = [];
    foreach ($trainers as $uid => $u) {
        $tarieven[$uid] = tr_tarieven($uid);
    }
    toon('trainers/tarieven', ['trainers' => $trainers, 'tarieven' => $tarieven, 'v' => $v, 'fout' => $fout,
        'vandaag' => vandaag()->format('Y-m-d')], 'Tarieven trainers');
}
