<?php
/** Module trainers: tabellen. */

namespace KHZS;

defined('ABSPATH') || exit;

add_filter('khzs_tabellen', fn($t) => array_merge($t, ['tr_momenten', 'tr_vast', 'tr_wijzigingen', 'tr_afgelast', 'tr_tarieven', 'tr_uitbetaald',
    'tr_bevestigingen', 'tr_log']));

/** Versie van de tabellen van deze module; los van DB_VERSIE van de kern, zodat de module zelf kan bijwerken. */
const TR_DB_VERSIE = '2';

add_action('plugins_loaded', function () {
    if (get_option('khzs_tr_db_versie') === TR_DB_VERSIE) {
        return;
    }
    // Bestaande installatie met planning: voorbije trainingen tellen mee zonder bevestiging (zoals vroeger).
    if (get_option('khzs_tr_bevestigen_vanaf', null) === null) {
        $heeft = db()->get_var("SHOW TABLES LIKE '" . esc_sql(t('tr_vast')) . "'") && (int) db()->get_var("SELECT COUNT(*) FROM " . t('tr_vast'));
        add_option('khzs_tr_bevestigen_vanaf', $heeft ? vandaag()->format('Y-m-d') : '', '', false);
    }
    installeer_db();
    update_option('khzs_tr_db_versie', TR_DB_VERSIE);
}, 11);

add_action('khzs_gebruiker_verwijderd', function (int $uid) {
    // Gepresteerde uren en uitbetalingen blijven (boekhouding); enkel toekomstige planning verdwijnt.
    $vandaag = vandaag()->format('Y-m-d');
    db()->query(db()->prepare("UPDATE " . t('tr_vast') . " SET tot=%s WHERE user_id=%d AND (tot IS NULL OR tot>%s)", $vandaag, $uid, $vandaag));
    db()->query(db()->prepare("DELETE FROM " . t('tr_wijzigingen') . " WHERE user_id=%d AND datum>%s", $uid, $vandaag));
    db()->query(db()->prepare("DELETE FROM " . t('tr_bevestigingen') . " WHERE user_id=%d AND datum>%s", $uid, $vandaag));
    db()->delete(t('tr_log'), ['user_id' => $uid]);
});

function schema_trainers(string $c): array
{
    return [
        "CREATE TABLE " . t('tr_momenten') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  naam varchar(100) NOT NULL,
  weekdag tinyint(1) unsigned NOT NULL,
  start time NOT NULL,
  einde time NOT NULL,
  locatie varchar(100) NOT NULL DEFAULT '',
  van date NOT NULL,
  tot date NULL,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;",
        "CREATE TABLE " . t('tr_vast') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  moment_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  van date NOT NULL,
  tot date NULL,
  PRIMARY KEY  (id),
  KEY moment_id (moment_id),
  KEY user_id (user_id)
) $c;",
        "CREATE TABLE " . t('tr_wijzigingen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  moment_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  soort varchar(12) NOT NULL,
  vervangt_id bigint(20) unsigned NULL,
  reden varchar(200) NOT NULL DEFAULT '',
  door_id bigint(20) unsigned NULL,
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (moment_id,datum,user_id),
  KEY datum (datum)
) $c;",
        "CREATE TABLE " . t('tr_afgelast') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  moment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  van date NOT NULL,
  tot date NOT NULL,
  reden varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY  (id)
) $c;",
        "CREATE TABLE " . t('tr_tarieven') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  soort varchar(10) NOT NULL DEFAULT 'uur',
  bedrag_cent int(10) unsigned NOT NULL,
  geldig_vanaf date NOT NULL,
  opmerking varchar(200) NOT NULL DEFAULT '',
  aangemaakt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $c;",
        "CREATE TABLE " . t('tr_uitbetaald') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  maand char(7) NOT NULL,
  trainingen int(10) unsigned NOT NULL DEFAULT 0,
  minuten int(10) unsigned NOT NULL DEFAULT 0,
  bedrag_cent int(10) unsigned NOT NULL DEFAULT 0,
  door_id bigint(20) unsigned NULL,
  op datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (user_id,maand)
) $c;",
        // Was de trainer aanwezig? aanwezig NULL = gevraagd, nog geen antwoord. bron: trainer, melding of bestuur.
        "CREATE TABLE " . t('tr_bevestigingen') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  moment_id bigint(20) unsigned NOT NULL,
  datum date NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  aanwezig tinyint(1) NULL,
  door_id bigint(20) unsigned NULL,
  bron varchar(10) NOT NULL DEFAULT '',
  op datetime NULL,
  gevraagd datetime NULL,
  herinnerd datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniek (moment_id,datum,user_id),
  KEY datum (datum)
) $c;",
        // Aanpassingen door iemand anders (bestuur, secretariaat) aan de planning of de aanwezigheid van een trainer.
        "CREATE TABLE " . t('tr_log') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  moment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  datum date NULL,
  wat varchar(255) NOT NULL,
  door_id bigint(20) unsigned NOT NULL,
  op datetime NOT NULL,
  gelezen tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $c;",
    ];
}
