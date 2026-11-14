<?php
/** Module communicatie: tabellen, opkuis en AVG. */

namespace KHZS;

defined('ABSPATH') || exit;

add_filter('khzs_tabellen', fn($t) => array_merge($t, ['com_secties', 'com_kanalen', 'com_berichten', 'com_vermeldingen', 'com_status',
    'com_wedstrijdzwemmers', 'com_aankondigingen', 'com_rsvp']));

/** Versie van de tabellen van deze module; los van DB_VERSIE van de kern (zoals TR_DB_VERSIE). */
const COM_DB_VERSIE = '2';
/** Berichten en aankondigingen blijven 2 jaar bewaard (vastgepinde berichten blijven). */
const COM_BEWAAR_DAGEN = 730;
/** Een wedstrijdthread wordt een jaar na de wedstrijd gearchiveerd: enkel nog lezen. */
const COM_WEDSTRIJD_ARCHIEF_DAGEN = 365;

add_action('plugins_loaded', function () {
    if (get_option('khzs_com_db_versie') !== COM_DB_VERSIE) {
        installeer_db();
        com_migreer();
        update_option('khzs_com_db_versie', COM_DB_VERSIE);
    }
}, 11);

/** Versie 2: een aankondiging kan voor meerdere kanalen zijn (kolom kanalen in plaats van kanaal_id). */
function com_migreer(): void
{
    db()->query("UPDATE " . t('com_aankondigingen') . " SET kanalen=kanaal_id, kanaal_id=0 WHERE kanaal_id>0 AND kanalen=''");
}

/** Map met de bijlagen van berichten (foto's en pdf's), in de privémap: enkel via de app te downloaden. */
function com_bijlagen_map(): string
{
    return prive_map('communicatie');
}

function com_wis_bijlage(?string $bestand): void
{
    if ($bestand && !str_contains($bestand, '..') && is_file(prive_map() . '/' . $bestand)) {
        @unlink(prive_map() . '/' . $bestand);
    }
}

add_action('khzs_gebruiker_verwijderd', function (int $uid) {
    // Berichten van het account verdwijnen mee (met hun bijlagen); aankondigingen van anderen blijven.
    foreach (db()->get_col(db()->prepare("SELECT bijlage FROM " . t('com_berichten') . " WHERE user_id=%d AND bijlage<>''", $uid)) as $b) {
        com_wis_bijlage($b);
    }
    db()->delete(t('com_berichten'), ['user_id' => $uid]);
    db()->delete(t('com_vermeldingen'), ['user_id' => $uid]);
    db()->delete(t('com_status'), ['user_id' => $uid]);
    db()->delete(t('com_rsvp'), ['user_id' => $uid]);
});

add_action('khzs_zwemmer_verwijderd', fn(int $zid) => db()->delete(t('com_wedstrijdzwemmers'), ['zwemmer_id' => $zid]));

add_filter('khzs_mijn_gegevens', function (array $d, int $uid) {
    $berichten = db()->get_results(db()->prepare("SELECT k.naam AS kanaal, b.tekst, b.bijlage_naam, b.aangemaakt, b.bewerkt FROM " . t('com_berichten')
        . " b JOIN " . t('com_kanalen') . " k ON k.id=b.kanaal_id WHERE b.user_id=%d AND b.verwijderd IS NULL ORDER BY b.id", $uid), ARRAY_A);
    $rsvp = db()->get_results(db()->prepare("SELECT a.titel, r.antwoord, r.personen, r.opmerking, r.gewijzigd FROM " . t('com_rsvp') . " r JOIN "
        . t('com_aankondigingen') . " a ON a.id=r.aankondiging_id WHERE r.user_id=%d ORDER BY r.id", $uid), ARRAY_A);
    $aank = db()->get_results(db()->prepare("SELECT soort, titel, tekst, aangemaakt FROM " . t('com_aankondigingen') . " WHERE door_id=%d ORDER BY id", $uid), ARRAY_A);
    if ($berichten || $rsvp || $aank) {
        $d['communicatie'] = ['berichten' => $berichten, 'aankondigingen' => $aank, 'antwoorden_op_hulpvragen' => $rsvp];
    }
    return $d;
}, 10, 2);

/** Dagelijkse opkuis (includes/avg.php): oude berichten en aankondigingen weg, oude wedstrijdthreads gearchiveerd. */
add_filter('khzs_opkuis_aantallen', function (array $n) {
    $grens = wp_date('Y-m-d H:i:s', time() - COM_BEWAAR_DAGEN * DAY_IN_SECONDS);
    $oud = db()->get_results(db()->prepare("SELECT id, bijlage FROM " . t('com_berichten') . " WHERE aangemaakt<%s AND vastgepind IS NULL", $grens));
    foreach ($oud as $b) {
        com_wis_bijlage($b->bijlage);
    }
    if ($oud) {
        $ids = in_lijst(array_column($oud, 'id'));
        db()->query("DELETE FROM " . t('com_vermeldingen') . " WHERE bericht_id IN ($ids)");
        db()->query("DELETE FROM " . t('com_berichten') . " WHERE id IN ($ids)");
    }
    $n['berichten'] = count($oud);
    $aank = db()->get_col(db()->prepare("SELECT id FROM " . t('com_aankondigingen') . " WHERE COALESCE(gewijzigd, aangemaakt)<%s", $grens));
    if ($aank) {
        db()->query("DELETE FROM " . t('com_rsvp') . " WHERE aankondiging_id IN (" . in_lijst($aank) . ")");
        db()->query("DELETE FROM " . t('com_aankondigingen') . " WHERE id IN (" . in_lijst($aank) . ")");
    }
    $n['aankondigingen'] = count($aank);
    if (module_actief('inschrijvingen') && function_exists(__NAMESPACE__ . '\\com_archiveer_oude_wedstrijden')) {
        $n['wedstrijdthreads_gearchiveerd'] = com_archiveer_oude_wedstrijden();
    }
    return $n;
});

function schema_communicatie(string $c): array
{
    return [
        // Grote secties zoals in Discord (Algemeen, Competitie, Zwemschool). soort: algemeen, competitie of zwemschool.
        "CREATE TABLE " . t('com_secties') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  naam varchar(60) NOT NULL,
  soort varchar(12) NOT NULL DEFAULT 'algemeen',
  volgorde int(10) NOT NULL DEFAULT 0,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;",
        // Kanalen (ouder_id 0) en threads (ouder_id = kanaal). toegang: iedereen, competitie, zwemschool, ploeg, wedstrijd,
        // staf, of erven (thread: zoals het kanaal). functie 'ploeg': het kanaal van een ploeg (naam en archief volgen de
        // ploeg); 'wedstrijden': hieronder komt per wedstrijd een thread.
        "CREATE TABLE " . t('com_kanalen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sectie_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ouder_id bigint(20) unsigned NOT NULL DEFAULT 0,
  naam varchar(80) NOT NULL,
  beschrijving varchar(200) NOT NULL DEFAULT '',
  toegang varchar(12) NOT NULL DEFAULT 'iedereen',
  ploeg_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wedstrijd_id bigint(20) unsigned NOT NULL DEFAULT 0,
  functie varchar(20) NOT NULL DEFAULT '',
  leden_posten tinyint(1) NOT NULL DEFAULT 1,
  gearchiveerd tinyint(1) NOT NULL DEFAULT 0,
  volgorde int(10) NOT NULL DEFAULT 0,
  laatste_id bigint(20) unsigned NOT NULL DEFAULT 0,
  laatste_op datetime NULL,
  door_id bigint(20) unsigned NULL,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY sectie_id (sectie_id),
  KEY ouder_id (ouder_id),
  KEY ploeg_id (ploeg_id),
  KEY wedstrijd_id (wedstrijd_id)
) $c;",
        "CREATE TABLE " . t('com_berichten') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kanaal_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  tekst text NOT NULL,
  bijlage varchar(255) NOT NULL DEFAULT '',
  bijlage_naam varchar(150) NOT NULL DEFAULT '',
  bijlage_type varchar(10) NOT NULL DEFAULT '',
  vastgepind datetime NULL,
  vastgepind_door bigint(20) unsigned NULL,
  bewerkt datetime NULL,
  verwijderd datetime NULL,
  verwijderd_door bigint(20) unsigned NULL,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kanaal_id (kanaal_id,id),
  KEY user_id (user_id)
) $c;",
        // Wie in een bericht vermeld wordt (@Voornaam Naam).
        "CREATE TABLE " . t('com_vermeldingen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  bericht_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (bericht_id,user_id),
  KEY user_id (user_id)
) $c;",
        // Per account en kanaal: gelezen tot welk bericht, en de meldingen (NULL = standaard, 1 = aan, 0 = gedempt).
        "CREATE TABLE " . t('com_status') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kanaal_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  gelezen_tot bigint(20) unsigned NOT NULL DEFAULT 0,
  meldingen tinyint(1) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (kanaal_id,user_id),
  KEY user_id (user_id)
) $c;",
        // Zwemmers die ooit deelnamen aan de wedstrijd van een thread (interesse, ik doe mee of ingeschreven). Ze blijven
        // erin, ook na 'Ik doe niet mee' of een weigering (beslissing van de eigenaar).
        "CREATE TABLE " . t('com_wedstrijdzwemmers') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kanaal_id bigint(20) unsigned NOT NULL,
  zwemmer_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (kanaal_id,zwemmer_id),
  KEY zwemmer_id (zwemmer_id)
) $c;",
        // Aankondigingen bovenaan. soort: info, dringend, aankondiging, hulp (vraag naar hulp, met RSVP).
        // kanalen: ids met komma's, bv. '3,7'; leeg = voor iedereen, anders voor wie minstens één van die kanalen ziet.
        // kanaal_id: enkel nog voor de overzetting van versie 1 (één kanaal). Zichtbaar tot en met 'tot' (leeg = tot iemand ze weghaalt).
        "CREATE TABLE " . t('com_aankondigingen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  soort varchar(12) NOT NULL DEFAULT 'info',
  titel varchar(120) NOT NULL,
  tekst text NOT NULL,
  kanaal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kanalen varchar(1000) NOT NULL DEFAULT '',
  wanneer varchar(80) NOT NULL DEFAULT '',
  nodig int(10) unsigned NOT NULL DEFAULT 0,
  tot date NULL,
  door_id bigint(20) unsigned NULL,
  aangemaakt datetime NOT NULL,
  gewijzigd datetime NULL,
  PRIMARY KEY  (id)
) $c;",
        // Antwoord op een vraag naar hulp: ja, nee of misschien, met hoeveel personen.
        "CREATE TABLE " . t('com_rsvp') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  aankondiging_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  antwoord varchar(10) NOT NULL,
  personen int(10) unsigned NOT NULL DEFAULT 1,
  opmerking varchar(200) NOT NULL DEFAULT '',
  gewijzigd datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (aankondiging_id,user_id)
) $c;",
    ];
}
