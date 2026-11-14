<?php
/** Databasetabellen van de kern (eigen tabellen naast die van WordPress), privé-map voor bestanden, hulpfuncties. */

namespace KHZS;

defined('ABSPATH') || exit;

/** Alle tabellen van de kern en de actieve modules (voor backup). Modules voegen de hunne toe via khzs_tabellen. */
function alle_tabellen(): array
{
    return apply_filters('khzs_tabellen', ['zwemmers', 'zwemmer_accounts', 'push', 'rolaanvragen', 'ploegen', 'ploeg_trainers', 'ploegleden']);
}

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
  koppelcode varchar(8) NOT NULL DEFAULT '',
  koppelcode_tot datetime NULL,
  PRIMARY KEY  (id),
  KEY account_id (account_id),
  KEY koppelcode (koppelcode)
) $c;";
    // Extra accounts van een zwemmer (bv. de andere ouder), gekoppeld met een koppelcode.
    $sql[] = "CREATE TABLE " . t('zwemmer_accounts') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zwemmer_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (zwemmer_id,user_id),
  KEY user_id (user_id)
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
    // Rolaanvragen: een lid vraagt een rol aan, een bestuurder/sportsecretaris/beheerder beslist (includes/rollen.php).
    $sql[] = "CREATE TABLE " . t('rolaanvragen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  rol varchar(20) NOT NULL,
  motivatie varchar(300) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  toegekende_rol varchar(20) NOT NULL DEFAULT '',
  reden varchar(300) NOT NULL DEFAULT '',
  behandeld_door bigint(20) unsigned NULL,
  behandeld_op datetime NULL,
  gelezen tinyint(1) NOT NULL DEFAULT 0,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status (status)
) $c;";
    // Ploegen (A-groep, B-groep, masters…), hun trainers en de zwemmers erin (includes/ploegen.php).
    $sql[] = "CREATE TABLE " . t('ploegen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  naam varchar(60) NOT NULL,
  beschrijving varchar(200) NOT NULL DEFAULT '',
  actief tinyint(1) NOT NULL DEFAULT 1,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY naam (naam)
) $c;";
    $sql[] = "CREATE TABLE " . t('ploeg_trainers') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ploeg_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (ploeg_id,user_id),
  KEY user_id (user_id)
) $c;";
    $sql[] = "CREATE TABLE " . t('ploegleden') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  zwemmer_id bigint(20) unsigned NOT NULL,
  ploeg_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  aangevraagd_door bigint(20) unsigned NULL,
  reden varchar(300) NOT NULL DEFAULT '',
  behandeld_door bigint(20) unsigned NULL,
  behandeld_op datetime NULL,
  gelezen tinyint(1) NOT NULL DEFAULT 0,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (zwemmer_id,ploeg_id),
  KEY ploeg_id (ploeg_id)
) $c;";
    // Tabellen van de modules (ook van uitgeschakelde, zodat hun gegevens bewaard blijven).
    foreach (modules() as $id => $m) {
        $f = __NAMESPACE__ . '\\schema_' . $id;
        if (function_exists($f)) {
            $sql = array_merge($sql, $f($c));
        }
    }
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

function verwijder_zwemmer(int $id): void
{
    do_action('khzs_zwemmer_verwijderd', $id); // modules ruimen hun gegevens van deze zwemmer op
    db()->delete(t('zwemmer_accounts'), ['zwemmer_id' => $id]);
    db()->delete(t('ploegleden'), ['zwemmer_id' => $id]);
    db()->delete(t('zwemmers'), ['id' => $id]);
}

/** WordPress-gebruiker verwijderd: gedeelde zwemmers gaan naar het andere account, de rest verdwijnt mee. */
function verwijder_gebruiker_data(int $user_id): void
{
    foreach (db()->get_col(db()->prepare("SELECT id FROM " . t('zwemmers') . " WHERE account_id=%d", $user_id)) as $zid) {
        $ander = db()->get_var(db()->prepare("SELECT user_id FROM " . t('zwemmer_accounts') . " WHERE zwemmer_id=%d AND user_id<>%d ORDER BY id LIMIT 1", $zid, $user_id));
        if ($ander) {
            db()->update(t('zwemmers'), ['account_id' => (int) $ander], ['id' => $zid]);
            db()->delete(t('zwemmer_accounts'), ['zwemmer_id' => $zid, 'user_id' => (int) $ander]);
        } else {
            verwijder_zwemmer((int) $zid);
        }
    }
    db()->delete(t('zwemmer_accounts'), ['user_id' => $user_id]);
    db()->delete(t('push'), ['user_id' => $user_id]);
    db()->delete(t('rolaanvragen'), ['user_id' => $user_id]);
    db()->delete(t('ploeg_trainers'), ['user_id' => $user_id]);
    do_action('khzs_gebruiker_verwijderd', $user_id);
}

/** Tijdelijk bestand (wp_tempnam zit enkel in wp-admin). */
function tijdelijk(): string
{
    return tempnam(get_temp_dir(), 'khzs');
}
