<?php
/** Module communicatie: pagina's. Alles in de app zelf (ook op de gsm), niet in wp-admin. */

namespace KHZS;

defined('ABSPATH') || exit;

function com_kanaal_of_404($id): object
{
    com_sync();
    $k = com_kanaal((int) $id) ?: niet_gevonden();
    if (!com_ziet($k, get_current_user_id())) {
        geen_toegang('Je ziet dit kanaal niet.');
    }
    return $k;
}

/** Verzoek via fetch (berichten sturen zonder de pagina te herladen): antwoord in JSON. */
function com_ajax(): bool
{
    return ($_SERVER['HTTP_X_KHZS_AJAX'] ?? '') === '1';
}

// ---------- overzicht ----------

function v_com_overzicht(): void
{
    vereist_login();
    com_sync();
    $uid = get_current_user_id();
    $zichtbaar = com_zichtbaar($uid);
    $ongelezen = com_ongelezen($uid);
    $laatste = array_filter(array_map(fn($k) => (int) $k->laatste_id, $zichtbaar));
    $berichten = [];
    if ($laatste) {
        foreach (db()->get_results("SELECT * FROM " . t('com_berichten') . " WHERE id IN (" . in_lijst($laatste) . ")") as $b) {
            $berichten[(int) $b->id] = $b;
        }
    }
    // Per sectie de kanalen; threads onder hun kanaal, of los als het kanaal zelf niet zichtbaar is (bv. een wedstrijd).
    $secties = [];
    foreach (com_secties() as $s) {
        $secties[(int) $s->id] = (object) ['sectie' => $s, 'kanalen' => []];
    }
    foreach ($zichtbaar as $k) {
        $k->ongelezen = $ongelezen[(int) $k->id] ?? 0;
        $k->volgt = com_meldingen_aan($k, $uid);
        $k->laatste = $berichten[(int) $k->laatste_id] ?? null;
        $k->threads = [];
    }
    foreach ($zichtbaar as $k) {
        if ((int) $k->ouder_id && isset($zichtbaar[(int) $k->ouder_id])) {
            if (!(int) $k->gearchiveerd) {
                $zichtbaar[(int) $k->ouder_id]->threads[] = $k;
            }
        } elseif (isset($secties[(int) $k->sectie_id]) && (!(int) $k->gearchiveerd || $k->ongelezen)) {
            $secties[(int) $k->sectie_id]->kanalen[] = $k;
        }
    }
    foreach ($zichtbaar as $k) {
        usort($k->threads, fn($a, $b) => strcmp((string) $b->laatste_op, (string) $a->laatste_op) ?: (int) $b->volgorde <=> (int) $a->volgorde);
    }
    $aankondigingen = com_actieve_aankondigingen($uid);
    if ($aankondigingen) {
        update_user_meta($uid, 'khzs_com_aank_gezien', max(array_map(fn($a) => (int) $a->id, $aankondigingen)));
    }
    toon('communicatie/overzicht', ['secties' => array_filter($secties, fn($s) => $s->kanalen), 'aankondigingen' => $aankondigingen,
        'bestuur' => com_is_bestuur(), 'mag_aankondigen' => (bool) com_aankondiging_kanalen($uid)], 'Berichten');
}

// ---------- kanaal (chat) ----------

/** Berichten van een kanaal, oudste eerst. $voor: enkel berichten met een kleiner id (oudere laden). */
function com_berichten_van(int $kanaal_id, int $voor = 0, int $na = 0): array
{
    $waar = db()->prepare("kanaal_id=%d", $kanaal_id) . ($voor ? db()->prepare(" AND id<%d", $voor) : '') . ($na ? db()->prepare(" AND id>%d", $na) : '');
    $lijst = db()->get_results("SELECT * FROM " . t('com_berichten') . " WHERE $waar ORDER BY id DESC LIMIT " . ($na ? 200 : COM_PER_PAGINA + 1));
    return array_reverse($lijst);
}

/** HTML van een reeks berichten (zelfde sjabloon voor de pagina en voor het vernieuwen). */
function com_berichten_html(object $k, array $lijst, ?object $vorige = null): string
{
    $uid = get_current_user_id();
    $vermeld = com_vermeld_namen(array_map(fn($b) => (int) $b->id, $lijst));
    $modereren = com_mag_modereren($k, $uid);
    ob_start();
    foreach ($lijst as $b) {
        $_b = $b;
        $_vorige = $vorige;
        $_vermeld = $vermeld[(int) $b->id] ?? [];
        $_k = $k;
        $_uid = $uid;
        $_mod = $modereren;
        include sjabloon('communicatie/_bericht');
        $vorige = $b;
    }
    return (string) ob_get_clean();
}

function v_com_kanaal($id): void
{
    vereist_login();
    $k = com_kanaal_of_404($id);
    $uid = get_current_user_id();
    if (is_post()) {
        $actie = post('actie');
        $terug = app_url("berichten/kanaal/{$k->id}/");
        try {
            if ($actie === 'plaats') {
                $bijlage = null;
                if (!empty($_FILES['bijlage']['name'])) {
                    if (!com_mag_modereren($k, $uid)) {
                        throw new \RuntimeException('Enkel trainers en bestuur kunnen een foto of pdf toevoegen.');
                    }
                    $bijlage = com_bewaar_bijlage($_FILES['bijlage']);
                }
                $bid = com_plaats($k, $uid, post_ruw('tekst') ?? '', $bijlage);
                if (com_ajax()) {
                    $na = (int) post('na');
                    $lijst = com_berichten_van((int) $k->id, 0, $na);
                    wp_send_json(['ok' => true, 'html' => com_berichten_html($k, $lijst, $na ? com_bericht($na) : null), 'laatste' => $bid]);
                }
                naar("$terug#b$bid");
            } elseif ($actie === 'thread' && com_mag_modereren($k, $uid) && !(int) $k->ouder_id) {
                $naam = mb_substr(post('naam'), 0, 80);
                if ($naam === '') {
                    throw new \RuntimeException('Geef de thread een naam.');
                }
                $tid = com_nieuw_kanaal(['sectie_id' => $k->sectie_id, 'ouder_id' => $k->id, 'naam' => $naam, 'toegang' => 'erven',
                    'leden_posten' => $k->leden_posten, 'door_id' => $uid, 'beschrijving' => mb_substr(post('beschrijving'), 0, 200)]);
                if (trim(post('tekst')) !== '') {
                    com_plaats(com_kanaal($tid), $uid, post_ruw('tekst'));
                }
                flash("Thread $naam aangemaakt.");
                naar(app_url("berichten/kanaal/$tid/"));
            } elseif ($actie === 'meldingen') {
                com_zet_status((int) $k->id, $uid, ['meldingen' => post('aan') === '1' ? 1 : 0]);
                flash(post('aan') === '1' ? 'Je krijgt meldingen van ' . com_volle_naam($k) . '.' : com_volle_naam($k) . ' is gedempt.');
                naar($terug);
            } else {
                geen_toegang();
            }
        } catch (\RuntimeException $e) {
            if (com_ajax()) {
                wp_send_json(['ok' => false, 'fout' => $e->getMessage()], 400);
            }
            flash($e->getMessage(), 'error');
            naar($terug);
        }
    }
    $voor = (int) get('voor');
    $lijst = com_berichten_van((int) $k->id, $voor);
    $meer = count($lijst) > COM_PER_PAGINA;
    if ($meer) {
        array_shift($lijst);
    }
    if (!$voor && (int) $k->laatste_id) {
        com_zet_status((int) $k->id, $uid, ['gelezen_tot' => (int) $k->laatste_id]);
    }
    $ouder = (int) $k->ouder_id ? com_kanaal((int) $k->ouder_id) : null;
    $threads = [];
    if (!$ouder) {
        foreach (com_kanalen() as $t) {
            if ((int) $t->ouder_id === (int) $k->id && com_ziet($t, $uid)) {
                $threads[] = $t;
            }
        }
        usort($threads, fn($a, $b) => strcmp((string) $b->laatste_op, (string) $a->laatste_op) ?: (int) $b->volgorde <=> (int) $a->volgorde);
    }
    $vastgepind = db()->get_results(db()->prepare("SELECT * FROM " . t('com_berichten') . " WHERE kanaal_id=%d AND vastgepind IS NOT NULL AND verwijderd IS NULL
        ORDER BY vastgepind DESC", $k->id));
    toon('communicatie/kanaal', ['k' => $k, 'ouder' => $ouder, 'sectie' => com_sectie((int) $k->sectie_id), 'berichten_html' => com_berichten_html($k, $lijst),
        'eerste' => $lijst[0]->id ?? 0, 'laatste' => $lijst ? end($lijst)->id : 0, 'meer' => $meer, 'voor' => $voor, 'threads' => $threads,
        'vastgepind' => $vastgepind, 'vastgepind_vermeld' => com_vermeld_namen(array_map(fn($b) => (int) $b->id, $vastgepind)),
        'aankondigingen' => com_actieve_aankondigingen($uid, (int) $k->id), 'mag_posten' => com_mag_posten($k, $uid),
        'modereren' => com_mag_modereren($k, $uid), 'beheren' => com_mag_beheren($k, $uid), 'meldingen' => com_meldingen_aan($k, $uid),
        'vermeldbaar' => com_mag_posten($k, $uid) ? array_values(com_vermeldbaar($k)) : []], com_volle_naam($k), 'com-main');
}

/** Nieuwe berichten sinds ?na= (vernieuwen zonder de pagina te herladen, elke paar seconden). */
function v_com_nieuw($id): void
{
    vereist_login();
    $k = com_kanaal_of_404($id);
    $na = (int) get('na');
    $lijst = com_berichten_van((int) $k->id, 0, $na);
    if ($lijst) {
        com_zet_status((int) $k->id, get_current_user_id(), ['gelezen_tot' => (int) end($lijst)->id]);
    }
    nocache_headers();
    wp_send_json(['html' => $lijst ? com_berichten_html($k, $lijst, $na ? com_bericht($na) : null) : '', 'laatste' => $lijst ? (int) end($lijst)->id : $na]);
}

/** Bewerken, verwijderen, vastpinnen en losmaken van één bericht. */
function v_com_bericht($id): void
{
    vereist_login();
    vereist_post();
    $b = com_bericht((int) $id) ?: niet_gevonden();
    $k = com_kanaal_of_404($b->kanaal_id);
    $uid = get_current_user_id();
    $eigen = (int) $b->user_id === $uid;
    $mod = com_mag_modereren($k, $uid);
    $actie = post('actie');
    if ($b->verwijderd) {
        flash('Dit bericht is al verwijderd.', 'error');
    } elseif ($actie === 'bewerken' && $eigen && !(int) $k->gearchiveerd) {
        $tekst = mb_substr(trim(post_ruw('tekst') ?? ''), 0, 4000);
        if ($tekst === '' && !$b->bijlage) {
            flash('Een bericht kan niet leeg zijn. Verwijder het als je het niet meer wil.', 'error');
        } else {
            db()->update(t('com_berichten'), ['tekst' => $tekst, 'bewerkt' => nu_sql()], ['id' => $b->id]);
            com_bewaar_vermeldingen((int) $b->id, array_values(array_diff(com_vermeldingen_in($tekst, $k), [$uid])));
            flash('Bericht aangepast.');
        }
    } elseif ($actie === 'verwijderen' && ($eigen || $mod)) {
        com_wis_bijlage($b->bijlage);
        db()->update(t('com_berichten'), ['tekst' => '', 'bijlage' => '', 'bijlage_naam' => '', 'bijlage_type' => '', 'vastgepind' => null,
            'verwijderd' => nu_sql(), 'verwijderd_door' => $uid], ['id' => $b->id]);
        db()->delete(t('com_vermeldingen'), ['bericht_id' => $b->id]);
        flash('Bericht verwijderd.');
    } elseif ($actie === 'vastpinnen' && $mod) {
        db()->update(t('com_berichten'), ['vastgepind' => nu_sql(), 'vastgepind_door' => $uid], ['id' => $b->id]);
        flash('Bericht bovenaan vastgepind.');
    } elseif ($actie === 'losmaken' && $mod) {
        db()->update(t('com_berichten'), ['vastgepind' => null, 'vastgepind_door' => null], ['id' => $b->id]);
        flash('Bericht losgemaakt.');
    } else {
        geen_toegang();
    }
    naar(app_url("berichten/kanaal/{$k->id}/") . "#b{$b->id}");
}

function v_com_bijlage($id): void
{
    vereist_login();
    $b = com_bericht((int) $id) ?: niet_gevonden();
    com_kanaal_of_404($b->kanaal_id);
    $pad = prive_map() . '/' . $b->bijlage;
    if (!$b->bijlage || str_contains($b->bijlage, '..') || !is_file($pad)) {
        niet_gevonden();
    }
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    header('Cache-Control: private, max-age=86400');
    download(file_get_contents($pad), $types[$b->bijlage_type] ?? 'application/octet-stream', $b->bijlage_naam, true);
}

// ---------- instellingen van een kanaal ----------

function v_com_kanaal_instellingen($id): void
{
    vereist_login();
    $k = com_kanaal_of_404($id);
    $uid = get_current_user_id();
    if (!com_mag_beheren($k, $uid)) {
        geen_toegang();
    }
    $bestuur = com_is_bestuur($uid);
    // Automatisch beheerd: de naam van een ploegkanaal volgt de ploeg, een wedstrijdthread volgt de wedstrijd.
    $auto = $k->functie === 'ploeg' || $k->toegang === 'wedstrijd';
    $f = [];
    $v = (array) $k;
    if (is_post()) {
        if (post('actie') === 'verwijderen') {
            if (!$bestuur || $auto || $k->functie === 'wedstrijden') {
                geen_toegang('Dit kanaal wordt automatisch beheerd. Archiveer het in plaats van het te verwijderen.');
            }
            com_verwijder_kanaal($k);
            flash("{$k->naam} verwijderd.");
            naar(app_url((int) $k->ouder_id ? "berichten/kanaal/{$k->ouder_id}/" : 'berichten/'));
        }
        $v = array_merge($v, ['beschrijving' => mb_substr(post('beschrijving'), 0, 200), 'leden_posten' => isset($_POST['leden_posten']) ? 1 : 0,
            'gearchiveerd' => isset($_POST['gearchiveerd']) ? 1 : 0]);
        if (!$auto) {
            $v['naam'] = mb_substr(post('naam'), 0, 80);
            if ($v['naam'] === '') {
                $f['naam'] = 'Dit veld is verplicht.';
            }
        }
        if ($bestuur && !(int) $k->ouder_id) {
            $v['volgorde'] = (int) post('volgorde');
            if (isset(com_secties()[(int) post('sectie_id')])) {
                $v['sectie_id'] = (int) post('sectie_id');
            }
            if (!$auto && $k->functie !== 'wedstrijden') {
                [$v['toegang'], $v['ploeg_id'], $fout] = com_lees_toegang();
                if ($fout) {
                    $f['toegang'] = $fout;
                }
            }
        } elseif ($bestuur && (int) $k->ouder_id && !$auto) {
            $v['toegang'] = post('toegang') === 'staf' ? 'staf' : 'erven';
        }
        if (!$f) {
            $velden = array_intersect_key($v, array_flip(['naam', 'beschrijving', 'leden_posten', 'gearchiveerd', 'volgorde', 'sectie_id', 'toegang', 'ploeg_id']));
            db()->update(t('com_kanalen'), $velden, ['id' => $k->id]);
            if (isset($velden['sectie_id'])) {
                db()->update(t('com_kanalen'), ['sectie_id' => $velden['sectie_id']], ['ouder_id' => $k->id]); // threads verhuizen mee
            }
            flash('Kanaal bewaard.');
            naar(app_url("berichten/kanaal/{$k->id}/"));
        }
    }
    toon('communicatie/instellingen', ['k' => $k, 'v' => $v, 'f' => $f, 'auto' => $auto, 'bestuur' => $bestuur,
        'ploegen' => actieve_ploegen()], 'Instellingen ' . com_volle_naam($k), 'smal');
}

/** Toegang en ploeg uit het formulier (beheer). Geeft [toegang, ploeg_id, fout]. */
function com_lees_toegang(): array
{
    $toegang = post('toegang');
    if (!isset(COM_TOEGANG[$toegang]) || in_array($toegang, ['wedstrijd', 'erven'], true)) {
        return ['iedereen', 0, 'Kies wie het kanaal ziet.'];
    }
    $ploeg = $toegang === 'ploeg' ? (int) post('ploeg_id') : 0;
    if ($toegang === 'ploeg' && !ploeg($ploeg)) {
        return [$toegang, 0, 'Kies een ploeg.'];
    }
    return [$toegang, $ploeg, null];
}

function com_verwijder_kanaal(object $k): void
{
    $ids = array_merge([(int) $k->id], array_map('intval', db()->get_col(db()->prepare("SELECT id FROM " . t('com_kanalen') . " WHERE ouder_id=%d", $k->id))));
    $lijst = in_lijst($ids);
    foreach (db()->get_col("SELECT bijlage FROM " . t('com_berichten') . " WHERE kanaal_id IN ($lijst) AND bijlage<>''") as $b) {
        com_wis_bijlage($b);
    }
    db()->query("DELETE FROM " . t('com_vermeldingen') . " WHERE bericht_id IN (SELECT id FROM " . t('com_berichten') . " WHERE kanaal_id IN ($lijst))");
    foreach (['com_berichten', 'com_status', 'com_wedstrijdzwemmers'] as $tab) {
        db()->query("DELETE FROM " . t($tab) . " WHERE kanaal_id IN ($lijst)");
    }
    // Aankondigingen verliezen dit kanaal; zonder kanalen over zijn ze voor iedereen (zoals vroeger).
    foreach (db()->get_results("SELECT id, kanalen FROM " . t('com_aankondigingen') . " WHERE kanalen<>''") as $a) {
        $over = array_diff(com_aank_kanalen($a), $ids);
        if (count($over) !== count(com_aank_kanalen($a))) {
            db()->update(t('com_aankondigingen'), ['kanalen' => implode(',', $over)], ['id' => $a->id]);
        }
    }
    db()->query("DELETE FROM " . t('com_kanalen') . " WHERE id IN ($lijst)");
    com_cache(true);
}

// ---------- beheer: secties en kanalen ----------

function v_com_beheer(): void
{
    vereist_login();
    if (!com_is_bestuur()) {
        geen_toegang();
    }
    com_sync();
    $f = [];
    $v = ['naam' => '', 'beschrijving' => '', 'toegang' => 'iedereen', 'ploeg_id' => 0, 'sectie_id' => 0, 'leden_posten' => 1];
    if (is_post()) {
        $actie = post('actie');
        if ($actie === 'sectie') {
            $naam = mb_substr(post('naam'), 0, 60);
            if ($naam === '') {
                flash('Geef de sectie een naam.', 'error');
            } else {
                com_nieuwe_sectie($naam, isset(COM_SECTIE_SOORT[post('soort')]) ? post('soort') : 'algemeen', count(com_secties()) + 1);
                flash("Sectie $naam aangemaakt.");
            }
            naar(app_url('berichten/beheer/'));
        } elseif ($actie === 'sectie_bewaar' && ($s = com_sectie((int) post('id')))) {
            $naam = mb_substr(post('naam'), 0, 60);
            db()->update(t('com_secties'), ['naam' => $naam !== '' ? $naam : $s->naam, 'soort' => isset(COM_SECTIE_SOORT[post('soort')]) ? post('soort') : $s->soort,
                'volgorde' => (int) post('volgorde')], ['id' => $s->id]);
            flash('Sectie bewaard.');
            naar(app_url('berichten/beheer/'));
        } elseif ($actie === 'sectie_verwijder' && ($s = com_sectie((int) post('id')))) {
            if (array_filter(com_kanalen(), fn($k) => (int) $k->sectie_id === (int) $s->id)) {
                flash('Verplaats of verwijder eerst de kanalen van deze sectie.', 'error');
            } else {
                db()->delete(t('com_secties'), ['id' => $s->id]);
                flash("Sectie {$s->naam} verwijderd.");
            }
            naar(app_url('berichten/beheer/'));
        } elseif ($actie === 'kanaal') {
            $v = ['naam' => mb_substr(post('naam'), 0, 80), 'beschrijving' => mb_substr(post('beschrijving'), 0, 200), 'sectie_id' => (int) post('sectie_id'),
                'leden_posten' => isset($_POST['leden_posten']) ? 1 : 0];
            [$v['toegang'], $v['ploeg_id'], $fout] = com_lees_toegang();
            if ($v['naam'] === '') {
                $f['naam'] = 'Dit veld is verplicht.';
            }
            if (!com_sectie($v['sectie_id'])) {
                $f['sectie_id'] = 'Kies een sectie.';
            }
            if ($fout) {
                $f['toegang'] = $fout;
            }
            if (!$f) {
                $id = com_nieuw_kanaal($v + ['door_id' => get_current_user_id(), 'volgorde' => 50]);
                flash("Kanaal {$v['naam']} aangemaakt.");
                naar(app_url("berichten/kanaal/$id/"));
            }
        } else {
            geen_toegang();
        }
    }
    $per_sectie = [];
    foreach (com_kanalen() as $k) {
        if (!(int) $k->ouder_id) {
            $per_sectie[(int) $k->sectie_id][] = $k;
        }
    }
    toon('communicatie/beheer', ['secties' => com_secties(), 'per_sectie' => $per_sectie, 'v' => $v, 'f' => $f, 'ploegen' => actieve_ploegen()],
        'Kanalen beheren');
}

// ---------- aankondigingen ----------

/** Leest een aankondiging uit het formulier. Geeft [rij, fouten]. */
function com_lees_aankondiging(int $uid, array $kanalen): array
{
    $v = ['soort' => post('soort'), 'titel' => mb_substr(post('titel'), 0, 120), 'tekst' => mb_substr(post_ruw('tekst') ?? '', 0, 5000),
        'kanalen' => '', 'wanneer' => mb_substr(post('wanneer'), 0, 80), 'nodig' => max(0, min(9999, (int) post('nodig'))),
        'tot' => post('tot') ?: null];
    $f = [];
    if (!isset(COM_AANK_SOORT[$v['soort']])) {
        $f['soort'] = 'Kies een soort.';
    }
    if ($v['titel'] === '') {
        $f['titel'] = 'Dit veld is verplicht.';
    }
    // Meerdere kanalen mogen; 'Iedereen' (0) gaat voor op de rest.
    $gekozen = array_values(array_unique(array_map('intval', (array) wp_unslash($_POST['kanalen'] ?? []))));
    if (!$gekozen || array_diff($gekozen, array_keys($kanalen))) {
        $f['kanalen'] = 'Kies voor wie de aankondiging is.';
    } elseif (!in_array(0, $gekozen, true)) {
        sort($gekozen);
        $v['kanalen'] = implode(',', $gekozen);
    }
    if ($v['tot'] !== null && !dag($v['tot'])) {
        $f['tot'] = 'Geef een geldige datum.';
    }
    if ($v['soort'] !== 'hulp') {
        $v['nodig'] = 0;
    }
    return [$v, $f];
}

function v_com_aankondigingen(): void
{
    vereist_login();
    com_sync();
    $uid = get_current_user_id();
    $kanalen = com_aankondiging_kanalen($uid);
    $v = ['soort' => get('soort') ?: 'info', 'titel' => '', 'tekst' => '', 'kanalen' => (string) (int) get('kanaal', array_key_first($kanalen) ?? 0),
        'wanneer' => '', 'nodig' => 0, 'tot' => ''];
    $f = [];
    if (is_post()) {
        if (!$kanalen) {
            geen_toegang();
        }
        [$v, $f] = com_lees_aankondiging($uid, $kanalen);
        if (!$f) {
            db()->insert(t('com_aankondigingen'), $v + ['door_id' => $uid, 'aangemaakt' => nu_sql()]);
            $a = com_aankondiging((int) db()->insert_id);
            com_meld_aankondiging($a);
            flash('Aankondiging geplaatst.');
            naar(app_url("berichten/aankondiging/{$a->id}/"));
        }
    }
    // Alle aankondigingen die je ziet, ook voorbije (archief), nieuwste eerst.
    $alle = array_values(array_filter(db()->get_results("SELECT * FROM " . t('com_aankondigingen') . " ORDER BY id DESC LIMIT 200"),
        fn($a) => com_ziet_aankondiging($a, $uid)));
    toon('communicatie/aankondigingen', ['lijst' => $alle, 'kanalen' => $kanalen, 'v' => $v, 'f' => $f], 'Aankondigingen');
}

function v_com_aankondiging($id): void
{
    vereist_login();
    com_sync();
    $a = com_aankondiging((int) $id) ?: niet_gevonden();
    $uid = get_current_user_id();
    if (!com_ziet_aankondiging($a, $uid)) {
        geen_toegang();
    }
    $wijzigen = com_mag_aankondiging_wijzigen($a, $uid);
    $v = (array) $a;
    $f = [];
    if (is_post()) {
        $actie = post('actie');
        if ($actie === 'rsvp' && $a->soort === 'hulp') {
            $antwoord = post('antwoord');
            if (!isset(COM_RSVP[$antwoord])) {
                flash('Kies ja, nee of misschien.', 'error');
            } else {
                $personen = $antwoord === 'nee' ? 0 : max(1, min(50, (int) (post('personen') ?: 1)));
                $rij = ['antwoord' => $antwoord, 'personen' => $personen, 'opmerking' => mb_substr(post('opmerking'), 0, 200), 'gewijzigd' => nu_sql()];
                if (db()->get_var(db()->prepare("SELECT id FROM " . t('com_rsvp') . " WHERE aankondiging_id=%d AND user_id=%d", $a->id, $uid))) {
                    db()->update(t('com_rsvp'), $rij, ['aankondiging_id' => $a->id, 'user_id' => $uid]);
                } else {
                    db()->insert(t('com_rsvp'), $rij + ['aankondiging_id' => $a->id, 'user_id' => $uid]);
                }
                flash(match ($antwoord) {
                    'ja' => 'Bedankt! Je staat op de lijst' . ($personen > 1 ? " met $personen personen." : '.'),
                    'misschien' => 'Genoteerd als misschien.',
                    default => 'Genoteerd: je kan niet.',
                });
            }
            naar(terug(app_url("berichten/aankondiging/{$a->id}/")));
        } elseif ($actie === 'rsvp_weg' && $a->soort === 'hulp') {
            db()->delete(t('com_rsvp'), ['aankondiging_id' => $a->id, 'user_id' => $uid]);
            flash('Je antwoord is weg.');
            naar(app_url("berichten/aankondiging/{$a->id}/"));
        } elseif ($actie === 'bewaar' && $wijzigen) {
            // Ook de kanalen die ze al had, als je die niet (meer) beheert.
            [$v, $f] = com_lees_aankondiging($uid, com_aank_keuzes($a, $uid));
            if (!$f) {
                db()->update(t('com_aankondigingen'), $v + ['gewijzigd' => nu_sql()], ['id' => $a->id]);
                flash('Aankondiging bewaard.');
                naar(app_url("berichten/aankondiging/{$a->id}/"));
            }
            $v['id'] = $a->id;
        } elseif ($actie === 'beeindigen' && $wijzigen) {
            db()->update(t('com_aankondigingen'), ['tot' => vandaag()->modify('-1 day')->format('Y-m-d'), 'gewijzigd' => nu_sql()], ['id' => $a->id]);
            flash('De aankondiging staat niet meer bovenaan.');
            naar(app_url("berichten/aankondiging/{$a->id}/"));
        } elseif ($actie === 'verwijderen' && $wijzigen) {
            db()->delete(t('com_rsvp'), ['aankondiging_id' => $a->id]);
            db()->delete(t('com_aankondigingen'), ['id' => $a->id]);
            flash('Aankondiging verwijderd.');
            naar(app_url('berichten/'));
        } else {
            geen_toegang();
        }
    }
    $lijst = com_ziet_rsvp_lijst($a, $uid) ? com_rsvp_lijst((int) $a->id) : [];
    toon('communicatie/aankondiging', ['a' => $a, 'v' => $v, 'f' => $f, 'wijzigen' => $wijzigen, 'lijst' => $lijst,
        'kanalen' => $wijzigen ? com_aank_keuzes($a, $uid) : []], $a->titel, 'smal');
}

/** Keuzes bij het bewerken: wat je mag kiezen, plus de kanalen die de aankondiging al heeft. */
function com_aank_keuzes(object $a, int $uid): array
{
    $keuzes = com_aankondiging_kanalen($uid);
    foreach (com_aank_kanalen($a) as $id) {
        if (!isset($keuzes[$id]) && ($k = com_kanaal($id))) {
            $keuzes[$id] = com_volle_naam($k);
        }
    }
    return $keuzes;
}

/** Voor wie een aankondiging is, als tekst. */
function com_aank_doel(object $a): string
{
    $namen = [];
    foreach (com_aank_kanalen($a) as $id) {
        if ($k = com_kanaal($id)) {
            $namen[] = com_volle_naam($k);
        }
    }
    return $namen ? implode(', ', $namen) : 'Iedereen';
}

function v_com_rsvp_export($id): void
{
    vereist_login();
    $a = com_aankondiging((int) $id) ?: niet_gevonden();
    if ($a->soort !== 'hulp' || !com_ziet_rsvp_lijst($a, get_current_user_id())) {
        geen_toegang();
    }
    $rijen = [['Naam', 'E-mail', 'Antwoord', 'Personen', 'Opmerking', 'Gewijzigd']];
    foreach (com_rsvp_lijst((int) $a->id) as $r) {
        $u = get_userdata((int) $r->user_id);
        $rijen[] = [com_naam($u ?: 0), $u ? $u->user_email : '', COM_RSVP[$r->antwoord], (int) $r->personen, $r->opmerking,
            new \DateTimeImmutable($r->gewijzigd, wp_timezone())];
    }
    $t = com_rsvp_totalen((int) $a->id);
    $rijen[] = [];
    foreach (COM_RSVP as $k => $l) {
        $rijen[] = ["Totaal $l", '', $t[$k]['antwoorden'] . ' antwoord(en)', $t[$k]['personen']];
    }
    $naam = sanitize_file_name('hulpvraag-' . $a->titel) . '.xlsx';
    download(maak_xlsx([['naam' => 'Inschrijvingen', 'rijen' => $rijen, 'datumkolommen' => [5]]]),
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $naam);
}
