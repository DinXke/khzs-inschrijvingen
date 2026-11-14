<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; $url = app_url('trainers/beheer/'); ?>
<div class="rij tussen"><h1>Trainingen beheren</h1><a class="knop secundair" href="<?= esc_url(app_url('trainers/planning/')) ?>">📅 Planning</a></div>
<h2>Vaste trainingen</h2>
<div class="kaart">
  <?php foreach ($momenten as $m): $voorbij = $m->tot && $m->tot < vandaag()->format('Y-m-d'); ?>
  <a class="tr-rij" href="<?= esc_url(app_url("trainers/moment/{$m->id}/")) ?>" style="text-decoration:none;color:inherit<?= $voorbij ? ';opacity:.5' : '' ?>">
    <div class="tr-info"><strong><?= e($m->naam) ?></strong>
      <span class="klein"><?= e(ucfirst(TR_DAGEN[$m->weekdag])) ?> <?= e(tr_tijd($m)) ?><?= $m->locatie ? ' · ' . e($m->locatie) : '' ?> · vanaf <?= e(datum($m->van, 'j/m/Y')) ?><?= $m->tot ? ' tot ' . e(datum($m->tot, 'j/m/Y')) : '' ?></span></div>
    <div class="tr-status klein"><?php $tr = $vast[(int) $m->id] ?? []; echo $tr ? '👥 ' . e(implode(', ', array_map(__NAMESPACE__ . '\\tr_voornaam', $tr))) : '<span class="badge afgekeurd">nog geen trainer</span>'; ?> ›</div>
  </a>
  <?php endforeach; ?>
  <?php if (!$momenten): ?><p class="klein">Nog geen trainingen. Voeg hieronder de vaste trainingen van de club toe.</p><?php endif; ?>
</div>
<details class="kaart"<?= ($f || !$momenten) ? ' open' : '' ?>><summary><strong>+ Training toevoegen</strong></summary>
  <form method="post" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><input type="hidden" name="wat" value="moment">
    <?php include __DIR__ . '/_moment_velden.php'; ?><p><button>Toevoegen</button></p></form>
</details>

<h2>Afgelast (vakantie, zwembad dicht…)</h2>
<div class="kaart">
  <?php foreach ($afgelast as $a): ?>
  <div class="tr-rij"><div class="tr-info"><strong><?= $a->moment_id ? e(tr_moment((int) $a->moment_id)->naam ?? '?') : 'Alle trainingen' ?></strong>
    <span class="klein"><?= e(datum($a->van, 'j/m/Y')) ?><?= $a->tot !== $a->van ? ' – ' . e(datum($a->tot, 'j/m/Y')) : '' ?><?= $a->reden ? ' · ' . e($a->reden) : '' ?></span></div>
    <form method="post" action="<?= esc_url($url) ?>" class="tr-status" onsubmit="return confirm('Afgelasting verwijderen?')"><?= nonce_veld() ?><input type="hidden" name="wat" value="afgelast_weg"><input type="hidden" name="id" value="<?= (int) $a->id ?>"><button class="mini secundair">✕</button></form></div>
  <?php endforeach; ?>
  <form method="post" action="<?= esc_url($url) ?>" class="rij" style="align-items:end;margin-top:.6rem"><?= nonce_veld() ?><input type="hidden" name="wat" value="afgelast">
    <div style="min-width:150px;flex:1"><label for="af-m">Welke training</label><select id="af-m" name="moment"><option value="0">Alle trainingen</option><?php foreach ($momenten as $m): ?><option value="<?= (int) $m->id ?>"><?= e($m->naam . ' (' . TR_DAGEN[$m->weekdag] . ')') ?></option><?php endforeach; ?></select></div>
    <div><label for="af-van">Van</label><input id="af-van" type="date" name="van" required></div>
    <div><label for="af-tot">Tot en met</label><input id="af-tot" type="date" name="tot"></div>
    <div style="min-width:150px;flex:1"><label for="af-r">Reden</label><input id="af-r" type="text" name="reden" maxlength="200" placeholder="bv. Kerstvakantie"></div>
    <button class="secundair">Afgelasten</button></form>
  <p class="help">Afgelaste trainingen tellen niet mee voor de uren en verloning.</p>
</div>
