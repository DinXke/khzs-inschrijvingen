<?php
/** Databasetabellen (eigen tabellen naast die van WordPress) en de privé-map voor bestanden. */

namespace KHZS;

defined('ABSPATH') || exit;

const TABELLEN = [
    'zwemmers', 'tijden', 'wedstrijden', 'sessies', 'nummers', 'leeftijdsgroepen', 'limietlijsten', 'limieten',
    'inschrijvingen', 'meldingen', 'push', 'herinneringen', 'deelnames', 'bijlagen',
];

/** Volledige tabelnaam, bv. t('zwemmers') → wp_khzs_zwemmers. */
function t(string $naam): string
{
    global $wpdb;
    return $wpdb->prefix . 'khzs_' . $naam;
}

function db(): \wpdb
{
    global $wpdb;
    return $wpdb;
}

function installeer_db(): void
{
    $c = db()->get_charset_collate();
    $sql = [];
    $sql[] = "CREATE TABLE " . t('zwemmers') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  account_id bigint(20) unsigned NOT NULL,
  voornaam varchar(60) NOT NULL,
  naam varchar(80) NOT NULL,
  geboortedatum date NOT NULL,
  geslacht char(1) NOT NULL,
  licentie varchar(30) NOT NULL DEFAULT '',
  swimrankings_id varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY account_id (account_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('tijden') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zwemmer_id bigint(20) unsigned NOT NULL,
  afstand int(10) unsigned NOT NULL,
  slag varchar(10) NOT NULL,
  baan varchar(3) NOT NULL,
  tijd int(10) unsigned NOT NULL,
  datum date NOT NULL,
  wedstrijd varchar(120) NOT NULL DEFAULT '',
  bron varchar(20) NOT NULL DEFAULT 'manueel',
  PRIMARY KEY  (id),
  KEY zwemmer_id (zwemmer_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('wedstrijden') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  naam varchar(200) NOT NULL,
  stad varchar(100) NOT NULL DEFAULT '',
  zwembad varchar(150) NOT NULL DEFAULT '',
  baan varchar(3) NOT NULL,
  deadline date NOT NULL,
  agedate date NULL,
  agedate_type varchar(10) NOT NULL DEFAULT 'YEAR',
  kwalificatie_van date NULL,
  kwalificatie_tot date NULL,
  lenex_bestand varchar(255) NOT NULL DEFAULT '',
  dagen_secretaris int(10) unsigned NOT NULL DEFAULT 3,
  dagen_trainer int(10) unsigned NOT NULL DEFAULT 2,
  status varchar(20) NOT NULL DEFAULT 'concept',
  info text NOT NULL,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;";
    $sql[] = "CREATE TABLE " . t('sessies') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wedstrijd_id bigint(20) unsigned NOT NULL,
  nummer int(10) unsigned NOT NULL,
  datum date NOT NULL,
  starttijd time NULL,
  opwarmen time NULL,
  PRIMARY KEY  (id),
  KEY wedstrijd_id (wedstrijd_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('nummers') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sessie_id bigint(20) unsigned NOT NULL,
  eventid varchar(20) NOT NULL,
  nummer int(10) unsigned NOT NULL,
  volgorde int(10) unsigned NOT NULL DEFAULT 0,
  geslacht char(1) NOT NULL,
  afstand int(10) unsigned NOT NULL,
  slag varchar(10) NOT NULL,
  naam varchar(100) NOT NULL DEFAULT '',
  aflossing int(10) unsigned NOT NULL DEFAULT 1,
  ronde varchar(5) NOT NULL DEFAULT 'TIM',
  inschrijfbaar tinyint(1) NOT NULL DEFAULT 1,
  kost_cent int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY sessie_id (sessie_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('leeftijdsgroepen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  nummer_id bigint(20) unsigned NOT NULL,
  agemin int(11) NOT NULL DEFAULT -1,
  agemax int(11) NOT NULL DEFAULT -1,
  PRIMARY KEY  (id),
  KEY nummer_id (nummer_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('limietlijsten') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wedstrijd_id bigint(20) unsigned NOT NULL,
  naam varchar(100) NOT NULL,
  code varchar(20) NOT NULL DEFAULT '',
  soort varchar(20) NOT NULL DEFAULT 'DEFAULT',
  baan varchar(3) NOT NULL,
  geslacht char(1) NOT NULL,
  agemin int(11) NOT NULL DEFAULT -1,
  agemax int(11) NOT NULL DEFAULT -1,
  gekozen tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY wedstrijd_id (wedstrijd_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('limieten') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lijst_id bigint(20) unsigned NOT NULL,
  afstand int(10) unsigned NOT NULL,
  slag varchar(10) NOT NULL,
  tijd int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY lijst_id (lijst_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('inschrijvingen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zwemmer_id bigint(20) unsigned NOT NULL,
  nummer_id bigint(20) unsigned NOT NULL,
  inschrijftijd int(10) unsigned NULL,
  inschrijfbaan varchar(3) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'ingediend',
  opmerking varchar(200) NOT NULL DEFAULT '',
  aangemaakt datetime NOT NULL,
  door_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY zwemmer_nummer (zwemmer_id,nummer_id),
  KEY nummer_id (nummer_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('meldingen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zwemmer_id bigint(20) unsigned NOT NULL,
  wedstrijd_id bigint(20) unsigned NOT NULL,
  door_id bigint(20) unsigned NULL,
  tekst text NOT NULL,
  gelezen tinyint(1) NOT NULL DEFAULT 0,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY zwemmer_id (zwemmer_id),
  KEY wedstrijd_id (wedstrijd_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('push') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  endpoint varchar(1000) NOT NULL,
  endpoint_hash char(64) NOT NULL,
  p256dh varchar(200) NOT NULL,
  auth varchar(100) NOT NULL,
  toestel varchar(200) NOT NULL DEFAULT '',
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('herinneringen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wedstrijd_id bigint(20) unsigned NOT NULL,
  soort varchar(30) NOT NULL,
  datum date NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (wedstrijd_id,soort,datum)
) $c;";
    $sql[] = "CREATE TABLE " . t('deelnames') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zwemmer_id bigint(20) unsigned NOT NULL,
  wedstrijd_id bigint(20) unsigned NOT NULL,
  keuze varchar(20) NOT NULL,
  geweigerd tinyint(1) NOT NULL DEFAULT 0,
  weigerreden varchar(200) NOT NULL DEFAULT '',
  gefinaliseerd tinyint(1) NOT NULL DEFAULT 0,
  gefinaliseerd_door bigint(20) unsigned NULL,
  gefinaliseerd_op datetime NULL,
  ingegeven tinyint(1) NOT NULL DEFAULT 0,
  gewijzigd_na_ingave tinyint(1) NOT NULL DEFAULT 0,
  gewijzigd datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY zwemmer_wedstrijd (zwemmer_id,wedstrijd_id),
  KEY wedstrijd_id (wedstrijd_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('bijlagen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wedstrijd_id bigint(20) unsigned NOT NULL,
  bestand varchar(255) NOT NULL,
  naam varchar(150) NOT NULL,
  door_id bigint(20) unsigned NULL,
  toegevoegd datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY wedstrijd_id (wedstrijd_id)
) $c;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('khzs_db_versie', DB_VERSIE);
}

/**
 * Map voor Lenex-bestanden, bijlagen en backups. Staat in uploads/ onder een willekeurige naam,
 * met .htaccess en index.php; bestanden worden enkel via de app (met controle op aanmelding) geserveerd.
 */
function prive_map(string $sub = ''): string
{
    $naam = get_option('khzs_prive_map');
    if (!$naam) {
        $naam = 'khzs-prive-' . wp_generate_password(16, false);
        update_option('khzs_prive_map', $naam, false);
    }
    $basis = trailingslashit(wp_upload_dir(null, false)['basedir']) . $naam;
    if (!is_dir($basis)) {
        wp_mkdir_p($basis);
        file_put_contents("$basis/.htaccess", "Require all denied\nDeny from all\n");
        file_put_contents("$basis/index.php", "<?php // stil\n");
    }
    $pad = $sub ? "$basis/$sub" : $basis;
    if (!is_dir($pad)) {
        wp_mkdir_p($pad);
    }
    return $pad;
}

function nu_sql(): string
{
    return current_time('mysql');
}

// ---------- verwijderen met alles wat eraan hangt (er zijn geen foreign keys) ----------

function in_lijst(array $ids): string
{
    return implode(',', array_map('intval', $ids)) ?: '0';
}

function verwijder_nummers(array $ids): void
{
    if (!$ids) {
        return;
    }
    $l = in_lijst($ids);
    db()->query("DELETE FROM " . t('leeftijdsgroepen') . " WHERE nummer_id IN ($l)");
    db()->query("DELETE FROM " . t('inschrijvingen') . " WHERE nummer_id IN ($l)");
    db()->query("DELETE FROM " . t('nummers') . " WHERE id IN ($l)");
}

function verwijder_limietlijsten(int $wedstrijd_id): void
{
    $ids = db()->get_col(db()->prepare("SELECT id FROM " . t('limietlijsten') . " WHERE wedstrijd_id=%d", $wedstrijd_id));
    if ($ids) {
        db()->query("DELETE FROM " . t('limieten') . " WHERE lijst_id IN (" . in_lijst($ids) . ")");
        db()->query("DELETE FROM " . t('limietlijsten') . " WHERE id IN (" . in_lijst($ids) . ")");
    }
}

function verwijder_wedstrijd(int $id): void
{
    $sessies = db()->get_col(db()->prepare("SELECT id FROM " . t('sessies') . " WHERE wedstrijd_id=%d", $id));
    if ($sessies) {
        verwijder_nummers(db()->get_col("SELECT id FROM " . t('nummers') . " WHERE sessie_id IN (" . in_lijst($sessies) . ")"));
    }
    foreach (db()->get_results(db()->prepare("SELECT * FROM " . t('bijlagen') . " WHERE wedstrijd_id=%d", $id)) as $b) {
        @unlink(prive_map() . '/' . $b->bestand);
    }
    $w = db()->get_row(db()->prepare("SELECT lenex_bestand FROM " . t('wedstrijden') . " WHERE id=%d", $id));
    if ($w && $w->lenex_bestand) {
        @unlink(prive_map() . '/' . $w->lenex_bestand);
    }
    verwijder_limietlijsten($id);
    foreach (['sessies', 'meldingen', 'deelnames', 'bijlagen', 'herinneringen'] as $tab) {
        db()->delete(t($tab), ['wedstrijd_id' => $id]);
    }
    db()->delete(t('wedstrijden'), ['id' => $id]);
}

function verwijder_zwemmer(int $id): void
{
    foreach (['tijden', 'inschrijvingen', 'meldingen', 'deelnames'] as $tab) {
        db()->delete(t($tab), ['zwemmer_id' => $id]);
    }
    db()->delete(t('zwemmers'), ['id' => $id]);
}

/** WordPress-gebruiker verwijderd: zijn zwemmers en toestellen gaan mee. */
function verwijder_gebruiker_data(int $user_id): void
{
    foreach (db()->get_col(db()->prepare("SELECT id FROM " . t('zwemmers') . " WHERE account_id=%d", $user_id)) as $zid) {
        verwijder_zwemmer((int) $zid);
    }
    db()->delete(t('push'), ['user_id' => $user_id]);
}

/** Tijdelijk bestand (wp_tempnam zit enkel in wp-admin). */
function tijdelijk(): string
{
    return tempnam(get_temp_dir(), 'khzs');
}
