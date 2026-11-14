<?php
/** Module trainers: pagina's en acties. */

namespace KHZS;

defined('ABSPATH') || exit;

function tr_vereist_trainer(): void
{
    vereist_login();
    if (!is_staf()) {
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

function tr_training_url(object $m, string $datum): string
{
    return app_url("trainers/training/{$m->id}/$datum/");
}

function tr_moment_tekst(object $m, string $datum): string
{
    return "{$m->naam} " . datum($datum, 'D j/m') . ' ' . substr($m->start, 0, 5);
}

// ---------- mijn trainingen ----------

function v_tr_mijn(): void
{
    tr_vereist_trainer();
    $uid = get_current_user_id();
    $van = vandaag()->format('Y-m-d');
    $tot = vandaag()->modify('+27 days')->format('Y-m-d');
    $mijn = $open = [];
    foreach (tr_trainingen($van, $tot) as $b) {
        if ($b->afgelast) {
            if (isset($b->aanwezig[$uid]) || isset($b->afwezig[$uid])) {
                $mijn[] = $b;
            }
            continue;
        }
        if (isset($b->aanwezig[$uid]) || isset($b->afwezig[$uid])) {
            $mijn[] = $b;
        }
        if ($b->open && !isset($b->aanwezig[$uid]) && !in_array($uid, $b->open, true)) {
            $open[] = $b;
        }
    }
    $momenten = tr_momenten($van, '9999-12-31');
    $vast = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE user_id=%d AND (tot IS NULL OR tot>=%s)", $uid, $van)) as $v) {
        $vast[(int) $v->moment_id] = $v;
    }
    $maand = tr_maand(vandaag()->format('Y-m'), $uid)[$uid] ?? null;
    toon('trainers/mijn', ['uid' => $uid, 'mijn' => $mijn, 'open' => $open, 'momenten' => $momenten, 'vast' => $vast, 'maand' => $maand],
        'Mijn trainingen');
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
    toon('trainers/planning', ['maandag' => $maandag, 'per_dag' => $per_dag, 'uid' => get_current_user_id()], 'Planning trainers');
}

function v_tr_training($id, $datum): void
{
    tr_vereist_trainer();
    $m = tr_moment((int) $id) ?: niet_gevonden();
    if (!tr_geldig($m, $datum)) {
        niet_gevonden();
    }
    $b = tr_bezetting($m, $datum, tr_context($datum, $datum));
    $wijz = db()->get_results(db()->prepare("SELECT * FROM " . t('tr_wijzigingen') . " WHERE moment_id=%d AND datum=%s ORDER BY aangemaakt", $m->id, $datum));
    toon('trainers/training', ['b' => $b, 'm' => $m, 'datum' => $datum, 'uid' => get_current_user_id(), 'wijz' => $wijz,
        'trainers' => tr_trainers(), 'mag' => $datum >= vandaag()->format('Y-m-d') || is_secretariaat()], tr_moment_tekst($m, $datum));
}

/** Eén actie op één training: afwezig, aanwezig, overnemen, helpen of schrappen. */
function v_tr_actie(): void
{
    tr_vereist_trainer();
    vereist_post();
    $m = tr_moment((int) post('moment')) ?: niet_gevonden();
    $datum = post('datum');
    $actie = post('actie');
    $ik = get_current_user_id();
    $sec = is_secretariaat();
    $doel = post('user') !== '' ? (int) post('user') : $ik;
    $terug = terug(tr_training_url($m, $datum));
    if (!tr_geldig($m, $datum)) {
        niet_gevonden();
    }
    if ($doel !== $ik && !$sec) {
        geen_toegang('Enkel het sportsecretariaat kan de planning van anderen aanpassen.');
    }
    if ($datum < vandaag()->format('Y-m-d') && !$sec) {
        flash('Deze training is al voorbij. Vraag het sportsecretariaat om een correctie.', 'error');
        naar($terug);
    }
    $b = tr_bezetting($m, $datum, tr_context($datum, $datum));
    if ($b->afgelast && in_array($actie, ['overnemen', 'helpen'], true)) {
        flash("Deze training is afgelast ({$b->afgelast}).", 'error');
        naar($terug);
    }
    $wis = fn() => db()->delete(t('tr_wijzigingen'), ['moment_id' => $m->id, 'datum' => $datum, 'user_id' => $doel]);
    $voeg = fn(string $soort, ?int $vervangt = null, string $reden = '') => db()->insert(t('tr_wijzigingen'), [
        'moment_id' => $m->id, 'datum' => $datum, 'user_id' => $doel, 'soort' => $soort, 'vervangt_id' => $vervangt,
        'reden' => mb_substr($reden, 0, 200), 'door_id' => $ik, 'aangemaakt' => nu_sql()]);
    $wie = $doel === $ik ? 'Je' : tr_voornaam($doel);
    $tekst = tr_moment_tekst($m, $datum);

    switch ($actie) {
        case 'afwezig':
            if (($b->aanwezig[$doel][0] ?? '') !== 'vast') {
                flash('Enkel een vaste trainer van deze training kan zich afmelden. Neem je een training over? Gebruik dan ‘Toch niet’.', 'error');
                break;
            }
            $wis();
            $voeg('afwezig', null, post('reden'));
            flash(($doel === $ik ? 'Je bent' : tr_voornaam($doel) . ' is') . " afgemeld voor $tekst. Collega's krijgen een melding.");
            $ontvangers = array_values(array_diff(users_met('push_vervanging', STAF), [$doel]));
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
            flash("$wie neemt $tekst over van " . tr_voornaam($vervangt) . '.');
            if (voorkeur($vervangt, 'push_vervanging')) {
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
            flash("$wie helpt mee op $tekst.");
            break;
        case 'schrappen':
            $soort = $b->aanwezig[$doel][0] ?? '';
            if (!in_array($soort, ['vervanger', 'extra'], true)) {
                break;
            }
            $vervangt = $b->aanwezig[$doel][1];
            $wis();
            flash("$wie staat niet meer op $tekst.");
            if ($soort === 'vervanger' && $vervangt) {
                $ontvangers = array_values(array_diff(users_met('push_vervanging', STAF), [$doel]));
                stuur($ontvangers, 'Opnieuw vervanger gezocht', "$tekst – " . tr_voornaam($vervangt) . ' kan niet', tr_training_url($m, $datum));
            }
            break;
        case 'toevoegen':
            // Sportsecretariaat: een trainer toevoegen (extra of als vervanger van een afwezige).
            if (!$sec) {
                geen_toegang();
            }
            if (isset($b->aanwezig[$doel])) {
                flash(tr_voornaam($doel) . ' staat al op deze training.', 'error');
                break;
            }
            $vervangt = (int) post('vervangt');
            $wis();
            $voeg($vervangt && in_array($vervangt, $b->open, true) ? 'vervanger' : 'extra', $vervangt && in_array($vervangt, $b->open, true) ? $vervangt : null);
            flash(tr_voornaam($doel) . " staat nu op $tekst.");
            break;
    }
    naar($terug);
}

/** Vaste training aan- of uitzetten (zelf, of door het sportsecretariaat voor iemand anders). */
function v_tr_vast(): void
{
    tr_vereist_trainer();
    vereist_post();
    $m = tr_moment((int) post('moment')) ?: niet_gevonden();
    $ik = get_current_user_id();
    $doel = post('user') !== '' ? (int) post('user') : $ik;
    if ($doel !== $ik && !is_secretariaat()) {
        geen_toegang();
    }
    $vanaf = dag(post('vanaf')) ?? vandaag();
    if ($vanaf < vandaag() && !is_secretariaat()) {
        $vanaf = vandaag();
    }
    $v = $vanaf->format('Y-m-d');
    $gisteren = $vanaf->modify('-1 day')->format('Y-m-d');
    $lopend = db()->get_row(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE moment_id=%d AND user_id=%d AND (tot IS NULL OR tot>=%s) ORDER BY van DESC LIMIT 1",
        $m->id, $doel, $v));
    $wie = $doel === $ik ? 'Je' : tr_voornaam($doel);
    if (post('aan') === '1') {
        if ($lopend) {
            flash("$wie geeft {$m->naam} al.", 'info');
        } else {
            db()->insert(t('tr_vast'), ['moment_id' => $m->id, 'user_id' => $doel, 'van' => max($v, $m->van), 'tot' => $m->tot]);
            flash("$wie geeft voortaan {$m->naam} (" . TR_DAGEN[$m->weekdag] . ' ' . tr_tijd($m) . ') vanaf ' . datum($v, 'j/m/Y') . '.');
        }
    } elseif ($lopend) {
        if ($lopend->van >= $v) {
            db()->delete(t('tr_vast'), ['id' => $lopend->id]);
        } else {
            db()->update(t('tr_vast'), ['tot' => $gisteren], ['id' => $lopend->id]);
        }
        // Afmeldingen na de stopdatum zijn niet meer nodig.
        db()->query(db()->prepare("DELETE FROM " . t('tr_wijzigingen') . " WHERE moment_id=%d AND user_id=%d AND soort='afwezig' AND datum>=%s", $m->id, $doel, $v));
        flash("$wie geeft {$m->naam} niet meer vanaf " . datum($v, 'j/m/Y') . '.');
    }
    naar(terug(app_url('trainers/')));
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

function v_tr_beheer(): void
{
    vereist_rol(SECRETARIAAT);
    $v = ['naam' => '', 'weekdag' => 1, 'start' => '', 'einde' => '', 'locatie' => '', 'van' => vandaag()->format('Y-m-d'), 'tot' => ''];
    $f = $af = [];
    if (is_post()) {
        if (post('wat') === 'moment') {
            [$v, $f] = tr_lees_moment();
            if (!$f) {
                db()->insert(t('tr_momenten'), $v + ['aangemaakt' => nu_sql()]);
                flash("Training ‘{$v['naam']}’ toegevoegd. Duid nu de vaste trainers aan.");
                naar(app_url('trainers/moment/' . db()->insert_id . '/'));
            }
        } elseif (post('wat') === 'afgelast') {
            $a = ['moment_id' => (int) post('moment'), 'van' => post('van'), 'tot' => post('tot') ?: post('van'), 'reden' => mb_substr(post('reden'), 0, 200)];
            if (!dag($a['van']) || !dag($a['tot']) || $a['tot'] < $a['van']) {
                flash('Geef een geldige periode op.', 'error');
            } else {
                db()->insert(t('tr_afgelast'), $a);
                flash('Afgelast: ' . ($a['moment_id'] ? tr_moment($a['moment_id'])->naam : 'alle trainingen') . ' van ' . datum($a['van'], 'j/m/Y') . ' tot ' . datum($a['tot'], 'j/m/Y') . '.');
            }
            naar(app_url('trainers/beheer/'));
        } elseif (post('wat') === 'afgelast_weg') {
            db()->delete(t('tr_afgelast'), ['id' => (int) post('id')]);
            flash('Afgelasting verwijderd.');
            naar(app_url('trainers/beheer/'));
        }
    }
    $momenten = tr_momenten();
    $vast = [];
    $vandaag = vandaag()->format('Y-m-d');
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE tot IS NULL OR tot>=%s", $vandaag)) as $r) {
        $vast[(int) $r->moment_id][] = (int) $r->user_id;
    }
    $afgelast = db()->get_results("SELECT * FROM " . t('tr_afgelast') . " ORDER BY van DESC");
    toon('trainers/beheer', ['momenten' => $momenten, 'vast' => $vast, 'v' => $v, 'f' => $f, 'afgelast' => $afgelast], 'Trainingen beheren');
}

function v_tr_moment($id): void
{
    vereist_rol(SECRETARIAAT);
    $m = tr_moment((int) $id) ?: niet_gevonden();
    $v = (array) $m;
    $v['start'] = substr($m->start, 0, 5);
    $v['einde'] = substr($m->einde, 0, 5);
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
                db()->delete(t('tr_momenten'), ['id' => $m->id]);
                flash("‘{$m->naam}’ verwijderd.");
            }
            naar(app_url('trainers/beheer/'));
        }
        [$v, $f] = tr_lees_moment();
        if (!$f) {
            db()->update(t('tr_momenten'), $v, ['id' => $m->id]);
            flash('Bewaard.');
            naar(app_url("trainers/moment/{$m->id}/"));
        }
    }
    $vast = db()->get_results(db()->prepare("SELECT * FROM " . t('tr_vast') . " WHERE moment_id=%d ORDER BY (tot IS NULL) DESC, van DESC", $m->id));
    toon('trainers/moment', ['m' => $m, 'v' => $v, 'f' => $f, 'vast' => $vast, 'trainers' => tr_trainers()], $m->naam);
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
    if (!$pm && !is_staf()) {
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
        $overzicht[$ik] = ['user' => wp_get_current_user(), 'trainingen' => 0, 'minuten' => 0, 'bedrag_cent' => 0, 'zonder_tarief' => 0, 'gepland' => 0, 'regels' => []];
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
    $rijen = [['Trainer', 'E-mail', 'Trainingen', 'Uren', 'Bedrag (€)', 'Zonder tarief', 'Uitbetaald op']];
    foreach ($overzicht as $uid => $r) {
        $rijen[] = [tr_naam($r['user']), $r['user'] ? $r['user']->user_email : '', $r['trainingen'], round($r['minuten'] / 60, 2),
            $r['bedrag_cent'] / 100, $r['zonder_tarief'], isset($uitbetaald[$uid]) ? datum($uitbetaald[$uid]->op, 'j/m/Y') : ''];
    }
    $rijen[] = ['Totaal', '', array_sum(array_column($overzicht, 'trainingen')), round(array_sum(array_column($overzicht, 'minuten')) / 60, 2),
        array_sum(array_column($overzicht, 'bedrag_cent')) / 100, '', ''];
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
