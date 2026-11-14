<?php
/**
 * Module communicatie: regels. Opbouw zoals Discord: secties → kanalen → threads. Wie een kanaal ziet, hangt af van
 * de toegang van het kanaal (of van het kanaal erboven, voor een thread die 'erven' heeft):
 *
 * - iedereen: alle leden; competitie / zwemschool: wie een zwemmer in een ploeg van die soort heeft;
 *   ploeg: de zwemmers (en gekoppelde ouders) en de trainers van die ploeg; wedstrijd: wie ooit deelnam (interesse,
 *   ik doe mee of ingeschreven) met de gekoppelde accounts; staf: enkel trainers en bestuur.
 * - "Als staf" (lezen, posten, vastpinnen, threads, bijlagen) zien de trainers van de competitie, het bestuur, het
 *   secretariaat en de beheerders alles. Een trainer van de zwemschool ziet als staf alles buiten de competitie
 *   (geen sectie Competitie, geen wedstrijden, geen competitieploegen). Een trainer ziet zijn eigen ploegen altijd als staf.
 */

namespace KHZS;

defined('ABSPATH') || exit;

const COM_SECTIE_SOORT = ['algemeen' => 'Algemeen', 'competitie' => 'Competitie', 'zwemschool' => 'Zwemschool'];
const COM_TOEGANG = ['iedereen' => 'Alle leden', 'competitie' => 'Leden van de competitie', 'zwemschool' => 'Leden van de zwemschool',
    'ploeg' => 'Eén ploeg', 'staf' => 'Enkel trainers en bestuur', 'wedstrijd' => 'Deelnemers aan de wedstrijd', 'erven' => 'Zoals het kanaal'];
const COM_AANK_SOORT = ['info' => 'Informatief', 'dringend' => 'Dringend', 'aankondiging' => 'Aankondiging', 'hulp' => 'Vraag naar hulp'];
const COM_AANK_ICOON = ['info' => 'ℹ️', 'dringend' => '⚠️', 'aankondiging' => '📣', 'hulp' => '🙋'];
const COM_RSVP = ['ja' => 'Ja', 'misschien' => 'Misschien', 'nee' => 'Nee'];
/** Bijlagen: foto's en pdf's tot 5 MB; foto's worden verkleind tot 1600 pixels. */
const COM_BIJLAGE_MAX = 5 * 1024 * 1024;
const COM_PER_PAGINA = 50;

// ---------- rollen ----------

/** Beheert alle secties, kanalen, threads en aankondigingen. */
function com_is_bestuur($user = null): bool
{
    return heeft_rol([SECRETARIS, BESTUURDER, BEHEERDER], $user);
}

/** Ziet alle kanalen als staf (dezelfde groep die alle ploegen ziet). */
function com_ziet_alles($user = null): bool
{
    return heeft_rol(ALLE_PLOEGEN, $user);
}

// ---------- gegevens (per verzoek in het geheugen) ----------

function com_cache(bool $wis = false): array
{
    static $c = null;
    if ($wis) {
        $c = null;
        return [];
    }
    if ($c === null) {
        $c = ['kanalen' => [], 'secties' => []];
        foreach (db()->get_results("SELECT * FROM " . t('com_kanalen') . " ORDER BY volgorde, naam") as $k) {
            $c['kanalen'][(int) $k->id] = $k;
        }
        foreach (db()->get_results("SELECT * FROM " . t('com_secties') . " ORDER BY volgorde, id") as $s) {
            $c['secties'][(int) $s->id] = $s;
        }
    }
    return $c;
}

function com_kanalen(): array
{
    return com_cache()['kanalen'];
}

function com_secties(): array
{
    return com_cache()['secties'];
}

function com_kanaal(int $id): ?object
{
    return com_kanalen()[$id] ?? null;
}

function com_sectie(int $id): ?object
{
    return com_secties()[$id] ?? null;
}

/** Het kanaal waarvan de toegang geldt: een thread met 'erven' volgt zijn kanaal. */
function com_effectief(object $k): object
{
    return $k->toegang === 'erven' && (int) $k->ouder_id && ($o = com_kanaal((int) $k->ouder_id)) ? $o : $k;
}

/** Naam met het kanaal erboven, bv. 'Wedstrijden › Herfstmeeting'. */
function com_volle_naam(object $k): string
{
    $o = (int) $k->ouder_id ? com_kanaal((int) $k->ouder_id) : null;
    return $o ? "{$o->naam} › {$k->naam}" : $k->naam;
}

/** Wat een account te maken heeft met ploegen en wedstrijden (één keer per verzoek opgezocht). */
function com_ctx(int $uid): object
{
    static $c = [];
    if (isset($c[$uid])) {
        return $c[$uid];
    }
    $zw = sql_zwemmers_van($uid);
    $ploegen = array_map('intval', db()->get_col(db()->prepare("SELECT ploeg_id FROM " . t('ploegleden') . " WHERE status=%s AND zwemmer_id IN ($zw)", PL_GOEDGEKEURD)));
    $soorten = $ploegen ? db()->get_col("SELECT DISTINCT soort FROM " . t('ploegen') . " WHERE id IN (" . in_lijst($ploegen) . ")") : [];
    return $c[$uid] = (object) [
        'uid' => $uid,
        'ploegen' => $ploegen,
        'soorten' => $soorten,
        'getraind' => eigen_getrainde_ploegen($uid),
        'wedstrijden' => array_map('intval', db()->get_col("SELECT kanaal_id FROM " . t('com_wedstrijdzwemmers') . " WHERE zwemmer_id IN ($zw)")),
        'alles' => com_ziet_alles($uid),
        'zwemschool' => heeft_rol([TRAINER_ZWEMSCHOOL], $uid),
        'trainer' => heeft_rol([TRAINER], $uid),
        'secretaris' => heeft_rol([SECRETARIS], $uid),
        'bestuur' => com_is_bestuur($uid),
    ];
}

// ---------- wie ziet wat ----------

/** Ziet dit kanaal als staf: lezen, posten (ook als leden enkel lezen), vastpinnen, threads maken, bijlagen. */
function com_staf_ziet(object $k, int $uid): bool
{
    $x = com_ctx($uid);
    if ($x->alles) {
        return true;
    }
    $e = com_effectief($k);
    if ($e->toegang === 'ploeg' && in_array((int) $e->ploeg_id, $x->getraind, true)) {
        return true;
    }
    if ($x->zwemschool) {
        if ((com_sectie((int) $k->sectie_id)->soort ?? '') === 'competitie') {
            return false;
        }
        return match ($e->toegang) {
            'wedstrijd', 'competitie' => false,
            'ploeg' => (ploeg((int) $e->ploeg_id)->soort ?? '') === 'zwemschool',
            default => true,
        };
    }
    return false;
}

/** Ziet dit kanaal als lid (zwemmer of ouder). */
function com_lid_ziet(object $k, int $uid): bool
{
    $x = com_ctx($uid);
    $e = com_effectief($k);
    return match ($e->toegang) {
        'iedereen' => true,
        'competitie', 'zwemschool' => in_array($e->toegang, $x->soorten, true),
        'ploeg' => in_array((int) $e->ploeg_id, $x->ploegen, true) || in_array((int) $e->ploeg_id, $x->getraind, true),
        'wedstrijd' => in_array((int) $e->id, $x->wedstrijden, true),
        default => false,
    };
}

function com_ziet(object $k, int $uid): bool
{
    return $uid > 0 && (com_staf_ziet($k, $uid) || com_lid_ziet($k, $uid));
}

function com_mag_posten(object $k, int $uid): bool
{
    if ((int) $k->gearchiveerd) {
        return false;
    }
    return com_staf_ziet($k, $uid) || ((int) $k->leden_posten && com_lid_ziet($k, $uid));
}

/** Berichten vastpinnen, berichten van anderen verwijderen, threads maken en bijlagen toevoegen. */
function com_mag_modereren(object $k, int $uid): bool
{
    return com_staf_ziet($k, $uid);
}

/** Instellingen van het kanaal (beschrijving, wie mag posten, archiveren): het bestuur, en trainers voor hun eigen ploegkanaal. */
function com_mag_beheren(object $k, int $uid): bool
{
    if (com_is_bestuur($uid)) {
        return true;
    }
    $e = com_effectief($k);
    $p = (int) $k->ploeg_id ?: ($e->toegang === 'ploeg' ? (int) $e->ploeg_id : 0);
    return $p && in_array($p, com_ctx($uid)->getraind, true);
}

/**
 * Hoort bij het kanaal: krijgt standaard een melding bij een nieuw bericht. Wie een kanaal enkel ziet omdat hij of zij
 * alles ziet (bv. een bestuurder bij een ploeg), krijgt standaard geen meldingen en kan het kanaal volgen.
 */
function com_is_lid(object $k, int $uid): bool
{
    $x = com_ctx($uid);
    $e = com_effectief($k);
    return match ($e->toegang) {
        'iedereen' => true,
        'competitie' => com_lid_ziet($k, $uid) || $x->trainer,
        'zwemschool' => com_lid_ziet($k, $uid) || $x->zwemschool,
        'ploeg' => com_lid_ziet($k, $uid),
        'wedstrijd' => com_lid_ziet($k, $uid) || $x->trainer || $x->secretaris,
        'staf' => com_staf_ziet($k, $uid),
        default => false,
    };
}

function com_status(int $kanaal_id, int $uid): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('com_status') . " WHERE kanaal_id=%d AND user_id=%d", $kanaal_id, $uid)) ?: null;
}

function com_zet_status(int $kanaal_id, int $uid, array $velden): void
{
    if (com_status($kanaal_id, $uid)) {
        db()->update(t('com_status'), $velden, ['kanaal_id' => $kanaal_id, 'user_id' => $uid]);
    } else {
        db()->insert(t('com_status'), $velden + ['kanaal_id' => $kanaal_id, 'user_id' => $uid]);
    }
}

/** Meldingen voor nieuwe berichten: eigen keuze voor het kanaal, anders die voor het kanaal erboven, anders lid of niet. */
function com_meldingen_aan(object $k, int $uid): bool
{
    $s = com_status((int) $k->id, $uid);
    if ($s && $s->meldingen !== null) {
        return (bool) (int) $s->meldingen;
    }
    if ((int) $k->ouder_id && ($o = com_kanaal((int) $k->ouder_id))) {
        $so = com_status((int) $o->id, $uid);
        if ($so && $so->meldingen !== null) {
            return (bool) (int) $so->meldingen;
        }
    }
    return com_is_lid($k, $uid);
}

/** Alle kanalen en threads die dit account ziet. */
function com_zichtbaar(int $uid): array
{
    return array_filter(com_kanalen(), fn($k) => com_ziet($k, $uid));
}

// ---------- opbouw: standaardsecties, ploegkanalen en wedstrijdthreads ----------

function com_nieuw_kanaal(array $v): int
{
    db()->insert(t('com_kanalen'), $v + ['aangemaakt' => nu_sql()]);
    $id = (int) db()->insert_id;
    com_cache(true);
    return $id;
}

function com_nieuwe_sectie(string $naam, string $soort, int $volgorde): int
{
    db()->insert(t('com_secties'), ['naam' => $naam, 'soort' => $soort, 'volgorde' => $volgorde, 'aangemaakt' => nu_sql()]);
    com_cache(true);
    return (int) db()->insert_id;
}

/** Eerste sectie van een soort (maakt ze aan als ze niet bestaat). */
function com_sectie_van_soort(string $soort): int
{
    foreach (com_secties() as $s) {
        if ($s->soort === $soort) {
            return (int) $s->id;
        }
    }
    return com_nieuwe_sectie(COM_SECTIE_SOORT[$soort] ?? 'Algemeen', $soort, count(com_secties()) + 1);
}

/** Bij de eerste keer: de secties Algemeen, Competitie en Zwemschool met hun vaste kanalen. */
function com_standaard_opbouw(): void
{
    if (com_secties() || com_kanalen()) {
        return;
    }
    $alg = com_nieuwe_sectie('Algemeen', 'algemeen', 1);
    $comp = com_nieuwe_sectie('Competitie', 'competitie', 2);
    $zs = com_nieuwe_sectie('Zwemschool', 'zwemschool', 3);
    $k = fn(int $s, string $naam, string $toegang, int $posten, string $besch, int $volg, string $functie = '') =>
        com_nieuw_kanaal(['sectie_id' => $s, 'naam' => $naam, 'toegang' => $toegang, 'leden_posten' => $posten, 'beschrijving' => $besch,
            'volgorde' => $volg, 'functie' => $functie]);
    $k($alg, 'Algemeen', 'iedereen', 0, 'Nieuws van de club voor alle leden.', 1);
    $k($alg, 'Trainers en bestuur', 'staf', 1, 'Overleg tussen trainers en bestuur.', 2);
    $k($comp, 'Competitie algemeen', 'competitie', 1, 'Voor alle zwemmers en ouders van de competitie.', 1);
    $k($comp, 'Wedstrijden', 'competitie', 1, 'Per wedstrijd een thread met de deelnemers.', 2, 'wedstrijden');
    $k($zs, 'Zwemschool algemeen', 'zwemschool', 1, 'Voor alle ouders van de zwemschool.', 1);
}

/** Het kanaal waaronder de wedstrijdthreads komen. */
function com_wedstrijdkanaal(): object
{
    foreach (com_kanalen() as $k) {
        if ($k->functie === 'wedstrijden') {
            return $k;
        }
    }
    return com_kanaal(com_nieuw_kanaal(['sectie_id' => com_sectie_van_soort('competitie'), 'naam' => 'Wedstrijden', 'toegang' => 'competitie',
        'leden_posten' => 1, 'functie' => 'wedstrijden', 'beschrijving' => 'Per wedstrijd een thread met de deelnemers.', 'volgorde' => 50]));
}

/**
 * Houdt alles gelijk met de rest van de app: standaardopbouw, een kanaal per ploeg (naam en archief volgen de ploeg),
 * een thread per wedstrijd die niet meer in concept staat, en de deelnemers van die wedstrijden. Eén keer per verzoek.
 */
function com_sync(bool $opnieuw = false): void
{
    static $gedaan = false;
    if ($gedaan && !$opnieuw) {
        return;
    }
    $gedaan = true;
    com_cache(true);
    com_standaard_opbouw();
    // Ploegen zonder kanaal.
    $zonder = db()->get_results("SELECT p.* FROM " . t('ploegen') . " p WHERE NOT EXISTS (SELECT 1 FROM " . t('com_kanalen')
        . " k WHERE k.ploeg_id=p.id AND k.functie='ploeg') ORDER BY p.naam");
    foreach ($zonder as $p) {
        com_nieuw_kanaal(['sectie_id' => com_sectie_van_soort($p->soort === 'zwemschool' ? 'zwemschool' : 'competitie'), 'naam' => $p->naam,
            'toegang' => 'ploeg', 'ploeg_id' => $p->id, 'functie' => 'ploeg', 'leden_posten' => 1, 'beschrijving' => $p->beschrijving, 'volgorde' => 100,
            'gearchiveerd' => (int) $p->actief ? 0 : 1]);
    }
    $n = db()->query("UPDATE " . t('com_kanalen') . " k JOIN " . t('ploegen') . " p ON p.id=k.ploeg_id SET k.naam=p.naam, k.gearchiveerd=1-p.actief
        WHERE k.functie='ploeg' AND (k.naam<>p.naam OR k.gearchiveerd<>1-p.actief)");
    if ($n) {
        com_cache(true);
    }
    if (module_actief('inschrijvingen')) {
        com_sync_wedstrijden();
    }
}

function com_sync_wedstrijden(): void
{
    $nieuw = db()->get_results(db()->prepare("SELECT w.id, w.naam FROM " . t('wedstrijden') . " w WHERE w.status<>%s AND NOT EXISTS (SELECT 1 FROM "
        . t('com_kanalen') . " k WHERE k.wedstrijd_id=w.id) ORDER BY w.id", W_CONCEPT));
    if ($nieuw) {
        $ouder = com_wedstrijdkanaal();
        foreach ($nieuw as $w) {
            $s = startdatum(wedstrijd((int) $w->id));
            com_nieuw_kanaal(['sectie_id' => $ouder->sectie_id, 'ouder_id' => $ouder->id, 'naam' => mb_substr($w->naam, 0, 80), 'toegang' => 'wedstrijd',
                'wedstrijd_id' => $w->id, 'leden_posten' => 1, 'volgorde' => $s ? (int) $s->format('Ymd') : 0,
                'beschrijving' => $s ? 'Wedstrijd op ' . datum($s, 'l j F Y') : '']);
        }
    }
    // Deelnemers: wie interesse toonde, meedoet of ingeschreven is, komt erbij en blijft erin.
    $k = t('com_kanalen');
    db()->query("INSERT IGNORE INTO " . t('com_wedstrijdzwemmers') . " (kanaal_id, zwemmer_id)
        SELECT k.id, d.zwemmer_id FROM $k k JOIN " . t('deelnames') . " d ON d.wedstrijd_id=k.wedstrijd_id
        WHERE k.toegang='wedstrijd' AND k.gearchiveerd=0 AND d.keuze IN ('interesse','doet_mee')
        UNION SELECT k.id, i.zwemmer_id FROM $k k JOIN " . t('sessies') . " s ON s.wedstrijd_id=k.wedstrijd_id JOIN " . t('nummers')
        . " n ON n.sessie_id=s.id JOIN " . t('inschrijvingen') . " i ON i.nummer_id=n.id WHERE k.toegang='wedstrijd' AND k.gearchiveerd=0");
}

/** Wedstrijdthreads waarvan de wedstrijd een jaar voorbij is: archief (enkel lezen). Geeft het aantal. */
function com_archiveer_oude_wedstrijden(): int
{
    $grens = (int) vandaag()->modify('-' . COM_WEDSTRIJD_ARCHIEF_DAGEN . ' days')->format('Ymd');
    $n = 0;
    foreach (com_kanalen() as $k) {
        if ($k->toegang === 'wedstrijd' && !(int) $k->gearchiveerd) {
            $w = wedstrijd((int) $k->wedstrijd_id);
            $s = $w ? startdatum($w) : null;
            if (!$w || ($s && (int) $s->format('Ymd') < $grens)) {
                db()->update(t('com_kanalen'), ['gearchiveerd' => 1], ['id' => $k->id]);
                $n++;
            }
        }
    }
    com_cache(true);
    return $n;
}

// ---------- berichten ----------

function com_bericht(int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('com_berichten') . " WHERE id=%d", $id)) ?: null;
}

function com_naam($u): string
{
    $u = gebruiker($u);
    return $u ? (volle_naam($u) ?: $u->display_name) : 'Verwijderd account';
}

/**
 * Wie je met @ kan vermelden in dit kanaal: wie er al schreef, de trainers en het bestuur die het zien, en voor een
 * ploeg of wedstrijd de leden. Niet iedereen van de club: zo krijgt een lid geen lijst van alle leden te zien.
 */
function com_vermeldbaar(object $k): array
{
    static $c = [];
    if (isset($c[$k->id])) {
        return $c[$k->id];
    }
    $e = com_effectief($k);
    $ids = array_map('intval', db()->get_col(db()->prepare("SELECT DISTINCT user_id FROM " . t('com_berichten') . " WHERE kanaal_id=%d", $k->id)));
    if ($e->toegang === 'ploeg') {
        $ids = [...$ids, ...ploeg_accounts((int) $e->ploeg_id)];
    } elseif ($e->toegang === 'wedstrijd') {
        $zids = db()->prepare("SELECT zwemmer_id FROM " . t('com_wedstrijdzwemmers') . " WHERE kanaal_id=%d", $e->id);
        $ids = [...$ids, ...array_map('intval', db()->get_col("SELECT account_id FROM " . t('zwemmers') . " WHERE id IN ($zids) UNION SELECT user_id FROM "
            . t('zwemmer_accounts') . " WHERE zwemmer_id IN ($zids)"))];
    }
    foreach (get_users(['fields' => 'ID']) as $id) {
        if (heeft_rol([...TRAINERS, SECRETARIS, BESTUURDER, BEHEERDER], (int) $id)) {
            $ids[] = (int) $id;
        }
    }
    $uit = [];
    foreach (array_unique($ids) as $id) {
        if (get_userdata($id) && com_ziet($k, $id)) {
            $uit[$id] = com_naam($id);
        }
    }
    asort($uit, SORT_NATURAL | SORT_FLAG_CASE);
    return $c[$k->id] = $uit;
}

/** Wie in de tekst vermeld wordt (@Voornaam Naam), uit de lijst van com_vermeldbaar(). */
function com_vermeldingen_in(string $tekst, object $k): array
{
    $uit = [];
    $namen = com_vermeldbaar($k);
    uasort($namen, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // langste naam eerst
    foreach ($namen as $id => $naam) {
        if ($naam !== '' && preg_match('/@' . preg_quote($naam, '/') . '(?![\p{L}\p{N}])/iu', $tekst)) {
            $uit[] = (int) $id;
            $tekst = preg_replace('/@' . preg_quote($naam, '/') . '/iu', '', $tekst);
        }
    }
    return $uit;
}

function com_bewaar_vermeldingen(int $bericht_id, array $ids): void
{
    db()->delete(t('com_vermeldingen'), ['bericht_id' => $bericht_id]);
    foreach (array_unique($ids) as $id) {
        db()->insert(t('com_vermeldingen'), ['bericht_id' => $bericht_id, 'user_id' => $id]);
    }
}

/** Tekst van een bericht als HTML: veilig, met links, vermeldingen en regeleinden. */
function com_opmaak(string $tekst, array $vermeld = []): string
{
    $h = esc_html($tekst);
    $h = preg_replace_callback('~\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]~i', fn($m) => '<a href="' . esc_url(html_entity_decode($m[0])) . '" target="_blank" rel="noopener nofollow">' . $m[0] . '</a>', $h);
    foreach ($vermeld as $naam) {
        $h = preg_replace('/@' . preg_quote(esc_html($naam), '/') . '/iu', '<span class="com-vermeld">$0</span>', $h);
    }
    return nl2br($h);
}

/** Namen van de vermelde accounts per bericht (voor de opmaak). */
function com_vermeld_namen(array $bericht_ids): array
{
    $uit = [];
    if ($bericht_ids) {
        foreach (db()->get_results("SELECT bericht_id, user_id FROM " . t('com_vermeldingen') . " WHERE bericht_id IN (" . in_lijst($bericht_ids) . ")") as $r) {
            $uit[(int) $r->bericht_id][] = com_naam((int) $r->user_id);
        }
    }
    return $uit;
}

/** Plaatst een bericht en stuurt de meldingen. $bijlage = ['bestand', 'naam', 'type'] of null. */
function com_plaats(object $k, int $uid, string $tekst, ?array $bijlage = null): int
{
    $tekst = mb_substr(trim($tekst), 0, 4000);
    if ($tekst === '' && !$bijlage) {
        throw new \RuntimeException('Schrijf eerst een bericht.');
    }
    if (!com_mag_posten($k, $uid)) {
        throw new \RuntimeException((int) $k->gearchiveerd ? 'Dit kanaal is gearchiveerd.' : 'In dit kanaal posten enkel trainers en bestuur.');
    }
    db()->insert(t('com_berichten'), ['kanaal_id' => $k->id, 'user_id' => $uid, 'tekst' => $tekst, 'bijlage' => $bijlage[0] ?? '',
        'bijlage_naam' => $bijlage[1] ?? '', 'bijlage_type' => $bijlage[2] ?? '', 'aangemaakt' => nu_sql()]);
    $id = (int) db()->insert_id;
    db()->update(t('com_kanalen'), ['laatste_id' => $id, 'laatste_op' => nu_sql()], ['id' => $k->id]);
    com_zet_status((int) $k->id, $uid, ['gelezen_tot' => $id]);
    $vermeld = array_values(array_diff(com_vermeldingen_in($tekst, $k), [$uid]));
    com_bewaar_vermeldingen($id, $vermeld);
    com_cache(true);
    com_meld_bericht(com_kanaal((int) $k->id), $uid, $id, $tekst !== '' ? $tekst : '📎 ' . ($bijlage[1] ?? 'bijlage'), $vermeld);
    return $id;
}

/** Accounts met minstens één toestel voor pushmeldingen. */
function com_push_accounts(): array
{
    return array_map('intval', db()->get_col("SELECT DISTINCT user_id FROM " . t('push')));
}

function com_meld_bericht(object $k, int $uid, int $bericht_id, string $tekst, array $vermeld): void
{
    $van = com_naam($uid);
    $kort = mb_strimwidth(preg_replace('/\s+/', ' ', $tekst), 0, 140, '…');
    $url = app_url("berichten/kanaal/{$k->id}/") . "#b$bericht_id";
    $tag = ['tag' => "khzs-kanaal-{$k->id}"];
    $vermeld_ok = array_values(array_filter($vermeld, fn($v) => voorkeur($v, 'push_com_vermelding') && com_ziet($k, $v)));
    stuur($vermeld_ok, "$van vermeldde je in " . com_volle_naam($k), $kort, $url, true, $tag);
    $andere = [];
    foreach (com_push_accounts() as $o) {
        if ($o !== $uid && !in_array($o, $vermeld_ok, true) && voorkeur($o, 'push_com_bericht') && com_ziet($k, $o) && com_meldingen_aan($k, $o)) {
            $andere[] = $o;
        }
    }
    stuur($andere, com_volle_naam($k), "$van: $kort", $url, true, $tag);
}

/**
 * Ongelezen berichten (van anderen, niet verwijderd) per kanaal voor dit account. In een kanaal dat het account nog
 * nooit opende, tellen enkel berichten van na de registratie: een nieuw lid begint niet met honderden ongelezen.
 */
function com_ongelezen(int $uid): array
{
    $sinds = get_date_from_gmt((string) (get_userdata($uid)->user_registered ?? '2000-01-01 00:00:00'));
    $rijen = db()->get_results(db()->prepare("SELECT b.kanaal_id, COUNT(*) AS n FROM " . t('com_berichten') . " b LEFT JOIN " . t('com_status')
        . " s ON s.kanaal_id=b.kanaal_id AND s.user_id=%d WHERE b.id>COALESCE(s.gelezen_tot, 0) AND (s.id IS NOT NULL OR b.aangemaakt>=%s)
        AND b.user_id<>%d AND b.verwijderd IS NULL GROUP BY b.kanaal_id", $uid, $sinds, $uid));
    return array_map('intval', array_column($rijen, 'n', 'kanaal_id'));
}

/** Het getal bij Berichten in het menu: ongelezen berichten in kanalen met meldingen aan, plus nieuwe aankondigingen. */
function com_menu_badge(): int
{
    $uid = get_current_user_id();
    if (!$uid) {
        return 0;
    }
    $n = 0;
    foreach (com_ongelezen($uid) as $kid => $aantal) {
        $k = com_kanaal((int) $kid);
        if ($k && com_ziet($k, $uid) && com_meldingen_aan($k, $uid)) {
            $n += $aantal;
        }
    }
    $gezien = (int) get_user_meta($uid, 'khzs_com_aank_gezien', true);
    foreach (com_actieve_aankondigingen($uid) as $a) {
        $n += (int) $a->id > $gezien && (int) $a->door_id !== $uid ? 1 : 0;
    }
    return $n;
}

// ---------- bijlagen ----------

/** Bewaart een opgeladen foto of pdf in de privémap. Geeft ['bestand', 'naam', 'type'] of een fout. */
function com_bewaar_bijlage(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new \RuntimeException('Het opladen van de bijlage lukte niet.');
    }
    if ($f['size'] > COM_BIJLAGE_MAX) {
        throw new \RuntimeException('De bijlage is te groot (maximaal 5 MB).');
    }
    $kop = (string) file_get_contents($f['tmp_name'], false, null, 0, 5);
    $info = @getimagesize($f['tmp_name']);
    $type = match (true) {
        str_starts_with($kop, '%PDF') => 'pdf',
        $info && $info[2] === IMAGETYPE_JPEG => 'jpg',
        $info && $info[2] === IMAGETYPE_PNG => 'png',
        $info && defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP => 'webp',
        $info && $info[2] === IMAGETYPE_GIF => 'gif',
        default => throw new \RuntimeException('Enkel foto\'s (jpg, png, webp, gif) en pdf\'s.'),
    };
    com_bijlagen_map();
    $rel = 'communicatie/' . gmdate('Ym') . '-' . wp_generate_password(20, false) . ".$type";
    $doel = prive_map() . "/$rel";
    if (!move_uploaded_file($f['tmp_name'], $doel)) {
        throw new \RuntimeException('Het bewaren van de bijlage lukte niet.');
    }
    if ($type !== 'pdf' && $type !== 'gif' && $info && max($info[0], $info[1]) > 1600) {
        $ed = wp_get_image_editor($doel);
        if (!is_wp_error($ed)) {
            $ed->resize(1600, 1600);
            $ed->save($doel);
        }
    }
    $naam = mb_substr(sanitize_file_name(pathinfo((string) $f['name'], PATHINFO_FILENAME)) ?: 'bijlage', 0, 140) . ".$type";
    return [$rel, $naam, $type];
}

// ---------- aankondigingen ----------

function com_aankondiging(int $id): ?object
{
    return db()->get_row(db()->prepare("SELECT * FROM " . t('com_aankondigingen') . " WHERE id=%d", $id)) ?: null;
}

function com_ziet_aankondiging(object $a, int $uid): bool
{
    if ($uid <= 0) {
        return false;
    }
    if (!(int) $a->kanaal_id || com_is_bestuur($uid) || (int) $a->door_id === $uid) {
        return true;
    }
    $k = com_kanaal((int) $a->kanaal_id);
    return $k && com_ziet($k, $uid);
}

/** Aankondigingen die nog lopen (tot vandaag inbegrepen), dringend eerst. Met $kanaal_id enkel die voor dat kanaal. */
function com_actieve_aankondigingen(int $uid, ?int $kanaal_id = null): array
{
    $sql = db()->prepare("SELECT * FROM " . t('com_aankondigingen') . " WHERE (tot IS NULL OR tot>=%s)", vandaag()->format('Y-m-d'));
    if ($kanaal_id !== null) {
        $sql .= db()->prepare(" AND kanaal_id=%d", $kanaal_id);
    }
    $lijst = db()->get_results("$sql ORDER BY soort='dringend' DESC, id DESC");
    return array_values(array_filter($lijst, fn($a) => com_ziet_aankondiging($a, $uid)));
}

/** Aankondigingen maken: het bestuur voor iedereen of elk kanaal, een trainer voor de kanalen die hij of zij beheert. */
function com_aankondiging_kanalen(int $uid): array
{
    $uit = com_is_bestuur($uid) ? [0 => 'Iedereen'] : [];
    foreach (com_kanalen() as $k) {
        if (!(int) $k->gearchiveerd && com_mag_beheren($k, $uid)) {
            $uit[(int) $k->id] = com_volle_naam($k);
        }
    }
    return $uit;
}

function com_mag_aankondiging_wijzigen(object $a, int $uid): bool
{
    return com_is_bestuur($uid) || (int) $a->door_id === $uid;
}

/** De lijst met namen ziet het bestuur en wie de vraag maakte; de rest ziet enkel de totalen. */
function com_ziet_rsvp_lijst(object $a, int $uid): bool
{
    return com_mag_aankondiging_wijzigen($a, $uid);
}

function com_rsvp_lijst(int $aid): array
{
    return db()->get_results(db()->prepare("SELECT * FROM " . t('com_rsvp') . " WHERE aankondiging_id=%d ORDER BY FIELD(antwoord,'ja','misschien','nee'), gewijzigd", $aid));
}

/** Totalen: per antwoord het aantal antwoorden en personen. */
function com_rsvp_totalen(int $aid): array
{
    $t = [];
    foreach (COM_RSVP as $k => $l) {
        $t[$k] = ['antwoorden' => 0, 'personen' => 0];
    }
    foreach (com_rsvp_lijst($aid) as $r) {
        $t[$r->antwoord]['antwoorden']++;
        $t[$r->antwoord]['personen'] += (int) $r->personen;
    }
    return $t;
}

function com_meld_aankondiging(object $a): void
{
    $voorkeur = $a->soort === 'hulp' ? 'push_com_hulpvraag' : 'push_com_aankondiging';
    $ontvangers = array_values(array_filter(com_push_accounts(), fn($o) => $o !== (int) $a->door_id && voorkeur($o, $voorkeur) && com_ziet_aankondiging($a, $o)));
    stuur($ontvangers, COM_AANK_ICOON[$a->soort] . ' ' . COM_AANK_SOORT[$a->soort] . ': ' . $a->titel,
        mb_strimwidth(preg_replace('/\s+/', ' ', $a->tekst), 0, 140, '…'), app_url("berichten/aankondiging/{$a->id}/"));
}

// ---------- weergave ----------

/** Korte tijd voor lijsten: vandaag 14:05, gisteren, za, 3 nov. */
function com_wanneer(?string $op): string
{
    $d = $op ? date_create_immutable($op, wp_timezone()) : null;
    if (!$d) {
        return '';
    }
    $dagen = (int) vandaag()->diff($d->setTime(0, 0))->format('%r%a');
    return match (true) {
        $dagen === 0 => $d->format('H:i'),
        $dagen === -1 => 'gisteren',
        $dagen > -7 && $dagen < 0 => datum($d, 'D'),
        default => datum($d, 'j M' . ($d->format('Y') !== vandaag()->format('Y') ? ' Y' : '')),
    };
}

/** Scheiding tussen de dagen in een chat: Vandaag, Gisteren, zaterdag 14 november. */
function com_dag_label(string $op): string
{
    $d = date_create_immutable(substr($op, 0, 10), wp_timezone());
    $dagen = (int) vandaag()->diff($d)->format('%r%a');
    return match ($dagen) {
        0 => 'Vandaag',
        -1 => 'Gisteren',
        default => datum($d, 'l j F' . ($d->format('Y') !== vandaag()->format('Y') ? ' Y' : '')),
    };
}

/** Rol naast de naam in een chat (trainer, bestuur…), zodat ouders zien wie er schrijft. Leeg voor zwemmers en ouders. */
function com_rol_label(int $uid): string
{
    static $c = [];
    if (!isset($c[$uid])) {
        $r = array_values(array_diff(rollen_van($uid), GEZIN))[0] ?? '';
        $c[$uid] = $r === '' ? '' : (ROLLEN[$r] ?? '');
    }
    return $c[$uid];
}

/** Initialen voor het rondje naast een bericht. */
function com_initialen(string $naam): string
{
    $delen = preg_split('/\s+/', trim($naam)) ?: [];
    $i = mb_substr($delen[0] ?? '?', 0, 1) . (count($delen) > 1 ? mb_substr(end($delen), 0, 1) : '');
    return mb_strtoupper($i);
}

/** Icoon van een kanaal in de lijsten. */
function com_icoon(object $k): string
{
    return match (true) {
        $k->toegang === 'wedstrijd' => '🏁',
        $k->toegang === 'staf' => '🔒',
        (bool) (int) $k->ouder_id => '↳',
        $k->toegang === 'ploeg' => '👥',
        default => '#',
    };
}
