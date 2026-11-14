<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
/** Statistiek van de aanwezigheid van zwemmers (trainers en bestuur). Verwacht $van, $tot, $ploeg, $ploegen en $s (tr_zw_stats). */
$q = ['van' => $van, 'tot' => $tot] + ($ploeg ? ['ploeg' => $ploeg] : []);
parse_str((string) wp_parse_url(app_url('trainers/zwemmers/'), PHP_URL_QUERY), $vast);
$t = $s['totaal'];
$zwemmer_url = fn(int $zid) => app_url("aanwezigheid/$zid/", ['van' => $van, 'tot' => $tot]);
?>
<div class="rij tussen"><h1>Aanwezigheid zwemmers</h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('trainers/kalender/')) ?>">🗓️ Kalender</a>
  <a class="knop secundair" href="<?= esc_url(app_url('trainers/zwemmers/export/', $q)) ?>">⬇ Excel</a></div></div>

<form method="get" action="<?= esc_url(app_url('trainers/zwemmers/')) ?>" class="kaart rij" style="align-items:end">
  <?php foreach ($vast as $_k => $_v): ?><input type="hidden" name="<?= esc_attr($_k) ?>" value="<?= esc_attr($_v) ?>"><?php endforeach; ?>
  <div><label for="zw-van">Van</label><input id="zw-van" type="date" name="van" value="<?= esc_attr($van) ?>"></div>
  <div><label for="zw-tot">Tot en met</label><input id="zw-tot" type="date" name="tot" value="<?= esc_attr($tot) ?>"></div>
  <div style="flex:1;min-width:12rem"><label for="zw-ploeg">Ploeg</label>
    <select id="zw-ploeg" name="ploeg"><option value="">Alle ploegen (<?= count($ploegen) ?>)</option>
      <?php foreach ($ploegen as $_p): ?><option value="<?= (int) $_p->id ?>" <?= $ploeg === (int) $_p->id ? 'selected' : '' ?>><?= e($_p->naam) ?><?= $_p->actief ? '' : ' (gearchiveerd)' ?></option><?php endforeach; ?></select></div>
  <button class="secundair">Toon</button>
</form>

<?php if (!$s['detail']): ?>
<div class="leeg">Nog geen aanwezigheden aangeduid in deze periode. Open een training (bv. via de kalender) en duid de zwemmers aan.</div>
<?php else: ?>
<div class="kaart">
  <div class="tr-cijfers">
    <div><span class="tr-getal"><?= e(tr_zw_procent($t)) ?></span><span class="klein">aanwezig</span></div>
    <div><span class="tr-getal"><?= e(tr_zw_procent($t, true)) ?></span><span class="klein">zonder ziek</span></div>
    <div><span class="tr-getal"><?= (int) $t['trainingen'] ?></span><span class="klein">aangeduide trainingen</span></div>
    <div><span class="tr-getal"><?= count($s['zwemmers']) ?></span><span class="klein">zwemmers</span></div>
    <div><span class="tr-getal"><?= $t['trainingen'] ? e(number_format(tr_zw_er($t) / $t['trainingen'], 1, ',', '')) : '—' ?></span><span class="klein">zwemmers per training</span></div>
    <div><span class="tr-getal"><?= (int) $t['laat'] ?></span><span class="klein">keer te laat</span></div>
    <div><span class="tr-getal"><?= (int) $t['vroeger'] ?></span><span class="klein">keer vroeger weg</span></div>
    <div><span class="tr-getal"><?= (int) $t['ziek'] ?></span><span class="klein">keer ziek</span></div>
  </div>
  <?= tr_zw_balk($t) ?>
  <?php include __DIR__ . '/_zw_legende.php'; ?>
</div>

<?php if (count($s['ploegen']) > 1): ?>
<h2>Per ploeg</h2>
<?php $_kop = 'Ploeg'; $_rijen = array_map(fn($id, $p) => ['<a href="' . esc_url(app_url('trainers/zwemmers/', ['van' => $van, 'tot' => $tot, 'ploeg' => $id])) . '">' . e($p['naam'])
    . '</a> <span class="klein">' . (int) $p['trainingen'] . ' tr.</span>' . ($p['soort'] === 'zwemschool' ? ' <span class="badge concept">zwemschool</span>' : ''), $p],
    array_keys($s['ploegen']), $s['ploegen']); include __DIR__ . '/_zw_tabel.php'; ?>
<?php endif; ?>

<h2>Per maand</h2>
<?php $_kop = 'Maand'; $_rijen = array_map(fn($m, $x) => ['<span style="text-transform:capitalize">' . e(tr_maandnaam($m)) . '</span>', $x], array_keys($s['maanden']), $s['maanden']);
include __DIR__ . '/_zw_tabel.php'; ?>

<h2>Per training</h2>
<?php $_kop = 'Training'; $_rijen = array_map(fn($x) => [e($x['naam']) . '<br><span class="klein">' . e($x['wanneer']) . ' · ' . (int) $x['trainingen'] . ' keer</span>', $x], $s['momenten']);
include __DIR__ . '/_zw_tabel.php'; ?>

<h2>Per weekdag</h2>
<?php $_kop = 'Dag'; $_rijen = array_map(fn($d, $x) => [e(ucfirst(TR_DAGEN[$d])), $x], array_keys($s['weekdagen']), $s['weekdagen']);
include __DIR__ . '/_zw_tabel.php'; ?>

<h2>Verdeling van de zwemmers</h2>
<div class="kaart"><?php $_max = max($s['verdeling']) ?: 1; foreach ($s['verdeling'] as $_n => $_aantal): ?>
  <div class="tr-zw-verdeling"><span><?= e($_n) ?></span><span class="tr-zw-staaf"><i style="width:<?= round(100 * $_aantal / $_max) ?>%"></i></span><strong><?= (int) $_aantal ?></strong></div>
<?php endforeach; ?></div>

<h2>Per zwemmer</h2>
<?php $_kop = 'Zwemmer'; $_rijen = array_map(fn($zid, $z) => ['<a href="' . esc_url($zwemmer_url($zid)) . '">' . e($z['naam']) . '</a><br><span class="klein">'
    . e(implode(', ', $z['ploegen'])) . '</span>', $z], array_keys($s['zwemmers']), $s['zwemmers']); include __DIR__ . '/_zw_tabel.php'; ?>
<?php endif; ?>
<p class="help">Enkel trainingen waarop een trainer de zwemmers aanduidde, tellen mee. Te laat en vroeger weg tellen als aanwezig, ziek als afwezig (behalve bij <i>% zonder ziek</i>). Zwemmers en ouders zien enkel hun eigen statistiek, bij de zwemmer.</p>
