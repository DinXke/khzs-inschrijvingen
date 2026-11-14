<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
/**
 * Statistiek van één zwemmer. Verwacht $z, $van, $tot, $s (tr_zw_stats), $reeksen (tr_zw_reeksen), $ploeg (tellingen van
 * de ploegen ter vergelijking) en $staf (trainer of bestuur, geen eigen zwemmer).
 */
$t = $s['totaal'];
parse_str((string) wp_parse_url(app_url("aanwezigheid/{$z->id}/"), PHP_URL_QUERY), $vast);
?>
<p><?php if ($staf && tr_zw_ploegen()): ?><a href="<?= esc_url(app_url('trainers/zwemmers/', ['van' => $van, 'tot' => $tot])) ?>">← Aanwezigheid zwemmers</a>
  <?php else: ?><a href="<?= esc_url(app_url("zwemmers/{$z->id}/")) ?>">← <?= e($z->voornaam) ?></a><?php endif; ?></p>
<h1>Aanwezigheid <?= e("{$z->voornaam} {$z->naam}") ?></h1>

<form method="get" action="<?= esc_url(app_url("aanwezigheid/{$z->id}/")) ?>" class="kaart rij" style="align-items:end">
  <?php foreach ($vast as $_k => $_v): ?><input type="hidden" name="<?= esc_attr($_k) ?>" value="<?= esc_attr($_v) ?>"><?php endforeach; ?>
  <div><label for="zs-van">Van</label><input id="zs-van" type="date" name="van" value="<?= esc_attr($van) ?>"></div>
  <div><label for="zs-tot">Tot en met</label><input id="zs-tot" type="date" name="tot" value="<?= esc_attr($tot) ?>"></div>
  <button class="secundair">Toon</button>
</form>

<?php if (!$s['detail']): ?>
<div class="leeg">Geen aangeduide trainingen in deze periode.</div>
<?php else: ?>
<div class="kaart">
  <div class="tr-cijfers">
    <div><span class="tr-getal"><?= e(tr_zw_procent($t)) ?></span><span class="klein">aanwezig</span></div>
    <div><span class="tr-getal"><?= e(tr_zw_procent($t, true)) ?></span><span class="klein">zonder ziek</span></div>
    <div><span class="tr-getal"><?= tr_zw_er($t) ?>/<?= (int) $t['totaal'] ?></span><span class="klein">trainingen er</span></div>
    <div><span class="tr-getal"><?= (int) $reeksen['huidig'] ?></span><span class="klein">na elkaar aanwezig (nu)</span></div>
    <div><span class="tr-getal"><?= (int) $reeksen['langst'] ?></span><span class="klein">langste reeks</span></div>
    <div><span class="tr-getal"><?= (int) $t['laat'] ?></span><span class="klein">keer te laat</span></div>
    <div><span class="tr-getal"><?= (int) $t['vroeger'] ?></span><span class="klein">keer vroeger weg</span></div>
    <div><span class="tr-getal"><?= (int) $t['ziek'] ?></span><span class="klein">keer ziek</span></div>
  </div>
  <?= tr_zw_balk($t) ?>
  <?php include __DIR__ . '/_zw_legende.php'; ?>
  <p class="klein" style="margin-bottom:0"><?= $reeksen['laatst'] ? 'Laatst aanwezig: ' . e(datum($reeksen['laatst'], 'l j F Y')) . '.' : 'Nog niet aanwezig in deze periode.' ?>
    <?php foreach ($s['ploegen'] as $_id => $_p): if (!isset($ploeg[$_id])) { continue; } ?>
      Gemiddelde van <?= e($_p['naam']) ?>: <?= e(tr_zw_procent($ploeg[$_id])) ?>.
    <?php endforeach; ?></p>
</div>

<h2>Per maand</h2>
<?php $_kop = 'Maand'; $_rijen = array_map(fn($m, $x) => ['<span style="text-transform:capitalize">' . e(tr_maandnaam($m)) . '</span>', $x], array_keys($s['maanden']), $s['maanden']);
include __DIR__ . '/_zw_tabel.php'; ?>

<h2>Per training</h2>
<?php $_kop = 'Training'; $_rijen = array_map(fn($x) => [e($x['naam']) . '<br><span class="klein">' . e($x['wanneer']) . '</span>', $x], $s['momenten']);
include __DIR__ . '/_zw_tabel.php'; ?>

<h2>Per weekdag</h2>
<?php $_kop = 'Dag'; $_rijen = array_map(fn($d, $x) => [e(ucfirst(TR_DAGEN[$d])), $x], array_keys($s['weekdagen']), $s['weekdagen']);
include __DIR__ . '/_zw_tabel.php'; ?>

<h2>Alle trainingen</h2>
<div class="kaart tr-zw-tijdlijn" aria-label="Elke training als blokje, oudste links">
  <?php foreach ($s['detail'] as $_r): ?><i class="st-<?= $_r->soort ?>" title="<?= esc_attr(datum($_r->datum, 'D j/m') . ' · ' . $_r->mnaam . ': ' . TR_ZW_SOORT[$_r->soort]) ?>"></i><?php endforeach; ?>
</div>
<div class="kaart">
  <?php foreach (array_reverse($s['detail']) as $_r): ?>
  <div class="tr-rij"><span class="tr-datum"><b><?= e(datum($_r->datum, 'D')) ?></b><span><?= e(datum($_r->datum, 'j M')) ?></span></span>
    <div class="tr-info"><strong><?= e((string) $_r->mnaam) ?></strong><span class="klein"><?= $_r->start ? e(substr($_r->start, 0, 5) . '–' . substr($_r->einde, 0, 5)) : '' ?></span></div>
    <div class="tr-status"><span class="tr-zw-label st-<?= $_r->soort ?>"><?= e(TR_ZW_SOORT[$_r->soort]) ?></span></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<p class="help">Een trainer duidt na elke training aan wie er was. Te laat en vroeger weg tellen als aanwezig, ziek als afwezig (behalve bij <i>zonder ziek</i>).<?= $staf ? ' Je ziet enkel de trainingen van de ploegen die je mag bekijken.' : '' ?></p>
