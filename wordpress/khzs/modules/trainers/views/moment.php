<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; $url = app_url("trainers/moment/{$m->id}/"); $vandaag = vandaag()->format('Y-m-d'); ?>
<p><a href="<?= esc_url(app_url('trainers/beheer/')) ?>">← Trainingen beheren</a></p>
<h1><?= e($m->naam) ?></h1>
<div class="grid twee">
  <div>
    <h2>Vaste trainers</h2>
    <div class="kaart">
      <?php foreach ($vast as $r): $loopt = !$r->tot || $r->tot >= $vandaag; ?>
      <div class="tr-rij"<?= $loopt ? '' : ' style="opacity:.55"' ?>><div class="tr-info"><strong><?= e(tr_naam((int) $r->user_id)) ?></strong>
        <span class="klein">vanaf <?= e(datum($r->van, 'j/m/Y')) ?><?= $r->tot ? ' tot ' . e(datum($r->tot, 'j/m/Y')) : '' ?></span></div>
        <?php if ($loopt): ?><form method="post" action="<?= esc_url(app_url('trainers/vast/')) ?>" class="tr-status"><?= nonce_veld() ?>
          <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="user" value="<?= (int) $r->user_id ?>"><input type="hidden" name="aan" value="0">
          <input type="hidden" name="terug" value="<?= esc_attr($url) ?>"><input type="date" name="vanaf" value="<?= esc_attr($vandaag) ?>" style="width:auto" title="Stopt vanaf">
          <button class="mini secundair">Stoppen</button></form><?php endif; ?></div>
      <?php endforeach; ?>
      <?php if (!$vast): ?><p class="klein">Nog geen vaste trainer. Trainers duiden dit zelf aan via ‘Trainingen’, of voeg ze hier toe.</p><?php endif; ?>
      <form method="post" action="<?= esc_url(app_url('trainers/vast/')) ?>" class="rij" style="align-items:end;margin-top:.6rem"><?= nonce_veld() ?>
        <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="aan" value="1"><input type="hidden" name="terug" value="<?= esc_attr($url) ?>">
        <div style="flex:1;min-width:150px"><label for="vt-u">Trainer toevoegen</label><select id="vt-u" name="user"><?php foreach ($trainers as $id => $u): ?><option value="<?= (int) $id ?>"><?= e(tr_naam($u)) ?></option><?php endforeach; ?></select></div>
        <div><label for="vt-v">Vanaf</label><input id="vt-v" type="date" name="vanaf" value="<?= esc_attr(max($vandaag, $m->van)) ?>"></div>
        <button class="secundair">Toevoegen</button></form>
    </div>
  </div>
  <div>
    <h2>Gegevens</h2>
    <form method="post" class="kaart" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><input type="hidden" name="wat" value="bewaar">
      <?php include __DIR__ . '/_moment_velden.php'; ?>
      <p class="help">Een ander uur geldt ook voor voorbije trainingen (en dus hun uren). Verandert het rooster, zet dan een einddatum en maak een nieuwe training.</p>
      <p><button>Bewaren</button></p></form>
    <form method="post" action="<?= esc_url($url) ?>" onsubmit="return confirm('Deze training verwijderen? Zijn er al uren op gepresteerd, dan stopt ze enkel vanaf vandaag.')"><?= nonce_veld() ?>
      <input type="hidden" name="wat" value="verwijderen"><button class="mini gevaar">Training verwijderen</button></form>
  </div>
</div>
