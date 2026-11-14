<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
/** Aanwezigheid van zwemmers per ploeg en per zwemmer. Verwacht $van, $tot, $ploeg, $ploegen en $o (tr_zw_overzicht). */
$q = ['van' => $van, 'tot' => $tot] + ($ploeg ? ['ploeg' => $ploeg] : []);
parse_str((string) wp_parse_url(app_url('trainers/zwemmers/'), PHP_URL_QUERY), $vast);
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

<?php if (!$o['detail']): ?>
<div class="leeg">Nog geen aanwezigheden aangeduid in deze periode. Open een training (bv. via de kalender) en duid de zwemmers aan.</div>
<?php else: ?>
<h2>Per ploeg</h2>
<div class="kaart tabelwrap"><table>
  <thead><tr><th>Ploeg</th><th>Trainingen</th><th>Gemiddeld aanwezig</th></tr></thead>
  <tbody><?php foreach ($o['ploegen'] as $_id => $_p): ?>
    <tr><td><a href="<?= esc_url(app_url('trainers/zwemmers/', ['van' => $van, 'tot' => $tot, 'ploeg' => $_id])) ?>"><?= e($_p['naam']) ?></a>
      <?= $_p['soort'] === 'zwemschool' ? '<span class="badge concept">zwemschool</span>' : '' ?></td>
      <td><?= (int) $_p['trainingen'] ?></td>
      <td><strong><?= e(tr_procent($_p['aanwezig'], $_p['totaal'])) ?></strong> <span class="klein">(<?= (int) $_p['aanwezig'] ?>/<?= (int) $_p['totaal'] ?>)</span></td></tr>
  <?php endforeach; ?></tbody></table></div>

<h2>Per zwemmer</h2>
<div class="kaart tabelwrap"><table>
  <thead><tr><th>Zwemmer</th><th>Ploeg</th><th>Aanwezig</th><th>Afwezig</th><th>%</th></tr></thead>
  <tbody><?php foreach ($o['zwemmers'] as $_z): $_n = $_z['aanwezig'] + $_z['afwezig']; $_pct = $_n ? 100 * $_z['aanwezig'] / $_n : 0; ?>
    <tr><td><?= e($_z['naam']) ?></td><td class="klein"><?= e(implode(', ', $_z['ploegen'])) ?></td>
      <td><?= (int) $_z['aanwezig'] ?></td><td><?= (int) $_z['afwezig'] ?></td>
      <td><strong style="color:var(<?= $_pct >= 75 ? '--ok' : ($_pct >= 50 ? '--wacht' : '--fout') ?>)"><?= e(tr_procent($_z['aanwezig'], $_n)) ?></strong></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<p class="help">Enkel trainingen waarop een trainer de zwemmers aanduidde, tellen mee. Zwemmers en ouders zien deze cijfers niet.</p>
