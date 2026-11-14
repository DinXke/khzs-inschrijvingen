<?php
/** Module trainers: tabellen. */

namespace KHZS;

defined('ABSPATH') || exit;

add_filter('khzs_tabellen', fn($t) => array_merge($t, ['tr_momenten', 'tr_vast', 'tr_wijzigingen', 'tr_afgelast', 'tr_tarieven', 'tr_uitbetaald']));

add_action('khzs_gebruiker_verwijderd', function (int $uid) {
    // Gepresteerde uren en uitbetalingen blijven (boekhouding); enkel toekomstige planning verdwijnt.
    $vandaag = vandaag()->format('Y-m-d');
    db()->query(db()->prepare("UPDATE " . t('tr_vast') . " SET tot=%s WHERE user_id=%d AND (tot IS NULL OR tot>%s)", $vandaag, $uid, $vandaag));
    db()->query(db()->prepare("DELETE FROM " . t('tr_wijzigingen') . " WHERE user_id=%d AND datum>%s", $uid, $vandaag));
});

/**
 * AVG: wie trainingen gaf of vergoed werd, valt onder de bewaarplicht van de boekhouding. Verwijdert zo iemand het
 * account, dan wordt het afgesloten (includes/avg.php) en blijven naam, uren en vergoedingen tot BOEKHOUDING_JAREN na
 * het boekjaar van de laatste prestatie. Daarna wist de opkuis alles.
 */
add_filter('khzs_bewaarplicht', function ($tot, int $uid) {
    $vandaag = vandaag()->format('Y-m-d');
    $laatst = max([
        (string) db()->get_var(db()->prepare("SELECT MAX(CONCAT(maand, '-01')) FROM " . t('tr_uitbetaald') . " WHERE user_id=%d", $uid)),
        (string) db()->get_var(db()->prepare("SELECT MAX(datum) FROM " . t('tr_wijzigingen') . " WHERE user_id=%d AND datum<=%s", $uid, $vandaag)),
        (string) db()->get_var(db()->prepare("SELECT MAX(LEAST(COALESCE(tot, %s), %s)) FROM " . t('tr_vast') . " WHERE user_id=%d AND van<=%s", $vandaag, $vandaag, $uid, $vandaag)),
        (string) db()->get_var(db()->prepare("SELECT MAX(LEAST(geldig_vanaf, %s)) FROM " . t('tr_tarieven') . " WHERE user_id=%d", $vandaag, $uid)),
    ]);
    if ($laatst === '') {
        return $tot;
    }
    return max((string) $tot, ((int) substr($laatst, 0, 4) + 1 + BOEKHOUDING_JAREN) . '-01-01');
}, 10, 2);

add_action('khzs_bewaartermijn_voorbij', function (int $uid) {
    foreach (['tr_vast', 'tr_wijzigingen', 'tr_tarieven', 'tr_uitbetaald'] as $tab) {
        db()->delete(t($tab), ['user_id' => $uid]);
    }
});

add_filter('khzs_mijn_gegevens', function (array $d, int $uid) {
    $vast = db()->get_results(db()->prepare("SELECT m.naam, m.weekdag, m.start, m.einde, v.van, v.tot FROM " . t('tr_vast') . " v JOIN "
        . t('tr_momenten') . " m ON m.id=v.moment_id WHERE v.user_id=%d ORDER BY v.van", $uid), ARRAY_A);
    $wijzigingen = db()->get_results(db()->prepare("SELECT w.datum, m.naam AS training, w.soort, w.reden FROM " . t('tr_wijzigingen') . " w LEFT JOIN "
        . t('tr_momenten') . " m ON m.id=w.moment_id WHERE w.user_id=%d ORDER BY w.datum", $uid), ARRAY_A);
    $tarieven = db()->get_results(db()->prepare("SELECT soort, bedrag_cent, geldig_vanaf, opmerking FROM " . t('tr_tarieven') . " WHERE user_id=%d ORDER BY geldig_vanaf", $uid), ARRAY_A);
    $uitbetaald = db()->get_results(db()->prepare("SELECT maand, trainingen, minuten, bedrag_cent, op FROM " . t('tr_uitbetaald') . " WHERE user_id=%d ORDER BY maand", $uid), ARRAY_A);
    if ($vast || $wijzigingen || $tarieven || $uitbetaald) {
        $d['trainer'] = ['vaste_trainingen' => $vast, 'afmeldingen_en_vervangingen' => $wijzigingen, 'tarieven' => $tarieven, 'uitbetalingen' => $uitbetaald];
    }
    return $d;
}, 10, 2);

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
    ];
}
