<?php
/** Module inschrijvingen: pagina's en acties (één functie per route, zoals de views van de Django-versie). */

namespace KHZS;

defined('ABSPATH') || exit;

function wedstrijd_of_404($id): object
{
    return wedstrijd((int) $id) ?: niet_gevonden();
}

function wedstrijd_url(object $w, ?object $z = null): string
{
    return app_url("wedstrijd/{$w->id}/", $z ? ['zwemmer' => $z->id] : []);
}

/** Melding voor de zwemmer bij een wijziging door staf, met push als het account dat wil. */
function meld(object $z, object $w, int $door, array $regels): void
{
    db()->insert(t('meldingen'), ['zwemmer_id' => $z->id, 'wedstrijd_id' => $w->id, 'door_id' => $door,
        'tekst' => implode("\n", $regels), 'gelezen' => 0, 'aangemaakt' => nu_sql()]);
    $ontvangers = array_values(array_filter(zwemmer_accounts($z), fn($uid) => voorkeur($uid, 'push_wijziging')));
    if ($ontvangers) {
        stuur($ontvangers, "Inschrijving {$z->voornaam} gewijzigd", mb_substr("{$w->naam}: " . implode('; ', $regels), 0, 180 + mb_strlen($w->naam) + 2),
            wedstrijd_url($w, $z));
    }
}

/** Na een wijziging: was de zwemmer al ingegeven door het secretariaat, dan moet die het opnieuw nakijken. */
function markeer_gewijzigd(object $z, object $w): void
{
    db()->update(t('deelnames'), ['ingegeven' => 0, 'gewijzigd_na_ingave' => 1, 'gewijzigd' => nu_sql()],
        ['zwemmer_id' => $z->id, 'wedstrijd_id' => $w->id, 'ingegeven' => 1]);
}

// ---------- algemeen ----------

/** Leest een zwemmerformulier uit $_POST met een prefix; geeft [velden, fouten]. */
function lees_zwemmer(string $p): array
{
    $v = ['voornaam' => post("{$p}voornaam"), 'naam' => post("{$p}naam"), 'geboortedatum' => post("{$p}geboortedatum"),
        'geslacht' => post("{$p}geslacht"), 'licentie' => post("{$p}licentie"), 'swimrankings_id' => post("{$p}swimrankings_id")];
    $f = [];
    foreach (['voornaam' => 60, 'naam' => 80] as $k => $max) {
        if ($v[$k] === '') {
            $f[$k] = 'Dit veld is verplicht.';
        } elseif (mb_strlen($v[$k]) > $max) {
            $f[$k] = "Maximaal $max tekens.";
        }
    }
    $d = dag($v['geboortedatum']);
    if (!$d || $d->format('Y-m-d') !== $v['geboortedatum']) {
        $f['geboortedatum'] = 'Geef een geldige datum op.';
    }
    if (!isset(GESLACHT[$v['geslacht']])) {
        $f['geslacht'] = 'Dit veld is verplicht.';
    }
    $v['licentie'] = mb_substr($v['licentie'], 0, 30);
    $v['swimrankings_id'] = mb_substr($v['swimrankings_id'], 0, 20);
    return [$v, $f];
}

function v_registreren(): void
{
    if (is_user_logged_in()) {
        naar(app_url('wedstrijden/'));
    }
    if (!registratie_open()) {
        toon('fout', ['titel' => 'Registreren', 'tekst' => 'Zelf een account aanmaken is uitgeschakeld. Vraag een account aan de club.'], 'Registreren', 'smal');
    }
    $form = ['email' => '', 'voornaam' => '', 'naam' => ''];
    $fouten = [];
    if (is_post()) {
        $form = ['email' => strtolower(post('email')), 'voornaam' => post('voornaam'), 'naam' => post('naam')];
        if (!is_email($form['email'])) {
            $fouten['email'] = 'Geef een geldig e-mailadres op.';
        } elseif (username_exists($form['email']) || email_exists($form['email'])) {
            $fouten['email'] = 'Er bestaat al een account met dit e-mailadres.';
        }
        foreach (['voornaam', 'naam'] as $k) {
            if ($form[$k] === '') {
                $fouten[$k] = 'Dit veld is verplicht.';
            }
        }
        if (empty($_POST['privacy'])) {
            $fouten['privacy'] = 'Je moet de privacyverklaring aanvaarden om een account te maken.';
        }
        $w1 = post_ruw('password1') ?? '';
        $w2 = post_ruw('password2') ?? '';
        if (mb_strlen($w1) < 8) {
            $fouten['password1'] = 'Dit wachtwoord is te kort. Het moet minstens 8 tekens bevatten.';
        } elseif (ctype_digit($w1)) {
            $fouten['password1'] = 'Dit wachtwoord bestaat enkel uit cijfers.';
        } elseif ($w1 !== $w2) {
            $fouten['password2'] = 'De twee wachtwoorden komen niet overeen.';
        }
        if (!$fouten) {
            $uid = wp_insert_user(['user_login' => $form['email'], 'user_email' => $form['email'], 'user_pass' => $w1,
                'first_name' => $form['voornaam'], 'last_name' => $form['naam'],
                'display_name' => trim($form['voornaam'] . ' ' . $form['naam']), 'role' => get_option('default_role', 'subscriber')]);
            if (is_wp_error($uid)) {
                $fouten['email'] = $uid->get_error_message();
            } else {
                zet_rollen($uid, []); // nog geen rol: die volgt uit de antwoorden in het stappenplan
                privacy_akkoord($uid);
                wp_set_current_user($uid);
                wp_set_auth_cookie($uid, true, is_ssl());
                naar(app_url('welkom/'));
            }
        }
    }
    toon('inschrijvingen/registreren', ['form' => $form, 'fouten' => $fouten], 'Profiel aanmaken', 'smal');
}

const WELKOM_STAPPEN = ['zelf', 'kinderen', 'staf', 'klaar'];

/** Bewaart een nieuwe zwemmer uit het formulier: account, gezinsrol en eventueel een ploegaanvraag. Geeft het id. */
function nieuwe_zwemmer(array $v, int $uid, bool $zelf): int
{
    $ploeg = (int) ($v['ploeg'] ?? 0);
    $toestemming = !empty($v['toestemming']);
    unset($v['ploeg'], $v['toestemming']);
    db()->insert(t('zwemmers'), $v + ['account_id' => $uid] + ($toestemming ? ['toestemming_op' => nu_sql(), 'toestemming_door' => $uid] : []));
    $id = (int) db()->insert_id;
    zorg_gezinsrol($uid, $zelf);
    if ($ploeg && ($p = ploeg($ploeg))) {
        vraag_ploeg_aan($uid, zwemmer($id), $p);
    }
    return $id;
}

/** Heeft deze zwemmer de naam van het account? Dan is het het account zelf (rol zwemmer, geen ouder). */
function is_zelf(array $v, \WP_User $u): bool
{
    return [mb_strtolower($v['voornaam']), mb_strtolower($v['naam'])] === [mb_strtolower($u->first_name), mb_strtolower($u->last_name)];
}

/** Stappenplan na de registratie, één vraag per scherm. Elke stap kan je overslaan en later opnieuw doen. */
function v_welkom(): void
{
    vereist_login();
    $u = wp_get_current_user();
    $stap = in_array(get('stap'), WELKOM_STAPPEN, true) ? get('stap') : 'zelf';
    $volgende = WELKOM_STAPPEN[min(array_search($stap, WELKOM_STAPPEN, true) + 1, 3)];
    $v = $stap === 'zelf' ? ['voornaam' => $u->first_name, 'naam' => $u->last_name] : ['naam' => $u->last_name];
    $f = [];
    $koppel_fout = null;
    if (is_post()) {
        if ($stap === 'zelf' || post('actie') === 'kind') {
            [$v, $f] = lees_zwemmer('');
            $v['ploeg'] = post('ploeg');
            controleer_toestemming($v, $f);
            if (!$f) {
                $id = nieuwe_zwemmer($v, $u->ID, $stap === 'zelf');
                flash($stap === 'zelf' ? 'Je bent toegevoegd als zwemmer.' : "{$v['voornaam']} is toegevoegd. Nog een kind? Vul het formulier opnieuw in.");
                naar(app_url('welkom/', ['stap' => 'kinderen']));
            }
        } elseif (post('actie') === 'koppel') {
            try {
                $z = koppel_zoek(post('code'));
                if (!$z) {
                    $koppel_fout = 'Deze koppelcode klopt niet of is verlopen. Vraag de andere ouder om een nieuwe code.';
                } else {
                    koppel($z, $u->ID);
                    zorg_gezinsrol($u->ID);
                    flash("{$z->voornaam} {$z->naam} is gekoppeld aan je account.");
                    naar(app_url('welkom/', ['stap' => 'kinderen']));
                }
            } catch (\RuntimeException $e) {
                $koppel_fout = $e->getMessage();
            }
        } elseif ($stap === 'staf') {
            $aangevraagd = [];
            foreach (array_intersect([TRAINER, TRAINER_ZWEMSCHOOL, BESTUURDER, SECRETARIS], (array) ($_POST['rollen'] ?? [])) as $r) {
                if (!heeft_rol([$r], $u->ID)) {
                    try {
                        vraag_rol_aan($u->ID, $r, post('motivatie'));
                        $aangevraagd[] = rol_naam($r);
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'error');
                    }
                }
            }
            if ($aangevraagd) {
                flash('Je aanvraag voor de rol ' . implode(' en ', $aangevraagd) . ' wacht op goedkeuring. Je krijgt een melding zodra ze behandeld is.', 'info');
            }
            naar(app_url('welkom/', ['stap' => 'klaar']));
        }
    }
    $zwemmers = zwemmers_van($u->ID);
    $kinderen = array_values(array_filter($zwemmers, fn($z) => !is_zelf((array) $z, $u)));
    $open = db()->get_results(db()->prepare("SELECT * FROM " . t('rolaanvragen') . " WHERE user_id=%d AND status=%s", $u->ID, RA_OPEN));
    toon('inschrijvingen/welkom', ['stap' => $stap, 'nr' => array_search($stap, WELKOM_STAPPEN, true) + 1, 'volgende' => $volgende,
        'v' => $v, 'f' => $f, 'koppel_fout' => $koppel_fout, 'zwemmers' => $zwemmers, 'kinderen' => $kinderen,
        'zelf_ontbreekt' => zelf_ontbreekt(), 'eigen_rollen' => rollen_van($u), 'open_rollen' => $open, 'ploegen' => actieve_ploegen()],
        'Welkom', 'smal');
}

/** Een ploeg aanvragen voor een zwemmer (of, voor de staf, de zwemmer er meteen in zetten). */
function v_zwemmer_ploeg($id): void
{
    vereist_login();
    vereist_post();
    $z = zwemmer_voor($id);
    $p = ploeg((int) post('ploeg')) ?: niet_gevonden();
    try {
        $lid = vraag_ploeg_aan(get_current_user_id(), $z, $p);
        flash($lid->status === PL_GOEDGEKEURD ? "{$z->voornaam} zit nu in {$p->naam}." : "Aanvraag voor {$p->naam} verstuurd. Je krijgt een melding zodra ze behandeld is.");
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    naar(app_url("zwemmers/{$z->id}/"));
}

function v_wedstrijden(): void
{
    vereist_login();
    if (zonder_wedstrijden()) {
        naar(app_url(''));
    }
    $staf = is_staf();
    $uid = get_current_user_id();
    $vandaag = vandaag()->format('Y-m-d');
    $w_tab = t('wedstrijden');
    $sql = "SELECT w.*, (SELECT MIN(datum) FROM " . t('sessies') . " s WHERE s.wedstrijd_id=w.id) AS _start,
        (SELECT COUNT(*) FROM " . t('bijlagen') . " b WHERE b.wedstrijd_id=w.id) AS bijlagen_aantal FROM $w_tab w";
    $lijst = db()->get_results($sql);
    $tellers = [];
    foreach (db()->get_results("SELECT s.wedstrijd_id, i.status, COUNT(*) c FROM " . t('inschrijvingen') . " i JOIN " . t('nummers') . " n ON n.id=i.nummer_id
        JOIN " . t('sessies') . " s ON s.id=n.sessie_id GROUP BY s.wedstrijd_id, i.status") as $r) {
        $tellers[$r->wedstrijd_id][$r->status] = (int) $r->c;
    }
    $eigen = $nieuw = $keuzes = [];
    if (!$staf) {
        // Zwemmers zien een wedstrijd zodra hij opgeladen is (interesse opgeven), tot de laatste wedstrijddag.
        $lijst = array_filter($lijst, fn($w) => $w->_start && $w->_start >= $vandaag);
        $zids = array_map(fn($z) => (int) $z->id, zwemmers_van($uid));
        $l = in_lijst($zids);
        foreach (db()->get_results("SELECT d.*, z.voornaam FROM " . t('deelnames') . " d JOIN " . t('zwemmers') . " z ON z.id=d.zwemmer_id WHERE d.zwemmer_id IN ($l) ORDER BY d.id") as $d) {
            $keuzes[$d->wedstrijd_id][] = $d;
        }
        foreach (db()->get_results("SELECT s.wedstrijd_id, COUNT(*) c FROM " . t('inschrijvingen') . " i JOIN " . t('nummers') . " n ON n.id=i.nummer_id
            JOIN " . t('sessies') . " s ON s.id=n.sessie_id WHERE i.zwemmer_id IN ($l) GROUP BY s.wedstrijd_id") as $r) {
            $eigen[$r->wedstrijd_id] = (int) $r->c;
        }
        foreach (db()->get_results("SELECT wedstrijd_id, COUNT(*) c FROM " . t('meldingen') . " WHERE zwemmer_id IN ($l) AND gelezen=0 GROUP BY wedstrijd_id") as $r) {
            $nieuw[$r->wedstrijd_id] = (int) $r->c;
        }
    }
    // Komende wedstrijden eerst (vroegste bovenaan), daarna de voorbije (meest recente bovenaan).
    usort($lijst, function ($a, $b) use ($vandaag) {
        $ka = $a->_start && $a->_start >= $vandaag ? [0, $a->_start] : [1, strrev((string) ($a->_start ?: $vandaag))];
        $kb = $b->_start && $b->_start >= $vandaag ? [0, $b->_start] : [1, strrev((string) ($b->_start ?: $vandaag))];
        if ($ka[0] !== $kb[0]) {
            return $ka[0] <=> $kb[0];
        }
        return $ka[0] === 0 ? strcmp($a->_start, $b->_start) : strcmp((string) ($b->_start ?: $vandaag), (string) ($a->_start ?: $vandaag));
    });
    foreach ($lijst as $w) {
        $w->aantal = array_sum($tellers[$w->id] ?? []);
        $w->te_keuren = $tellers[$w->id][INGEDIEND] ?? 0;
    }
    toon('inschrijvingen/wedstrijden', ['lijst' => $lijst, 'eigen' => $eigen, 'nieuw' => $nieuw, 'keuzes' => $keuzes], 'Wedstrijden');
}

// ---------- zwemmers & tijden ----------

function v_zwemmers(): void
{
    vereist_login();
    if (is_staf()) {
        $q = get('q');
        toon('inschrijvingen/zwemmers', ['zwemmers' => alle_zwemmers($q), 'q' => $q, 'alle' => true, 'zelf_ontbreekt' => zelf_ontbreekt()], 'Zwemmers');
    }
    toon('inschrijvingen/zwemmers', ['zwemmers' => zwemmers_van(get_current_user_id()), 'q' => '', 'alle' => false, 'zelf_ontbreekt' => zelf_ontbreekt()], 'Zwemmers');
}

/** Toon ‘Mezelf toevoegen’ zolang er geen zwemmer met de naam van dit account bij het account hoort. */
function zelf_ontbreekt(): bool
{
    $u = wp_get_current_user();
    foreach (zwemmers_van($u->ID) as $z) {
        if ($u->first_name && mb_strtolower($z->voornaam) === mb_strtolower($u->first_name) && mb_strtolower($z->naam) === mb_strtolower($u->last_name)) {
            return false;
        }
    }
    return true;
}

function v_zwemmer_nieuw(): void
{
    vereist_login();
    $zelf = get('zelf') === '1';
    $u = wp_get_current_user();
    $v = $zelf ? ['voornaam' => $u->first_name, 'naam' => $u->last_name] : [];
    $f = [];
    if (is_post()) {
        [$v, $f] = lees_zwemmer('');
        $v['ploeg'] = post('ploeg');
        controleer_toestemming($v, $f);
        if (!$f) {
            $id = nieuwe_zwemmer($v, $u->ID, is_zelf($v, $u));
            flash("{$v['voornaam']} {$v['naam']} toegevoegd.");
            naar(app_url("zwemmers/$id/"));
        }
    }
    $titel = $zelf ? 'Mezelf toevoegen als zwemmer' : 'Zwemmer toevoegen';
    toon('inschrijvingen/zwemmer_form', ['v' => $v, 'f' => $f, 'titel' => $titel, 'zelf' => $zelf, 'ploegen' => actieve_ploegen()], $titel, 'smal');
}

const AFSTANDEN = [25, 50, 100, 200, 400, 800, 1500];

function v_zwemmer($id): void
{
    vereist_login();
    $z = zwemmer_voor($id);
    $staf = is_staf();
    $v = (array) $z;
    $f = [];
    $tijd = ['slag' => 'FREE', 'afstand' => '', 'baan' => 'SCM', 'tijd' => '', 'datum' => '', 'wedstrijd' => ''];
    $tf = [];
    if (is_post()) {
        if (post('actie') === 'toestemming' && heeft_account($z, get_current_user_id())) {
            geef_toestemming((int) $z->id, get_current_user_id());
            flash('Toestemming bewaard. Bedankt!');
            naar(app_url("zwemmers/{$z->id}/"));
        }
        if (isset($_POST['z-voornaam'])) {
            [$v, $f] = lees_zwemmer('z-');
            if (!$f) {
                db()->update(t('zwemmers'), $v, ['id' => $z->id]);
                flash('Gegevens bewaard.');
                naar(app_url("zwemmers/{$z->id}/"));
            }
        }
        if (isset($_POST['t-tijd']) && $staf) {
            $tijd = ['slag' => post('t-slag'), 'afstand' => post('t-afstand'), 'baan' => post('t-baan'), 'tijd' => post('t-tijd'),
                'datum' => post('t-datum'), 'wedstrijd' => mb_substr(post('t-wedstrijd'), 0, 120)];
            if (!isset(SLAG[$tijd['slag']])) {
                $tf['slag'] = 'Maak een geldige keuze.';
            }
            if (!in_array((int) $tijd['afstand'], AFSTANDEN, true)) {
                $tf['afstand'] = 'Maak een geldige keuze.';
            }
            if (!isset(BAAN[$tijd['baan']])) {
                $tf['baan'] = 'Maak een geldige keuze.';
            }
            $hon = null;
            try {
                $hon = $tijd['tijd'] === '' ? null : parse_invoer_tijd($tijd['tijd']);
                if ($hon === null) {
                    $tf['tijd'] = 'Dit veld is verplicht.';
                }
            } catch (\InvalidArgumentException $e) {
                $tf['tijd'] = $e->getMessage();
            }
            if (!dag($tijd['datum'])) {
                $tf['datum'] = 'Geef een geldige datum op.';
            }
            if (!$tf) {
                db()->insert(t('tijden'), ['zwemmer_id' => $z->id, 'afstand' => (int) $tijd['afstand'], 'slag' => $tijd['slag'],
                    'baan' => $tijd['baan'], 'tijd' => $hon, 'datum' => $tijd['datum'], 'wedstrijd' => $tijd['wedstrijd'], 'bron' => 'manueel']);
                flash('Tijd toegevoegd.');
                naar(app_url("zwemmers/{$z->id}/"));
            }
        }
    }
    $inschrijvingen = db()->get_results(db()->prepare("SELECT i.*, n.nummer AS n_nummer, n.naam AS n_naam, n.afstand, n.slag, n.aflossing,
        w.id AS wid, w.naam AS wnaam, s.datum FROM " . t('inschrijvingen') . " i JOIN " . t('nummers') . " n ON n.id=i.nummer_id
        JOIN " . t('sessies') . " s ON s.id=n.sessie_id JOIN " . t('wedstrijden') . " w ON w.id=s.wedstrijd_id
        WHERE i.zwemmer_id=%d ORDER BY s.datum DESC, s.nummer, n.volgorde", $z->id));
    $lidmaatschappen = ploegen_van_zwemmer((int) $z->id);
    if (heeft_account($z, get_current_user_id())) { // de beslissingen over de ploegen zijn nu gezien
        db()->query(db()->prepare("UPDATE " . t('ploegleden') . " SET gelezen=1 WHERE zwemmer_id=%d AND status<>%s", $z->id, PL_OPEN));
    }
    $al = array_column(array_filter($lidmaatschappen, fn($l) => $l->status !== PL_AFGEKEURD), 'ploeg_id');
    toon('inschrijvingen/zwemmer', ['z' => $z, 'v' => $v, 'f' => $f, 'tijd' => $tijd, 'tf' => $tf, 'tijden' => tijden_van((int) $z->id),
        'lidmaatschappen' => $lidmaatschappen, 'kies_ploegen' => array_values(array_filter(actieve_ploegen(), fn($p) => !in_array($p->id, $al))),
        'meldingen' => meldingen(db()->prepare('m.zwemmer_id=%d', $z->id)), 'inschrijvingen' => $inschrijvingen,
        'accounts' => array_filter(array_map('get_userdata', zwemmer_accounts($z))), 'koppelcode' => koppel_toon(koppel_geldige_code($z) ?? ''),
        'toestemming_nodig' => minderjarig($z->geboortedatum) && empty($z->toestemming_op) && heeft_account($z, get_current_user_id())],
        "{$z->voornaam} {$z->naam}");
}

/** Een zwemmer van een ander account (bv. de andere ouder) koppelen met een koppelcode. */
function v_zwemmer_koppelen(): void
{
    vereist_login();
    vereist_post();
    try {
        $z = koppel_zoek(post('code'));
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
        naar(app_url('zwemmers/'));
    }
    if (!$z) {
        flash('Deze koppelcode klopt niet of is verlopen. Vraag de andere ouder om een nieuwe code.', 'error');
        naar(app_url('zwemmers/'));
    }
    if (koppel($z, get_current_user_id())) {
        zorg_gezinsrol(get_current_user_id());
        flash("{$z->voornaam} {$z->naam} is gekoppeld aan je account. Je ziet nu dezelfde inschrijvingen en meldingen.");
    } else {
        flash("{$z->voornaam} {$z->naam} hoort al bij je account.", 'info');
    }
    naar(app_url("zwemmers/{$z->id}/"));
}

function v_zwemmer_delen($id): void
{
    vereist_login();
    vereist_post();
    $z = zwemmer_voor($id);
    $code = koppel_nieuwe_code($z);
    flash("Koppelcode voor {$z->voornaam}: " . koppel_toon($code) . ' (' . KOPPEL_DAGEN . ' dagen geldig).');
    naar(app_url("zwemmers/{$z->id}/"));
}

/** Een account loskoppelen: zelf altijd; anderen enkel door het eerste account of de staf. */
function v_zwemmer_ontkoppelen($id): void
{
    vereist_login();
    vereist_post();
    $z = zwemmer_voor($id);
    $ik = get_current_user_id();
    $weg = (int) post('user');
    if ($weg !== $ik && (int) $z->account_id !== $ik && !is_staf()) {
        geen_toegang();
    }
    $u = get_userdata($weg) ?: niet_gevonden();
    try {
        ontkoppel($z, $weg);
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'error');
        naar(app_url("zwemmers/{$z->id}/"));
    }
    if ($weg === $ik && !is_staf()) {
        flash("{$z->voornaam} {$z->naam} is niet meer gekoppeld aan je account.");
        naar(app_url('zwemmers/'));
    }
    flash((volle_naam($u) ?: $u->user_login) . " is losgekoppeld van {$z->voornaam}.");
    naar(app_url("zwemmers/{$z->id}/"));
}

/** Staf: een bestaand account (op e-mail) koppelen, bv. als een ouder hulp vraagt. */
function v_zwemmer_account(): void
{
    vereist_rol(STAF);
    vereist_post();
    $z = zwemmer((int) post('zwemmer')) ?: niet_gevonden();
    $email = post('email');
    $u = get_user_by('email', $email) ?: get_user_by('login', $email);
    if (!$u) {
        flash("Geen account gevonden met e-mail $email.", 'error');
    } elseif (koppel($z, $u->ID)) {
        flash((volle_naam($u) ?: $u->user_login) . " is gekoppeld aan {$z->voornaam}.");
    } else {
        flash('Dit account was al gekoppeld.', 'info');
    }
    naar(app_url("zwemmers/{$z->id}/"));
}

function v_tijd_verwijderen($id): void
{
    vereist_login();
    vereist_post();
    $t = rij('tijden', (int) $id) ?: niet_gevonden();
    if (!is_staf()) {
        geen_toegang();
    }
    db()->delete(t('tijden'), ['id' => $t->id]);
    flash('Tijd verwijderd.');
    naar(app_url("zwemmers/{$t->zwemmer_id}/"));
}

// ---------- inschrijven ----------

function v_wedstrijd($id): void
{
    vereist_login();
    $w = wedstrijd_of_404($id);
    $staf = is_staf();
    $zwemmers = $staf ? alle_zwemmers() : zwemmers_van(get_current_user_id());
    $gekozen = null;
    if (get('zwemmer') !== '') {
        $gekozen = zwemmer_voor(get('zwemmer'));
    } elseif (count($zwemmers) === 1 && !$staf) {
        $gekozen = $zwemmers[0];
    }
    $ctx = ['w' => $w, 'zwemmers' => $zwemmers, 'gekozen' => $gekozen, 'staf' => $staf, 'mag_wijzigen' => mag_wijzigen(null, $w),
        'bijlagen' => bijlagen_van((int) $w->id), 'beheer_bijlagen' => false];
    if ($gekozen) {
        [$jaren, $programma] = programma_voor($gekozen, $w);
        $bestaande = inschrijvingen_zwemmer((int) $gekozen->id, (int) $w->id);
        $meldingen = meldingen(db()->prepare('m.zwemmer_id=%d AND m.wedstrijd_id=%d', $gekozen->id, $w->id));
        $ctx += ['deelname' => deelname((int) $gekozen->id, (int) $w->id), 'jaren' => $jaren, 'programma' => $programma,
            'bestaande' => $bestaande, 'meldingen' => $meldingen, 'ongelezen' => count(array_filter($meldingen, fn($m) => !$m->gelezen)),
            'totaal' => array_sum(array_map(fn($i) => (int) $i->nummer->kost_cent, $bestaande)),
            'heeft_tijden' => (bool) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('tijden') . " WHERE zwemmer_id=%d", $gekozen->id))];
        if (heeft_account($gekozen, get_current_user_id())) {
            db()->update(t('meldingen'), ['gelezen' => 1], ['zwemmer_id' => $gekozen->id, 'wedstrijd_id' => $w->id, 'gelezen' => 0]);
        }
    }
    toon('inschrijvingen/wedstrijd', $ctx, $w->naam);
}

/** Zet de inschrijvingen van één zwemmer voor deze wedstrijd gelijk aan de aangevinkte nummers. */
function v_inschrijven($id): void
{
    vereist_login();
    vereist_post();
    $w = wedstrijd_of_404($id);
    $z = zwemmer_voor(post('zwemmer'));
    $terug = wedstrijd_url($w, $z);
    if (!mag_wijzigen(null, $w)) {
        flash('De inschrijvingen voor deze wedstrijd zijn afgesloten.', 'error');
        naar($terug);
    }
    $staf = is_staf();
    $uid = get_current_user_id();
    $d = deelname((int) $z->id, (int) $w->id);
    if (!$staf && $d && $d->geweigerd) {
        flash('Je deelname aan deze wedstrijd werd geweigerd door de club.', 'error');
        naar($terug);
    }
    if (!$staf && $d && $d->gefinaliseerd) {
        flash('Je trainer heeft je inschrijving gefinaliseerd. Wijzigen kan enkel nog via je trainer.', 'error');
        naar($terug);
    }
    $jaren = leeftijd($z, $w);
    $lijsten = gekozen_limietlijsten((int) $w->id);
    $tijden = tijden_van((int) $z->id);
    $gevraagd = array_flip(array_map('intval', post_lijst('nummer')));
    $bestaande = inschrijvingen_zwemmer((int) $z->id, (int) $w->id);
    $nummers = nummers_van((int) $w->id);
    $toegevoegd = 0;
    $geweigerd = $log = [];

    // Staf kan per inschrijving de inschrijftijd aanpassen (veld tijd_<nummer>).
    if ($staf) {
        foreach ($bestaande as $nid => $i) {
            $invoer = post_ruw("tijd_$nid");
            if ($invoer === null || !isset($gevraagd[$nid])) {
                continue;
            }
            try {
                $nieuw = trim($invoer) !== '' && strtoupper(trim($invoer)) !== 'NT' ? parse_invoer_tijd($invoer) : null;
            } catch (\InvalidArgumentException) {
                $geweigerd[] = "#{$i->nummer->nummer} (ongeldige tijd '$invoer')";
                continue;
            }
            $oud = $i->inschrijftijd === null ? null : (int) $i->inschrijftijd;
            if ($nieuw !== $oud) {
                $log[] = "inschrijftijd #{$i->nummer->nummer} " . omschrijving($i->nummer) . ': ' . toon_tijd($oud) . ' → ' . toon_tijd($nieuw);
                db()->update(t('inschrijvingen'), ['inschrijftijd' => $nieuw, 'inschrijfbaan' => $i->inschrijfbaan ?: $w->baan], ['id' => $i->id]);
            }
        }
    }

    foreach ($nummers as $nid => $n) {
        if (!isset($gevraagd[$nid]) || isset($bestaande[$nid])) {
            continue;
        }
        if (!($n->inschrijfbaar && (int) $n->aflossing === 1 && past_in_nummer($z, $n, $jaren))) {
            $geweigerd[] = "#{$n->nummer}";
            continue;
        }
        $b = beoordeel($z, $n, $w, $jaren, $lijsten, $tijden);
        if (!$b->mag_inschrijven && !$staf) {
            $geweigerd[] = "#{$n->nummer} (limiet niet gehaald)";
            continue;
        }
        db()->insert(t('inschrijvingen'), ['zwemmer_id' => $z->id, 'nummer_id' => $nid, 'door_id' => $uid,
            'inschrijftijd' => $b->beste ? (int) $b->beste->tijd : null, 'inschrijfbaan' => $b->beste ? $b->beste->baan : '',
            'status' => $staf ? GOEDGEKEURD : INGEDIEND, 'opmerking' => '', 'aangemaakt' => nu_sql()]);
        $toegevoegd++;
        $log[] = "toegevoegd: #{$n->nummer} " . omschrijving($n);
    }

    // Tot de eigen deadline mag een zwemmer alles schrappen, ook goedgekeurde inschrijvingen.
    $verwijderd = 0;
    foreach ($bestaande as $nid => $i) {
        if (!isset($gevraagd[$nid])) {
            $log[] = "geschrapt: #{$i->nummer->nummer} " . omschrijving($i->nummer);
            db()->delete(t('inschrijvingen'), ['id' => $i->id]);
            $verwijderd++;
        }
    }
    if ($toegevoegd) {
        bewaar_deelname((int) $z->id, (int) $w->id, ['keuze' => DOET_MEE]);
    }
    if ($log) {
        markeer_gewijzigd($z, $w);
    }
    if ($staf && $log) {
        meld($z, $w, $uid, $log);
    }
    if (!$staf && $toegevoegd) {
        stuur(users_met('push_nieuwe_inschrijving', STAF), 'Nieuwe inschrijving',
            "{$z->voornaam} {$z->naam} – {$w->naam}: $toegevoegd nummer(s)", app_url("wedstrijd/{$w->id}/nakijken/"));
    }
    if ($geweigerd) {
        flash('Niet ingeschreven: ' . implode(', ', $geweigerd), 'error');
    }
    flash("Bewaard: $toegevoegd toegevoegd, $verwijderd geschrapt.");
    naar($terug);
}

function v_deelname($id): void
{
    vereist_login();
    vereist_post();
    $w = wedstrijd_of_404($id);
    $z = zwemmer_voor(post('zwemmer'));
    $keuze = post('keuze');
    $terug = wedstrijd_url($w, $z);
    if (!isset(KEUZES[$keuze])) {
        naar($terug);
    }
    $d = deelname((int) $z->id, (int) $w->id);
    if (!is_staf() && $d && ($d->geweigerd || $d->gefinaliseerd)) {
        flash('Je deelname is vastgelegd door de club. Wijzigen kan enkel nog via je trainer.', 'error');
        naar($terug);
    }
    bewaar_deelname((int) $z->id, (int) $w->id, ['keuze' => $keuze]);
    flash("{$z->voornaam}: " . mb_strtolower(KEUZES[$keuze]) . ' – bewaard.');
    naar($terug);
}

/** Staf weigert de deelname van een zwemmer (met reden) of staat ze opnieuw toe. */
function v_deelname_beoordelen($id): void
{
    vereist_rol(STAF);
    vereist_post();
    $w = wedstrijd_of_404($id);
    $z = zwemmer_voor(post('zwemmer'));
    $reden = mb_substr(post('reden'), 0, 200);
    deelname_of_nieuw((int) $z->id, (int) $w->id, INTERESSE);
    $naam = "{$z->voornaam} {$z->naam}";
    if (post('actie') === 'weigeren') {
        bewaar_deelname((int) $z->id, (int) $w->id, ['geweigerd' => 1, 'weigerreden' => $reden]);
        $te_keuren = array_map(fn($i) => (int) $i->id, inschrijvingen_van((int) $w->id, db()->prepare("AND i.zwemmer_id=%d AND i.status<>%s", $z->id, AFGEKEURD)));
        $n = count($te_keuren);
        if ($n) {
            db()->query(db()->prepare("UPDATE " . t('inschrijvingen') . " SET status=%s, opmerking=%s WHERE id IN (" . in_lijst($te_keuren) . ")",
                AFGEKEURD, $reden ?: 'deelname geweigerd'));
        }
        meld($z, $w, get_current_user_id(), array_merge(['deelname geweigerd' . ($reden ? ": $reden" : '')], $n ? ["$n inschrijving(en) afgekeurd"] : []));
        markeer_gewijzigd($z, $w);
        flash("Deelname van $naam geweigerd; {$z->voornaam} kreeg een melding.");
    } else {
        bewaar_deelname((int) $z->id, (int) $w->id, ['geweigerd' => 0, 'weigerreden' => '']);
        meld($z, $w, get_current_user_id(), ['deelname opnieuw toegestaan']);
        flash("Deelname van $naam opnieuw toegestaan.");
    }
    naar(wedstrijd_url($w, $z));
}

// ---------- bijlagen ----------

const TOEGELATEN_BIJLAGEN = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

function v_bijlage_toevoegen($id): void
{
    vereist_rol(STAF);
    vereist_post();
    $w = wedstrijd_of_404($id);
    $terug = terug(app_url("wedstrijd/{$w->id}/nakijken/"));
    $f = $_FILES['bestand'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        flash('Dit veld is verplicht.', 'error');
        naar($terug);
    }
    $ext = extensie($f['name']);
    if (!in_array($ext, TOEGELATEN_BIJLAGEN, true)) {
        flash('Enkel PDF, afbeeldingen, Word- of Excelbestanden.', 'error');
        naar($terug);
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        flash('Bestand is te groot (max. 10 MB).', 'error');
        naar($terug);
    }
    $naam = post('naam') ?: preg_replace('/\.[^.]*$/', '', wp_unslash($f['name']));
    $naam = mb_substr($naam, 0, 150);
    $jaar = current_datetime()->format('Y');
    $rel = "bijlagen/$jaar/" . wp_generate_password(12, false) . '-' . sanitize_file_name(wp_unslash($f['name']));
    prive_map("bijlagen/$jaar");
    if (!move_uploaded_file($f['tmp_name'], prive_map() . "/$rel")) {
        flash('Opslaan van de bijlage lukte niet.', 'error');
        naar($terug);
    }
    db()->insert(t('bijlagen'), ['wedstrijd_id' => $w->id, 'bestand' => $rel, 'naam' => $naam, 'door_id' => get_current_user_id(), 'toegevoegd' => nu_sql()]);
    do_action('khzs_wedstrijd_bewaard', (int) $w->id);
    flash("Bijlage ‘{$naam}’ toegevoegd.");
    naar($terug);
}

function v_bijlage_verwijderen($id): void
{
    vereist_rol(STAF);
    vereist_post();
    $b = rij('bijlagen', (int) $id) ?: niet_gevonden();
    @unlink(prive_map() . '/' . $b->bestand);
    db()->delete(t('bijlagen'), ['id' => $b->id]);
    do_action('khzs_bijlage_verwijderd', (int) $b->id);
    do_action('khzs_wedstrijd_bewaard', (int) $b->wedstrijd_id);
    flash("Bijlage ‘{$b->naam}’ verwijderd.");
    naar(terug(app_url("wedstrijd/{$b->wedstrijd_id}/nakijken/")));
}

/** Download via de app (de privémap wordt niet publiek geserveerd). Publieke bijlagen (websitekalender) ook zonder aanmelden. */
function v_bijlage($id): void
{
    $b = rij('bijlagen', (int) $id);
    if (!$b || !bijlage_publiek($b)) {
        vereist_login();
    }
    $b = $b ?: niet_gevonden();
    $pad = prive_map() . '/' . $b->bestand;
    if (!is_file($pad)) {
        niet_gevonden();
    }
    $ext = extensie($b->bestand);
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    download(file_get_contents($pad), $types[$ext] ?? 'application/octet-stream', "{$b->naam}.$ext", in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true));
}

// ---------- trainer ----------

function lees_dagen(string $k): ?int
{
    $v = post($k);
    return ctype_digit($v) ? (int) $v : null;
}

function v_nakijken($id): void
{
    vereist_rol(STAF);
    $w = wedstrijd_of_404($id);
    $instfout = [];
    if (is_post() && isset($_POST['dagen_trainer'])) {
        $dagen = lees_dagen('dagen_trainer');
        if ($dagen === null) {
            $instfout['dagen_trainer'] = 'Geef een positief geheel getal op.';
        } else {
            db()->update(t('wedstrijden'), ['dagen_trainer' => $dagen, 'info' => post('info')], ['id' => $w->id]);
            do_action('khzs_wedstrijd_bewaard', (int) $w->id);
            $w = wedstrijd((int) $w->id);
            flash('Bewaard. Deadline zwemmers is nu ' . deadline_zwemmer($w)->format('d/m/Y') . '.');
            naar(app_url("wedstrijd/{$w->id}/nakijken/"));
        }
    }
    $status = get('status');
    $alle = inschrijvingen_van((int) $w->id);
    $tellers = [];
    foreach ($alle as $i) {
        $tellers[$i->status] = ($tellers[$i->status] ?? 0) + 1;
    }
    $per_zwemmer = [];
    foreach ($alle as $i) {
        if ($status === '' || $i->status === $status) {
            $per_zwemmer[$i->zwemmer_id] ??= [$i->zwemmer, []];
            $per_zwemmer[$i->zwemmer_id][1][] = $i;
        }
    }
    foreach ($per_zwemmer as &$p) {
        usort($p[1], fn($a, $b) => (int) $a->nummer->volgorde <=> (int) $b->nummer->volgorde);
    }
    unset($p);
    $deelnames = db()->get_results(db()->prepare("SELECT d.*, z.voornaam, z.naam FROM " . t('deelnames') . " d JOIN " . t('zwemmers') . " z ON z.id=d.zwemmer_id
        WHERE d.wedstrijd_id=%d ORDER BY z.naam, z.voornaam", $w->id));
    $groepen = [];
    $deelname_van = [];
    foreach ($deelnames as $d) {
        $groepen[$d->geweigerd ? 'geweigerd' : $d->keuze][] = $d;
        $deelname_van[(int) $d->zwemmer_id] = $d;
    }
    $overzicht = [];
    foreach (KEUZES + ['geweigerd' => 'Geweigerd'] as $k => $label) {
        $overzicht[] = [$k, $label, $groepen[$k] ?? []];
    }
    $met_inschrijving = array_unique(array_map(fn($i) => (int) $i->zwemmer_id, $alle));
    $finaal = ['zwemmers' => count($met_inschrijving),
        'gefinaliseerd' => count(array_filter($met_inschrijving, fn($zid) => !empty($deelname_van[$zid]->gefinaliseerd))),
        'ingegeven' => count(array_filter($met_inschrijving, fn($zid) => !empty($deelname_van[$zid]->ingegeven))),
        'gewijzigd' => count(array_filter($deelname_van, fn($d) => $d->gewijzigd_na_ingave))];
    toon('inschrijvingen/nakijken', ['w' => $w, 'per_zwemmer' => $per_zwemmer, 'status' => $status, 'tellers' => $tellers, 'overzicht' => $overzicht,
        'deelname_van' => $deelname_van, 'finaal' => $finaal, 'instfout' => $instfout, 'bijlagen' => bijlagen_van((int) $w->id),
        'beheer_bijlagen' => true], "Nakijken · {$w->naam}");
}

/** Trainer: inschrijving van een zwemmer vastzetten (of heropenen). Secretariaat: als 'ingegeven' markeren. */
function v_finaliseren($id): void
{
    vereist_rol(STAF);
    vereist_post();
    $w = wedstrijd_of_404($id);
    $actie = post('finaliseer') ?: get('actie');
    $secretariaat = is_secretariaat();
    $uid = get_current_user_id();
    if (post('zwemmer') === 'alle') {
        $zids = array_unique(array_map(fn($i) => (int) $i->zwemmer_id, inschrijvingen_van((int) $w->id)));
        $zwemmers = [];
        foreach ($zids as $zid) {
            $d = deelname($zid, (int) $w->id);
            if ($d && ($d->geweigerd || ($actie === 'finaliseer' && $d->gefinaliseerd))) {
                continue;
            }
            $zwemmers[] = zwemmer($zid);
        }
    } else {
        $zwemmers = [zwemmer_voor(post('zwemmer'))];
    }
    $n = 0;
    foreach ($zwemmers as $z) {
        deelname_of_nieuw((int) $z->id, (int) $w->id, DOET_MEE);
        if ($actie === 'finaliseer') {
            $open = array_map(fn($i) => (int) $i->id, inschrijvingen_van((int) $w->id, db()->prepare("AND i.zwemmer_id=%d AND i.status=%s", $z->id, INGEDIEND)));
            if ($open) {
                db()->query(db()->prepare("UPDATE " . t('inschrijvingen') . " SET status=%s WHERE id IN (" . in_lijst($open) . ")", GOEDGEKEURD));
            }
            bewaar_deelname((int) $z->id, (int) $w->id, ['gefinaliseerd' => 1, 'gefinaliseerd_door' => $uid, 'gefinaliseerd_op' => nu_sql()]);
            $aantal = count(inschrijvingen_van((int) $w->id, db()->prepare("AND i.zwemmer_id=%d AND i.status=%s", $z->id, GOEDGEKEURD)));
            meld($z, $w, $uid, array_merge(["inschrijving gefinaliseerd: $aantal nummer(s)"], $open ? [count($open) . ' openstaande inschrijving(en) goedgekeurd'] : []));
        } elseif ($actie === 'heropen') {
            bewaar_deelname((int) $z->id, (int) $w->id, ['gefinaliseerd' => 0]);
            meld($z, $w, $uid, ['inschrijving heropend: je kan opnieuw wijzigen tot je deadline']);
            markeer_gewijzigd($z, $w);
        } elseif (in_array($actie, ['ingegeven', 'niet_ingegeven'], true) && $secretariaat) {
            bewaar_deelname((int) $z->id, (int) $w->id, ['ingegeven' => $actie === 'ingegeven' ? 1 : 0, 'gewijzigd_na_ingave' => 0]);
        } else {
            continue;
        }
        $n++;
    }
    $labels = ['finaliseer' => 'gefinaliseerd', 'heropen' => 'heropend', 'ingegeven' => 'als ingegeven gemarkeerd',
        'niet_ingegeven' => 'niet meer als ingegeven gemarkeerd'];
    if ($n) {
        flash("$n zwemmer(s) " . ($labels[$actie] ?? '') . '.');
    }
    naar(terug(app_url("wedstrijd/{$w->id}/nakijken/")));
}

/** Afdrukbaar overzicht (A4 / PDF via de browser) per zwemmer of per nummer. */
function v_overzicht($id): void
{
    vereist_rol(STAF);
    $w = wedstrijd_of_404($id);
    $filter = get('filter', 'actief'); // actief = zonder afgekeurde, goedgekeurd, alle
    $weergave = get('weergave', 'zwemmer');
    $waar = $filter === 'goedgekeurd' ? db()->prepare('AND i.status=%s', GOEDGEKEURD) : ($filter === 'actief' ? db()->prepare('AND i.status<>%s', AFGEKEURD) : '');
    $qs = inschrijvingen_van((int) $w->id, $waar);
    usort($qs, fn($a, $b) => [(int) $a->nummer->sessie->nummer, (int) $a->nummer->volgorde] <=> [(int) $b->nummer->sessie->nummer, (int) $b->nummer->volgorde]);
    $keuzes = [];
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('deelnames') . " WHERE wedstrijd_id=%d", $w->id)) as $d) {
        $keuzes[(int) $d->zwemmer_id] = $d;
    }
    $per_zwemmer = $per_nummer = [];
    foreach ($qs as $i) {
        $per_zwemmer[$i->zwemmer_id] ??= [$i->zwemmer, []];
        $per_zwemmer[$i->zwemmer_id][1][] = $i;
        $per_nummer[$i->nummer_id] ??= [$i->nummer, []];
        $per_nummer[$i->nummer_id][1][] = $i;
    }
    uasort($per_zwemmer, fn($a, $b) => [$a[0]->naam, $a[0]->voornaam] <=> [$b[0]->naam, $b[0]->voornaam]);
    $zwemmers = [];
    foreach ($per_zwemmer as $zid => [$z, $lijst]) {
        $zwemmers[] = ['z' => $z, 'lijst' => $lijst, 'jaren' => leeftijd($z, $w), 'deelname' => $keuzes[$zid] ?? null,
            'kost' => array_sum(array_map(fn($i) => (int) $i->nummer->kost_cent, $lijst))];
    }
    uasort($per_nummer, fn($a, $b) => [(int) $a[0]->sessie->nummer, (int) $a[0]->volgorde] <=> [(int) $b[0]->sessie->nummer, (int) $b[0]->volgorde]);
    $nummers = [];
    foreach ($per_nummer as [$n, $lijst]) {
        usort($lijst, fn($a, $b) => [$a->inschrijftijd === null, (int) $a->inschrijftijd] <=> [$b->inschrijftijd === null, (int) $b->inschrijftijd]);
        $nummers[] = [$n, $lijst];
    }
    $zonder = [];
    foreach ($keuzes as $zid => $d) {
        if (in_array($d->keuze, ACTIEF, true) && !$d->geweigerd && !isset($per_zwemmer[$zid])) {
            $zonder[] = zwemmer($zid);
        }
    }
    usort($zonder, fn($a, $b) => [$a->naam, $a->voornaam] <=> [$b->naam, $b->voornaam]);
    status_header(200);
    nocache_headers();
    $ctx = ['w' => $w, 'zwemmers' => $zwemmers, 'nummers' => $nummers, 'zonder' => $zonder, 'filter' => $filter, 'weergave' => $weergave,
        'aantal' => count($qs), 'totaal' => array_sum(array_column($zwemmers, 'kost')), 'bijlagen' => bijlagen_van((int) $w->id)];
    extract($ctx);
    include __DIR__ . '/views/overzicht.php';
    exit;
}

function v_beoordelen($id): void
{
    vereist_rol(STAF);
    vereist_post();
    $w = wedstrijd_of_404($id);
    $actie = post('actie');
    $ids = array_map('intval', post_lijst('inschrijving'));
    if (isset(I_STATUS[$actie]) && $ids) {
        $opmerking = mb_substr(post_ruw('opmerking') ?? '', 0, 200);
        $label = mb_strtolower(I_STATUS[$actie]);
        $qs = inschrijvingen_van((int) $w->id, 'AND i.id IN (' . in_lijst($ids) . ')');
        $per_zwemmer = [];
        foreach ($qs as $i) {
            if ($i->status !== $actie || $i->opmerking !== $opmerking) {
                $per_zwemmer[$i->zwemmer_id] ??= [$i->zwemmer, []];
                $per_zwemmer[$i->zwemmer_id][1][] = "$label: #{$i->nummer->nummer} " . omschrijving($i->nummer) . ($opmerking ? " ($opmerking)" : '');
            }
        }
        if ($qs) {
            db()->query(db()->prepare("UPDATE " . t('inschrijvingen') . " SET status=%s, opmerking=%s WHERE id IN (" . in_lijst(array_map(fn($i) => (int) $i->id, $qs)) . ")",
                $actie, $opmerking));
        }
        $n = count($qs);
        foreach ($per_zwemmer as [$z, $regels]) {
            meld($z, $w, get_current_user_id(), $regels);
            markeer_gewijzigd($z, $w);
        }
        flash("$n inschrijving(en) $label.");
    } elseif (isset(I_STATUS[$actie])) {
        flash('0 inschrijving(en) ' . mb_strtolower(I_STATUS[$actie]) . '.');
    }
    naar(terug(app_url("wedstrijd/{$w->id}/nakijken/")));
}

// ---------- sportsecretaris ----------

/** Controleert een geüpload Lenex-bestand; geeft [inhoud, naam] of een foutmelding. */
function lees_upload(): array|string
{
    $f = $_FILES['bestand'] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Dit veld is verplicht.';
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'Bestand is te groot.' : 'Opladen mislukt.';
    }
    $naam = wp_unslash($f['name']);
    if (!preg_match('/\.(lxf|lef|xml)$/i', $naam)) {
        return 'Kies een .lxf- of .lef-bestand.';
    }
    if ($f['size'] > 20 * 1024 * 1024) {
        return 'Bestand is te groot (max 20 MB).';
    }
    return [file_get_contents($f['tmp_name']), $naam];
}

function v_upload($id = null): void
{
    vereist_rol(SECRETARIAAT);
    $bestaand = $id ? wedstrijd_of_404($id) : null;
    $fout = '';
    if (is_post()) {
        $up = lees_upload();
        if (is_string($up)) {
            $fout = $up;
        } else {
            [$inhoud, $naam] = $up;
            try {
                $meet = parse_meet(lees_xml($inhoud));
            } catch (LenexFout $e) {
                $fout = $e->getMessage();
            }
            if (!$fout) {
                $rel = 'lenex/' . wp_generate_password(8, false) . '-' . sanitize_file_name($naam);
                prive_map('lenex');
                file_put_contents(prive_map() . "/$rel", $inhoud);
                $wid = importeer($meet, $rel, $bestaand ? (int) $bestaand->id : null);
                $w = wedstrijd($wid);
                if (!$bestaand) {
                    stuur(users_met('push_nieuwe_wedstrijd', GEZIN), "Nieuwe wedstrijd: {$w->naam}",
                        'Laat weten of je interesse hebt of meedoet.', app_url("wedstrijd/$wid/"));
                }
                $aantal = array_sum(array_map(fn($s) => count($s['nummers']), $meet['sessies']));
                flash("'{$w->naam}' ingelezen: " . count($meet['sessies']) . " sessies, $aantal nummers, " . count($meet['limietlijsten']) . ' limietlijsten.');
                foreach ($meet['waarschuwingen'] as $t) {
                    flash($t, 'info');
                }
                naar(app_url("wedstrijd/$wid/instellingen/"));
            }
        }
    }
    toon('inschrijvingen/upload', ['bestaand' => $bestaand, 'fout' => $fout], 'Lenex uploaden', 'smal');
}

function v_uitslagen(): void
{
    vereist_rol(STAF);
    $fout = '';
    $stats = null;
    if (is_post()) {
        $up = lees_upload();
        if (is_string($up)) {
            $fout = $up;
        } else {
            try {
                $resultaten = parse_uitslagen(lees_xml($up[0]));
                if (!$resultaten) {
                    $fout = 'Geen individuele uitslagen gevonden in dit bestand.';
                } else {
                    $stats = importeer_uitslagen($resultaten);
                    flash("{$stats['gekoppeld']} resultaten van clubzwemmers verwerkt: {$stats['nieuw']} nieuwe en {$stats['verbeterd']} verbeterde beste tijden.");
                }
            } catch (LenexFout $e) {
                $fout = $e->getMessage();
            }
        }
    }
    toon('inschrijvingen/uitslagen', ['fout' => $fout, 'stats' => $stats], 'Uitslagen importeren', 'smal');
}

function v_instellingen($id): void
{
    vereist_rol(SECRETARIAAT);
    $w = wedstrijd_of_404($id);
    $fouten = [];
    $lijsten = limietlijsten((int) $w->id, false);
    if (is_post()) {
        $v = ['naam' => mb_substr(post('naam'), 0, 200), 'deadline' => post('deadline'), 'status' => post('status'), 'info' => post_ruw('info') ?? ''];
        $dagen = lees_dagen('dagen_secretaris');
        if ($v['naam'] === '') {
            $fouten['naam'] = 'Dit veld is verplicht.';
        }
        if (!dag($v['deadline'])) {
            $fouten['deadline'] = 'Geef een geldige datum op.';
        }
        if ($dagen === null) {
            $fouten['dagen_secretaris'] = 'Geef een positief geheel getal op.';
        }
        if (!isset(W_STATUS[$v['status']])) {
            $fouten['status'] = 'Maak een geldige keuze.';
        }
        if (!$fouten) {
            $vorige_status = $w->status;
            $v['info'] = trim($v['info']);
            db()->update(t('wedstrijden'), $v + ['dagen_secretaris' => $dagen], ['id' => $w->id]);
            $gekozen = array_flip(post_lijst('limietgroepen'));
            foreach ($lijsten as $l) {
                db()->update(t('limietlijsten'), ['gekozen' => isset($gekozen["{$l->naam}|{$l->baan}"]) ? 1 : 0], ['id' => $l->id]);
            }
            $w = wedstrijd((int) $w->id);
            do_action('khzs_wedstrijd_bewaard', (int) $w->id);
            if ($vorige_status !== W_OPEN && $w->status === W_OPEN) {
                $n = stuur(users_met('push_nieuwe_wedstrijd', null, (int) $w->id), "Inschrijvingen open: {$w->naam}",
                    'Inschrijven kan tot ' . deadline_zwemmer($w)->format('d/m') . '.', app_url("wedstrijd/{$w->id}/"));
                if ($n) {
                    flash("Pushmelding verstuurd naar $n toestel(len).", 'info');
                }
            }
            flash('Instellingen bewaard.');
            naar(app_url("wedstrijd/{$w->id}/instellingen/"));
        }
        foreach ($v as $k => $val) {
            $w->$k = $val;
        }
    }
    $groepen = [];
    foreach ($lijsten as $l) {
        $groepen["{$l->naam}|{$l->baan}"] ??= ["{$l->naam} (" . BAAN[$l->baan] . ')', false];
        $groepen["{$l->naam}|{$l->baan}"][1] = $groepen["{$l->naam}|{$l->baan}"][1] || $l->gekozen;
    }
    ksort($groepen);
    toon('inschrijvingen/instellingen', ['w' => $w, 'fouten' => $fouten, 'groepen' => $groepen, 'sessies' => sessies_met_nummers((int) $w->id),
        'bijlagen' => bijlagen_van((int) $w->id), 'beheer_bijlagen' => true], $w->naam);
}

function v_verwijderen($id): void
{
    vereist_rol(SECRETARIAAT);
    vereist_post();
    $w = wedstrijd_of_404($id);
    verwijder_wedstrijd((int) $w->id);
    flash("'{$w->naam}' verwijderd.");
    naar(app_url('wedstrijden/'));
}

function goedgekeurd(object $w): array
{
    $l = inschrijvingen_van((int) $w->id, db()->prepare('AND i.status=%s', GOEDGEKEURD));
    if (get('enkel') === 'gefinaliseerd') {
        $l = array_values(array_filter($l, fn($i) => (bool) (deelname((int) $i->zwemmer_id, (int) $w->id)->gefinaliseerd ?? false)));
    }
    return $l;
}

function v_export_lxf($id): void
{
    vereist_rol(SECRETARIAAT);
    $w = wedstrijd_of_404($id);
    download(bouw_inschrijvingen($w, goedgekeurd($w)), 'application/zip', bestandsnaam($w, 'lxf'));
}

function v_export_xlsx($id): void
{
    vereist_rol(SECRETARIAAT);
    $w = wedstrijd_of_404($id);
    download(excel_overzicht($w, goedgekeurd($w)), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', bestandsnaam($w, 'xlsx'));
}

// ---------- uitleg ----------

const FILMPJES = [
    ['zwemmer', 'Voor zwemmers en ouders', 'handleiding-zwemmer'],
    ['trainer', 'Voor trainers', 'handleiding-trainer'],
    ['sportsecretaris', 'Voor de sportsecretaris', 'handleiding-sportsecretaris'],
];
const PDF_NAMEN = [
    'HZS-inschrijvingen-documentatie' => 'Volledige documentatie', 'onepager-zwemmer' => 'Onepager zwemmers',
    'onepager-trainer' => 'Onepager trainers', 'onepager-sportsecretaris' => 'Onepager sportsecretaris',
    'handleiding-zwemmer' => 'Handleiding zwemmers & ouders', 'handleiding-trainer' => 'Handleiding trainers',
    'handleiding-sportsecretaris' => 'Handleiding sportsecretaris', 'handleiding-beheerder' => 'Handleiding beheerder',
    'technisch' => 'Technische documentatie',
];

function v_uitleg(): void
{
    vereist_login();
    $secretariaat = is_secretariaat();
    $trainer = heeft_rol([TRAINER]);
    $zichtbaar = $secretariaat ? array_column(FILMPJES, 0) : ($trainer ? ['trainer', 'zwemmer'] : ['zwemmer']);
    $filmpjes = array_values(array_filter(FILMPJES, fn($f) => in_array($f[0], $zichtbaar, true)));
    $pdfs = $secretariaat
        ? array_merge(['HZS-inschrijvingen-documentatie', 'onepager-sportsecretaris', 'handleiding-sportsecretaris', 'handleiding-trainer', 'handleiding-zwemmer'],
            heeft_rol([BEHEERDER]) ? ['handleiding-beheerder', 'technisch'] : [])
        : ($trainer ? ['onepager-trainer', 'handleiding-trainer', 'handleiding-zwemmer'] : ['onepager-zwemmer', 'handleiding-zwemmer']);
    // Filmpjes en PDF's zijn optioneel (groot); enkel tonen wat meegeleverd is in assets/docs.
    $filmpjes = array_values(array_filter($filmpjes, fn($f) => is_file(KHZS_PAD . "assets/docs/video/{$f[0]}.mp4")));
    $pdfs = array_values(array_filter($pdfs, fn($p) => is_file(KHZS_PAD . "assets/docs/pdf/$p.pdf")));
    toon('inschrijvingen/uitleg', ['filmpjes' => $filmpjes, 'pdfs' => $pdfs], 'Uitleg');
}

