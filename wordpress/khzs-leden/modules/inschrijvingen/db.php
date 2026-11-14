<?php
/** Module inschrijvingen: tabellen en verwijderen met alles wat eraan hangt (er zijn geen foreign keys). */

namespace KHZS;

defined('ABSPATH') || exit;

add_filter('khzs_tabellen', fn($t) => array_merge($t, ['tijden', 'wedstrijden', 'sessies', 'nummers', 'leeftijdsgroepen', 'limietlijsten',
    'limieten', 'inschrijvingen', 'meldingen', 'herinneringen', 'deelnames', 'bijlagen']));

add_action('khzs_zwemmer_verwijderd', function (int $id) {
    foreach (['tijden', 'inschrijvingen', 'meldingen', 'deelnames'] as $tab) {
        db()->delete(t($tab), ['zwemmer_id' => $id]);
    }
});

// AVG: de gegevens van een zwemmer in 'Mijn gegevens downloaden' (ook als de module uit staat).
add_filter('khzs_mijn_zwemmer', function (array $d, object $z) {
    $tijd = fn($h) => $h === null ? null : (function_exists(__NAMESPACE__ . '\\toon_tijd') ? toon_tijd((int) $h) : (int) $h);
    $slag = fn($s) => defined(__NAMESPACE__ . '\\SLAG') ? mb_strtolower(SLAG[$s] ?? $s) : $s;
    $d['beste_tijden'] = array_map(fn($t) => ['nummer' => "{$t->afstand} m " . $slag($t->slag), 'baan' => $t->baan === 'LCM' ? '50 m' : '25 m',
        'tijd' => $tijd($t->tijd), 'datum' => $t->datum, 'wedstrijd' => $t->wedstrijd, 'bron' => $t->bron],
        db()->get_results(db()->prepare("SELECT * FROM " . t('tijden') . " WHERE zwemmer_id=%d ORDER BY slag, afstand, baan, tijd", $z->id)));
    $d['deelnames'] = array_map(fn($x) => ['wedstrijd' => $x->wnaam, 'keuze' => $x->keuze, 'geweigerd' => (bool) $x->geweigerd, 'reden' => $x->weigerreden],
        db()->get_results(db()->prepare("SELECT d.*, w.naam AS wnaam FROM " . t('deelnames') . " d JOIN " . t('wedstrijden') . " w ON w.id=d.wedstrijd_id WHERE d.zwemmer_id=%d ORDER BY d.id", $z->id)));
    $d['inschrijvingen'] = array_map(fn($i) => ['wedstrijd' => $i->wnaam, 'nummer' => '#' . $i->nummer . ' ' . ($i->naam ?: "{$i->afstand} m " . $slag($i->slag)),
        'inschrijftijd' => $tijd($i->inschrijftijd), 'status' => $i->status, 'opmerking' => $i->opmerking, 'aangemaakt' => $i->aangemaakt],
        db()->get_results(db()->prepare("SELECT i.*, n.nummer, n.naam, n.afstand, n.slag, w.naam AS wnaam FROM " . t('inschrijvingen') . " i
            JOIN " . t('nummers') . " n ON n.id=i.nummer_id JOIN " . t('sessies') . " s ON s.id=n.sessie_id JOIN " . t('wedstrijden') . " w ON w.id=s.wedstrijd_id
            WHERE i.zwemmer_id=%d ORDER BY s.datum, n.volgorde", $z->id)));
    $d['meldingen'] = array_map(fn($m) => ['wedstrijd' => $m->wnaam, 'tekst' => $m->tekst, 'op' => $m->aangemaakt],
        db()->get_results(db()->prepare("SELECT m.*, w.naam AS wnaam FROM " . t('meldingen') . " m LEFT JOIN " . t('wedstrijden') . " w ON w.id=m.wedstrijd_id WHERE m.zwemmer_id=%d ORDER BY m.id", $z->id)));
    return $d;
}, 10, 2);

// AVG: bewaartermijnen (zie includes/avg.php).
add_filter('khzs_opkuis_aantallen', function (array $n) {
    $n['meldingen'] = (int) db()->query(db()->prepare("DELETE FROM " . t('meldingen') . " WHERE aangemaakt<%s", wp_date('Y-m-d H:i:s', time() - MELDINGEN_DAGEN * DAY_IN_SECONDS)));
    $n['herinneringen'] = (int) db()->query(db()->prepare("DELETE FROM " . t('herinneringen') . " WHERE datum<%s", wp_date('Y-m-d', time() - HERINNERINGEN_DAGEN * DAY_IN_SECONDS)));
    return $n;
});

function schema_inschrijvingen(string $c): array
{
    $sql = [];
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
    return $sql;
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
    do_action('khzs_wedstrijd_verwijderd', $id);
}
